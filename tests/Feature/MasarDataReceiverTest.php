<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\DeliveryStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use App\Models\OrderIntegrationState;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\DeliveryOrderUpdateService;
use App\Services\Integration\MasarDataEnvelope;
use App\Services\Integration\MasarStatusEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The receiving half of Masar's data channel — `order.data.updated`
 * (CONTRACT §13.8, §13.14).
 *
 * Three properties carry the weight here, and each is asserted rather than
 * described.
 *
 * **The correction lands on one order.** `customers` is one row behind every
 * order a person placed and its phone is UNIQUE here, so a correction routed
 * into it would rewrite what every sibling order shows while the announcement
 * named exactly one of them. That is the failure D7 was decided to end, and the
 * tests for it are the shared-customer ones below.
 *
 * **Receiving produces no announcement back.** The local edit path raises an
 * `order.updated` into the outbox, correctly, because what it changes is ours.
 * Routing Masar's own correction through it would send that correction back to
 * its author, arrive as a new `order_version`, be applied, and be announced
 * again, without end — a loop no idempotency can break, because every lap is
 * genuinely a new event.
 *
 * **`base_order_version` is checked, and checked in its place.** It answers a
 * different question from `data_version`: not «is this the newest correction»
 * but «was it built on the picture of this order we hold now» (§13.8.4). A
 * correction can be both the newest and built on something stale.
 */
class MasarDataReceiverTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTS = '/api/v1/integration/events';

    private const TOKENS = '/api/v1/integration/auth/token';

    private MasarIntegrationClient $client;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-05 12:00:00');

        $this->client = MasarIntegrationClient::create([
            'name' => 'Masar',
            'client_id' => 'masar',
            'client_secret_hash' => Hash::make('masar-secret'),
            'status' => 'active',
        ]);

        $this->token = $this->issueToken('masar', 'masar-secret');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------ the door

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $order = $this->assignedOrder();

        $this->postJson(self::EVENTS, $this->envelope($order))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_FAILED');

        $this->assertUncorrected($order);
    }

    public function test_a_disabled_client_is_refused_with_its_own_code(): void
    {
        $order = $this->assignedOrder();

        $this->client->forceFill(['status' => 'disabled'])->save();

        $this->send($order)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_CLIENT');

        $this->assertUncorrected($order);
    }

    // ------------------------------------------------------- the vocabulary

    public function test_an_unknown_event_type_keeps_its_own_code(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order);
        $payload['event_type'] = 'order.something.else';

        $this->sendPayload($payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNKNOWN_EVENT_TYPE');

        $this->assertUncorrected($order);
    }

    /**
     * The two event types are read separately, not as a union (§13.8.1).
     *
     * A status envelope carrying `changed_fields`, or a data envelope carrying
     * `delivery_status`, is a body neither contract describes. Validating
     * against the sum of both would accept either.
     */
    public function test_a_data_event_carrying_status_fields_is_refused(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order);
        $payload['data']['delivery_status'] = DeliveryStatus::Delivered->value;
        $payload['data']['status_version'] = 3;
        unset($payload['data']['changed_fields']);

        $this->sendPayload($payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertUncorrected($order);
        $this->assertSame(DeliveryStatus::WithRepresentative, $order->fresh()->delivery_status);
    }

    public function test_a_status_event_still_takes_the_status_channel(): void
    {
        $order = $this->assignedOrder();

        $instant = Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');

        $this->sendPayload([
            'contract_version' => MasarStatusEnvelope::CONTRACT_VERSION,
            'event_id' => (string) Str::uuid(),
            'event_type' => MasarStatusEnvelope::EVENT_TYPE,
            'occurred_at' => $instant,
            'data' => [
                'order_id' => (string) $order->getKey(),
                'status_version' => 1,
                'delivery_status' => DeliveryStatus::Delivered->value,
                'status_reason' => null,
                'result_occurred_at' => $instant,
            ],
        ])->assertOk()->assertJsonPath('status', 'processed');

        $order->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $order->delivery_status);
        // The two sequences are independent (§13.8.2): a status announcement
        // does not move the data channel's high-water mark.
        $this->assertSame(1, (int) $order->masar_status_version);
        $this->assertSame(0, (int) $order->masar_data_version);
    }

    #[DataProvider('malformedCorrections')]
    public function test_a_malformed_correction_is_refused(callable $mutate): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order);
        $payload = $mutate($payload);

        $this->sendPayload($payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertUncorrected($order);
        $this->assertSame(0, MasarIntegrationEvent::query()->count());
    }

    /** @return array<string, array{0: callable}> */
    public static function malformedCorrections(): array
    {
        $with = static fn (array $fields) => static function (array $payload) use ($fields): array {
            $payload['data']['changed_fields'] = $fields;

            return $payload;
        };

        return [
            'an empty correction' => [$with([])],
            'a path outside the five' => [$with(['order.delivery_cost' => '10.00'])],
            'the withdrawn customer path' => [$with(['customer.phone' => '0919999999'])],
            'a status path' => [$with(['order.delivery_status' => 'delivered'])],
            'a blank name' => [$with(['order.recipient_name' => '   '])],
            'a null name, which is not clearable' => [$with(['order.recipient_name' => null])],
            'a null phone, likewise' => [$with(['order.recipient_phone' => null])],
            'a name past the column width' => [$with(['order.recipient_name' => str_repeat('n', 256)])],
            'a non-numeric amount' => [$with(['order.amount' => 'lots'])],
            'a negative amount' => [$with(['order.amount' => '-1.00'])],
            'three decimals' => [$with(['order.amount' => '10.125'])],
            // Past DECIMAL(12, 2), which is now both systems' ceiling — so it is
            // refused here because neither can represent it, not because this
            // one is the narrower of the two.
            'an amount neither system can hold' => [$with(['order.amount' => '10000000000.00'])],
            'a payer outside the two' => [$with(['order.delivery_payer' => 'company'])],
            'a data version of zero' => [static function (array $payload): array {
                $payload['data']['data_version'] = 0;

                return $payload;
            }],
            'a missing base_order_version key' => [static function (array $payload): array {
                unset($payload['data']['base_order_version']);

                return $payload;
            }],
        ];
    }

    // ------------------------------------------------------- the unknown order

    public function test_an_unknown_order_is_a_404_with_its_own_code(): void
    {
        $this->sendPayload($this->envelope(null, externalOrderId: '999999'))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

        // Recorded, because §3.21.7 makes this a state a person has to repair
        // and the evidence of it is what they will look for.
        $event = MasarIntegrationEvent::query()->sole();
        $this->assertSame('rejected', $event->result);
        $this->assertSame('ORDER_NOT_FOUND', $event->error_code);
        $this->assertSame(1, (int) $event->data_version);
    }

    public function test_a_non_numeric_order_id_is_answered_as_a_missing_order(): void
    {
        $this->sendPayload($this->envelope(null, externalOrderId: 'not-an-id'))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
    }

    // ---------------------------------------------------------- applying it

    public function test_every_correctable_path_is_applied(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, [
            'order.recipient_name' => 'محمد',
            'order.recipient_phone' => '0919999999',
            'order.recipient_alternate_phone' => '0921111111',
            'order.amount' => '275.50',
            'order.delivery_payer' => 'recipient',
        ])->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('applied_data_version', 1)
            ->assertJsonPath('order_id', (string) $order->getKey());

        $order->refresh();
        $this->assertSame('محمد', $order->recipient_name);
        $this->assertSame('0919999999', $order->recipient_phone);
        $this->assertSame('0921111111', $order->recipient_alternate_phone);
        $this->assertSame('275.50', $order->value);
        $this->assertSame('recipient', $order->delivery_payer);
        $this->assertSame(1, (int) $order->masar_data_version);
    }

    public function test_a_partial_correction_leaves_the_other_paths_alone(): void
    {
        $order = $this->assignedOrder();
        $order->forceFill([
            'recipient_name' => 'أحمد',
            'recipient_phone' => '0910000001',
            'recipient_alternate_phone' => '0920000002',
        ])->save();

        $this->send($order, ['order.recipient_phone' => '0919999999'])->assertOk();

        $order->refresh();
        $this->assertSame('أحمد', $order->recipient_name);
        $this->assertSame('0919999999', $order->recipient_phone);
        $this->assertSame('0920000002', $order->recipient_alternate_phone);
    }

    /**
     * The one clearable path (§13.14.1): null means «this recipient has no
     * second number», which is a correction like any other.
     */
    public function test_the_alternate_phone_can_be_cleared(): void
    {
        $order = $this->assignedOrder();
        $order->forceFill(['recipient_alternate_phone' => '0920000002'])->save();

        $this->send($order, ['order.recipient_alternate_phone' => null])->assertOk();

        $this->assertNull($order->fresh()->recipient_alternate_phone);
    }

    // ------------------------------------------------------- the amount domain

    /**
     * The whole DECIMAL(12, 2) range survives the round trip exactly (§13.8.3).
     *
     * Asserted as strings on the way out as well as in. The column is decimal
     * and so is the cast, and a value that went through a binary float on either
     * leg would come back a few thousandths short at the top of the range —
     * quietly, and only for the largest orders.
     */
    #[DataProvider('amountsWithinTheDomain')]
    public function test_an_amount_anywhere_in_the_shared_domain_is_stored_exactly(string $amount): void
    {
        $order = $this->assignedOrder();

        $this->send($order, ['order.amount' => $amount])
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame($amount, $order->fresh()->value);

        // And from the database itself, not only through the model's cast.
        $this->assertSame($amount, DB::table('delivery_orders')->where('id', $order->getKey())->value('value'));
    }

    /** @return array<string, array{0: string}> */
    public static function amountsWithinTheDomain(): array
    {
        return [
            'the smallest representable amount' => ['0.01'],
            'the old DECIMAL(10, 2) ceiling' => ['99999999.99'],
            'one past the old ceiling, which used to be terminally refused' => ['100000000.00'],
            'the DECIMAL(12, 2) ceiling' => ['9999999999.99'],
        ];
    }

    /**
     * And the mathematical boundary is where it is refused, not before it.
     *
     * DECIMAL(12, 2) holds ten integer digits: the largest value is
     * 10^10 - 0.01 = 9999999999.99, and 10000000000.00 is the first that cannot
     * be represented.
     */
    public function test_the_first_unrepresentable_amount_is_refused(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, ['order.amount' => '10000000000.00'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame('20.00', $order->fresh()->value);
    }

    /**
     * The value Masar's own boundary test sends, applied end to end.
     *
     * The two systems now name one domain, so the largest amount a courier can
     * record is an amount this system stores. Before the widening this exact
     * value committed in Masar and was refused here for ever, with §13.7 point 8
     * keeping the courier from ever learning of it.
     */
    public function test_masars_largest_editable_amount_is_applicable_here(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, ['order.amount' => '9999999999.99'])->assertOk();

        $this->assertSame('9999999999.99', $order->fresh()->value);
        $this->assertSame(1, (int) $order->fresh()->masar_data_version);
    }

    // ------------------------------------------------------ D7 — the isolation

    /**
     * **The property this whole channel is shaped around** (§13.14, D7).
     *
     * One customer row, two orders. Masar's courier corrects the recipient on
     * one. The other must be untouched — and the shared profile too, which here
     * is not merely a preference: `customers.phone` is UNIQUE, so a channel that
     * wrote the profile would fail outright the second time two orders of
     * different customers were corrected to the same number.
     */
    public function test_a_correction_reaches_one_order_and_neither_its_sibling_nor_the_shared_customer(): void
    {
        $customer = Customer::create(['name' => 'أحمد', 'phone' => '0910000001']);

        $target = $this->assignedOrder($customer);
        $sibling = $this->assignedOrder($customer);

        $before = (array) DB::table('customers')->where('id', $customer->id)->first();

        $this->send($target, [
            'order.recipient_name' => 'محمد',
            'order.recipient_phone' => '0919999999',
        ])->assertOk();

        $target->refresh();
        $this->assertSame('محمد', $target->recipient_name);
        $this->assertSame('0919999999', $target->recipient_phone);

        // The sibling keeps its own snapshot and its own version.
        $sibling->refresh();
        $this->assertSame('أحمد', $sibling->recipient_name);
        $this->assertSame('0910000001', $sibling->recipient_phone);
        $this->assertSame(0, (int) $sibling->masar_data_version);

        // And the shared profile has not moved at all.
        $this->assertEquals($before, (array) DB::table('customers')->where('id', $customer->id)->first());
    }

    /**
     * And the two orders can hold the same number without colliding.
     *
     * The `customers.phone` UNIQUE index is the concrete reason the profile
     * cannot be the place a per-order correction lands: two corrections to one
     * number are ordinary — a household, a shop, one person with two orders —
     * and on the profile the second would be a database error answered as a
     * server fault to Masar.
     */
    public function test_two_orders_may_be_corrected_to_the_same_number(): void
    {
        $first = $this->assignedOrder();
        $second = $this->assignedOrder();

        $this->send($first, ['order.recipient_phone' => '0919999999'])->assertOk();
        $this->send($second, ['order.recipient_phone' => '0919999999'])->assertOk();

        $this->assertSame('0919999999', $first->fresh()->recipient_phone);
        $this->assertSame('0919999999', $second->fresh()->recipient_phone);
    }

    // -------------------------------------------------------- the no-echo barrier

    /**
     * Receiving a correction produces no announcement back (§3.21.10, §13.8).
     *
     * Asserted on the outbox itself and on the order's own outbound version,
     * because either moving would be the first lap of a loop nothing downstream
     * could stop.
     */
    public function test_applying_a_correction_enqueues_nothing_and_does_not_advance_our_own_version(): void
    {
        $order = $this->assignedOrder();

        $outboxBefore = IntegrationOutbox::query()->count();
        $versionBefore = (int) OrderIntegrationState::query()
            ->where('delivery_order_id', $order->getKey())->value('current_version');

        $this->send($order, [
            'order.recipient_name' => 'محمد',
            'order.recipient_phone' => '0919999999',
            'order.amount' => '99.00',
        ])->assertOk();

        $this->assertSame($outboxBefore, IntegrationOutbox::query()->count());
        $this->assertSame($versionBefore, (int) OrderIntegrationState::query()
            ->where('delivery_order_id', $order->getKey())->value('current_version'));
    }

    /**
     * And the corrected values are what this system announces from then on.
     *
     * The other half of the barrier. Not echoing the correction back is
     * necessary; continuing to announce the *old* recipient on the next ordinary
     * `order.updated` would be the same loop with an extra step, because Masar
     * would apply the profile's name back over its own courier's correction.
     */
    public function test_a_later_outbound_event_carries_the_corrected_recipient(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, [
            'order.recipient_name' => 'محمد',
            'order.recipient_phone' => '0919999999',
        ])->assertOk();

        app(DeliveryOrderUpdateService::class)->update($order->fresh(), ['value' => '55.00']);

        $latest = IntegrationOutbox::query()->latest('id')->first();

        $this->assertSame('order.updated', $latest->event_type->value);
        $this->assertSame('محمد', $latest->payload['data']['current_snapshot']['customer']['name']);
        $this->assertSame('0919999999', $latest->payload['data']['current_snapshot']['customer']['phone']);
        // The identity is still the shared customer's: that is what
        // `external_customer_id` means, and it did not change.
        $this->assertSame(
            (string) $order->customer_id,
            $latest->payload['data']['current_snapshot']['customer']['external_customer_id'],
        );
    }

    // ---------------------------------------------------------- idempotency

    public function test_a_retry_of_the_same_event_replays_its_answer(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order, ['order.recipient_name' => 'محمد']);

        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'processed');
        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'already_processed');

        $this->assertSame(1, MasarIntegrationEvent::query()->count());
        $this->assertSame(1, (int) $order->fresh()->masar_data_version);
    }

    public function test_one_event_id_with_a_different_payload_is_a_conflict(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order, ['order.recipient_name' => 'محمد']);
        $this->sendPayload($payload)->assertOk();

        $payload['data']['changed_fields'] = ['order.recipient_name' => 'علي'];

        $this->sendPayload($payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $this->assertSame('محمد', $order->fresh()->recipient_name);
        $this->assertSame(1, MasarIntegrationEvent::query()->count());
    }

    // ------------------------------------------------------------ ordering

    public function test_an_older_correction_is_ignored_as_stale(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, ['order.recipient_name' => 'محمد'], version: 5)->assertOk();

        $this->send($order, ['order.recipient_name' => 'قديم'], version: 3)
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale')
            ->assertJsonPath('applied_data_version', 5);

        $order->refresh();
        $this->assertSame('محمد', $order->recipient_name);
        $this->assertSame(5, (int) $order->masar_data_version);
    }

    public function test_a_stale_event_replays_as_stale_and_not_as_applied(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, ['order.recipient_name' => 'محمد'], version: 5)->assertOk();

        $stale = $this->envelope($order, ['order.recipient_name' => 'قديم'], version: 3);

        $this->sendPayload($stale)->assertOk()->assertJsonPath('status', 'ignored_stale');
        $this->sendPayload($stale)->assertOk()->assertJsonPath('status', 'ignored_stale');
    }

    /**
     * Two different events claiming one version. Masar mints one version per
     * accepted correction, so one of them is wrong and the receiver cannot tell
     * which — it applies neither.
     */
    public function test_a_second_event_at_the_applied_version_is_a_conflict(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, ['order.recipient_name' => 'محمد'], version: 2)->assertOk();

        $this->send($order, ['order.recipient_name' => 'آخر'], version: 2)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $this->assertSame('محمد', $order->fresh()->recipient_name);
    }

    /**
     * Identity is decided before ordering, and the order is load-bearing.
     *
     * A legitimate retry of an event that *was* applied must answer
     * `already_processed`. Checking the version first would answer it
     * `ignored_stale` — harmless to the data, but it would tell the sender its
     * correction never landed when it had.
     */
    public function test_a_retry_of_the_newest_event_answers_already_processed_not_stale(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order, ['order.recipient_name' => 'محمد'], version: 4);

        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'processed');
        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'already_processed');
    }

    // -------------------------------------------------- base_order_version

    /**
     * §13.8.4 — the precondition, and the question `data_version` cannot answer.
     *
     * The correction here is the newest Masar has sent, and it is still refused:
     * it was built on our version 1, and a local edit has since moved us to 2.
     * The courier chose a name while looking at an order this system has already
     * changed.
     */
    public function test_a_correction_built_on_a_superseded_order_version_is_refused(): void
    {
        $order = $this->assignedOrder();

        $this->assertSame(1, $this->outboundVersion($order));

        // An ordinary local edit, which raises `order.updated` and advances our
        // own sequence.
        app(DeliveryOrderUpdateService::class)->update($order, ['value' => '77.00']);
        $this->assertSame(2, $this->outboundVersion($order));

        $this->send($order, ['order.recipient_name' => 'محمد'], base: 1)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DATA_BASE_VERSION_CONFLICT');

        $order->refresh();
        $this->assertSame($order->customer->name, $order->recipient_name, 'The refused correction was applied anyway.');
        $this->assertSame(0, (int) $order->masar_data_version);

        // Recorded with both numbers, because «what did they think our version
        // was» is the whole question this refusal raises.
        $event = MasarIntegrationEvent::query()->sole();
        $this->assertSame('rejected', $event->result);
        $this->assertSame('DATA_BASE_VERSION_CONFLICT', $event->error_code);
        $this->assertSame(1, (int) $event->data_version);
        $this->assertSame(1, (int) $event->base_order_version);
    }

    public function test_a_correction_built_on_the_current_order_version_is_applied(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, ['order.recipient_name' => 'محمد'], base: $this->outboundVersion($order))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame('محمد', $order->fresh()->recipient_name);
    }

    /**
     * A null base is not a failure (§13.8.6).
     *
     * Masar sends it for an order it holds without an integration mapping: it
     * has no picture of ours to have built on, so there is nothing to disagree
     * with, and refusing would strand a correction that is perfectly applicable.
     */
    public function test_a_correction_with_no_base_version_is_applied(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, ['order.recipient_name' => 'محمد'], base: null)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame('محمد', $order->fresh()->recipient_name);
        $this->assertNull(MasarIntegrationEvent::query()->sole()->base_order_version);
    }

    /**
     * A base conflict is settled, not reconsidered on retry.
     *
     * This matters more here than anywhere else on the channel: the state a base
     * conflict depends on *does* change over time, so a reconsidered retry could
     * apply a correction the first attempt correctly refused — and it would do so
     * looking like an ordinary success.
     */
    public function test_a_refused_base_conflict_replays_its_refusal(): void
    {
        $order = $this->assignedOrder();

        app(DeliveryOrderUpdateService::class)->update($order, ['value' => '77.00']);

        $payload = $this->envelope($order, ['order.recipient_name' => 'محمد'], base: 1);

        $this->sendPayload($payload)->assertStatus(409)->assertJsonPath('error.code', 'DATA_BASE_VERSION_CONFLICT');

        // Even once our version happens to be what the correction claimed again
        // — which it never will be in production, but the guard must not depend
        // on that.
        OrderIntegrationState::query()->where('delivery_order_id', $order->getKey())
            ->update(['current_version' => 1]);

        $this->sendPayload($payload)->assertStatus(409)->assertJsonPath('error.code', 'DATA_BASE_VERSION_CONFLICT');

        $order->refresh();
        $this->assertSame($order->customer->name, $order->recipient_name);
    }

    /**
     * The precedence, at the one point where two guards could both fire.
     *
     * A stale correction that was also built on a superseded version is answered
     * `ignored_stale`, not refused: something newer is already in place, which is
     * the ordinary convergence this channel exists to produce, and reporting a
     * fault for it would make every retry-after-correction look like a problem.
     */
    public function test_staleness_is_decided_before_the_base_version(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, ['order.recipient_name' => 'محمد'], version: 5, base: 1)->assertOk();

        app(DeliveryOrderUpdateService::class)->update($order->fresh(), ['value' => '77.00']);

        $this->send($order, ['order.recipient_name' => 'قديم'], version: 3, base: 1)
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale');

        $this->assertSame('محمد', $order->fresh()->recipient_name);
    }

    // ------------------------------------------------------------- the log

    public function test_an_applied_correction_is_recorded_with_both_versions(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, ['order.recipient_name' => 'محمد'], version: 3, base: 1)->assertOk();

        $event = MasarIntegrationEvent::query()->sole();

        $this->assertSame(MasarDataEnvelope::EVENT_TYPE, $event->event_type);
        $this->assertSame('processed', $event->result);
        $this->assertSame((int) $order->getKey(), (int) $event->delivery_order_id);
        $this->assertSame(3, (int) $event->data_version);
        $this->assertSame(1, (int) $event->base_order_version);
        // The status channel said nothing, and a zero here would claim it had.
        $this->assertNull($event->status_version);
    }

    // ------------------------------------------------------------- machinery

    private function assertUncorrected(DeliveryOrder $order): void
    {
        $order->refresh();

        $this->assertSame(0, (int) $order->masar_data_version);
        $this->assertNull($order->delivery_payer);
    }

    private function outboundVersion(DeliveryOrder $order): int
    {
        return (int) (OrderIntegrationState::query()
            ->where('delivery_order_id', $order->getKey())
            ->value('current_version') ?? 0);
    }

    private function issueToken(string $clientId, string $secret): string
    {
        $response = $this->postJson(self::TOKENS, ['client_id' => $clientId, 'client_secret' => $secret]);

        $response->assertOk()->assertJsonPath('token_type', 'Bearer');

        return $response->json('access_token');
    }

    /** @param array<string, mixed>|null $changedFields */
    private function send(
        DeliveryOrder $order,
        ?array $changedFields = null,
        int $version = 1,
        ?int $base = 1,
    ): TestResponse {
        return $this->sendPayload($this->envelope($order, $changedFields, version: $version, base: $base));
    }

    /** @param array<string, mixed> $payload */
    private function sendPayload(array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson(self::EVENTS, $payload);
    }

    /**
     * @param  array<string, mixed>|null  $changedFields
     * @return array<string, mixed>
     */
    private function envelope(
        ?DeliveryOrder $order = null,
        ?array $changedFields = null,
        ?string $externalOrderId = null,
        int $version = 1,
        ?int $base = 1,
    ): array {
        return [
            'contract_version' => MasarDataEnvelope::CONTRACT_VERSION,
            'event_id' => (string) Str::uuid(),
            'event_type' => MasarDataEnvelope::EVENT_TYPE,
            'occurred_at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'data' => [
                'order_id' => $externalOrderId ?? (string) $order?->getKey(),
                'data_version' => $version,
                'base_order_version' => $base,
                'changed_fields' => $changedFields ?? ['order.recipient_name' => 'محمد'],
            ],
        ];
    }

    private function assignedOrder(?Customer $customer = null): DeliveryOrder
    {
        $customer ??= Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('+218', true),
        ]);

        $representative = Representative::create([
            'name' => 'Representative '.uniqid(),
            'phone' => '+218920000007',
            'is_active' => true,
        ]);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            // Seeded as the migration's backfill seeds every existing row, so
            // the fixture starts where production starts.
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);

        return app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($order, $representative);
    }
}
