<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderResult;
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
use App\Services\Integration\MasarStatusEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The receiving half of the Masar → Mini Delivery channel (CONTRACT §3.21).
 *
 * The cases here follow the contract's own divisions: authentication, the closed
 * vocabulary and its reason pairing, the unknown order, idempotency in both of
 * its forms — and, separately and at length, the two properties that make this
 * channel safe to have at all: that receiving an announcement produces no
 * announcement back, and that the legacy lifecycle columns are not touched by a
 * system that does not own them.
 */
class MasarStatusReceiverTest extends TestCase
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

    // ---------------------------------------------------------------- auth

    public function test_the_event_endpoint_refuses_an_unauthenticated_caller(): void
    {
        $order = $this->assignedOrder();

        $this->postJson(self::EVENTS, $this->envelope($order))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_FAILED');

        $this->assertSame(
            DeliveryStatus::WithRepresentative,
            $order->fresh()->delivery_status,
        );
    }

    public function test_a_disabled_client_is_refused_with_its_own_code(): void
    {
        $order = $this->assignedOrder();

        // The token was valid when issued; disabling has to take effect at
        // once, so the check is on the request and not only at issue time.
        $this->client->forceFill(['status' => 'disabled'])->save();

        $this->send($order)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_CLIENT');

        $this->assertSame(
            DeliveryStatus::WithRepresentative,
            $order->fresh()->delivery_status,
        );
    }

    public function test_an_expired_token_is_refused(): void
    {
        $order = $this->assignedOrder();

        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00')->addMinutes(61));

        $this->send($order)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_FAILED');
    }

    public function test_wrong_credentials_do_not_yield_a_token(): void
    {
        $this->postJson(self::TOKENS, ['client_id' => 'masar', 'client_secret' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_FAILED');

        // An unknown client id is answered identically, so the endpoint cannot
        // be used to discover which client ids exist.
        $this->postJson(self::TOKENS, ['client_id' => 'nobody', 'client_secret' => 'masar-secret'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_FAILED');
    }

    // ------------------------------------------------- the three statuses

    public function test_a_delivered_announcement_is_applied_with_no_reason(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::Delivered, null)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('order_id', (string) $order->getKey());

        $order->refresh();

        $this->assertSame(DeliveryStatus::Delivered, $order->delivery_status);
        $this->assertNull($order->status_reason);
    }

    public function test_a_postponed_announcement_is_applied_with_its_reason(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::Postponed, 'customer_absent')->assertOk();

        $order->refresh();

        $this->assertSame(DeliveryStatus::Postponed, $order->delivery_status);
        $this->assertSame('customer_absent', $order->status_reason);
    }

    public function test_a_returned_announcement_is_applied_with_its_reason(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::Returned, 'customer_refused')->assertOk();

        $order->refresh();

        $this->assertSame(DeliveryStatus::Returned, $order->delivery_status);
        $this->assertSame('customer_refused', $order->status_reason);
    }

    // ------------------------------------------------- the pairing rules

    public function test_delivered_with_a_reason_is_refused(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::Delivered, 'customer_absent')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertUntouched($order);
    }

    public function test_postponed_with_a_return_reason_is_refused(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::Postponed, 'customer_refused')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertUntouched($order);
    }

    public function test_returned_with_a_postponement_reason_is_refused(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::Returned, 'customer_absent')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertUntouched($order);
    }

    public function test_a_reason_outside_the_closed_list_is_refused(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::Postponed, 'because_i_said_so')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertUntouched($order);
    }

    public function test_postponed_without_a_reason_is_refused(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::Postponed, null)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertUntouched($order);
    }

    /**
     * The vocabulary is exactly four values.
     *
     * `with_rep` used to be refused here — v4.8 kept the clear off the wire
     * altogether. v4.9 admits it, and its acceptance is asserted under "clear"
     * below; what remains to prove is that admitting one more value did not
     * open the field to anything else.
     */
    public function test_a_status_outside_the_four_is_refused(): void
    {
        $order = $this->assignedOrder();

        foreach (['deferred', 'cancelled', 'completed', ''] as $status) {
            $payload = $this->envelope($order);
            $payload['data']['delivery_status'] = $status;

            $this->sendPayload($payload)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }

        $this->assertUntouched($order);
    }

    public function test_an_unknown_event_type_has_its_own_code(): void
    {
        $order = $this->assignedOrder();
        $payload = $this->envelope($order);
        $payload['event_type'] = 'order.updated';

        $this->sendPayload($payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNKNOWN_EVENT_TYPE');

        $this->assertUntouched($order);
    }

    // ------------------------------------------------- the unknown order

    public function test_an_unknown_external_order_is_refused_and_recorded(): void
    {
        $payload = $this->envelope(null, DeliveryStatus::Delivered, null, '9999999');

        $this->sendPayload($payload)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

        // §3.21.4 calls this a state a person has to repair, so the evidence of
        // it survives the refusal that changed nothing else.
        $event = MasarIntegrationEvent::query()->where('event_id', $payload['event_id'])->firstOrFail();

        $this->assertSame('rejected', $event->result);
        $this->assertSame('ORDER_NOT_FOUND', $event->error_code);
        $this->assertNull($event->delivery_order_id);
    }

    // -------------------------------------------------------- idempotency

    public function test_the_same_event_replayed_answers_already_processed_and_applies_nothing_twice(): void
    {
        $order = $this->assignedOrder();
        $payload = $this->envelope($order, DeliveryStatus::Postponed, 'customer_absent');

        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'processed');

        // A second writer moves the status underneath, standing in for anything
        // that could have happened between the original and the retry. If the
        // replay applied the event again this would be overwritten.
        $order->forceFill(['delivery_status' => DeliveryStatus::Returned->value, 'status_reason' => 'order_issue'])->save();

        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'already_processed');

        $order->refresh();

        $this->assertSame(DeliveryStatus::Returned, $order->delivery_status);
        $this->assertSame('order_issue', $order->status_reason);
        $this->assertSame(1, MasarIntegrationEvent::query()->where('event_id', $payload['event_id'])->count());
    }

    public function test_the_same_event_id_with_a_different_payload_is_a_conflict(): void
    {
        $order = $this->assignedOrder();
        $first = $this->envelope($order, DeliveryStatus::Postponed, 'customer_absent');

        $this->sendPayload($first)->assertOk();

        $second = $first;
        $second['data']['delivery_status'] = DeliveryStatus::Delivered->value;
        $second['data']['status_reason'] = null;

        $this->sendPayload($second)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $order->refresh();

        $this->assertSame(DeliveryStatus::Postponed, $order->delivery_status);
        $this->assertSame('customer_absent', $order->status_reason);
    }

    public function test_a_rejected_event_replays_its_rejection_rather_than_being_reconsidered(): void
    {
        $payload = $this->envelope(null, DeliveryStatus::Delivered, null, '9999999');

        $this->sendPayload($payload)->assertStatus(404);
        $this->sendPayload($payload)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

        $this->assertSame(1, MasarIntegrationEvent::query()->where('event_id', $payload['event_id'])->count());
    }

    // ---------------------------------------------------- loop prevention

    public function test_receiving_an_announcement_produces_no_outbound_event_back_to_masar(): void
    {
        $order = $this->assignedOrder();

        // The assignment itself legitimately produced one; everything after
        // this point must not.
        $before = IntegrationOutbox::query()->count();
        $versionBefore = OrderIntegrationState::query()
            ->where('delivery_order_id', $order->getKey())->value('current_version');

        $this->send($order, DeliveryStatus::Delivered, null, 1)->assertOk();
        $this->send($order, DeliveryStatus::Postponed, 'customer_absent', 2)->assertOk();
        // §3.21.3 — the clear travels on this channel too, and it echoes no
        // more than the others do.
        $this->send($order, DeliveryStatus::WithRepresentative, null, 3)->assertOk();

        $this->assertSame($before, IntegrationOutbox::query()->count());

        // §3.21.10 — and the version matters as much as the row count: a bumped
        // counter with no row would still make the next legitimate event carry
        // a number Masar has never seen.
        $this->assertSame(
            $versionBefore,
            OrderIntegrationState::query()->where('delivery_order_id', $order->getKey())->value('current_version'),
        );
    }

    public function test_the_legacy_lifecycle_columns_are_left_alone(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::Returned, 'customer_refused')->assertOk();

        $order->refresh();

        // §3.21.10 — `result` is Mini Delivery's own binary lifecycle outcome
        // and cannot express the difference between a postponement and a
        // return, so Masar does not get to derive it.
        $this->assertNull($order->result);
        $this->assertSame(DeliveryOrderStatus::Assigned, $order->status);
        $this->assertNull($order->completed_at);
        $this->assertNull($order->cancelled_at);
        $this->assertNull($order->location_completed_at);
    }

    public function test_a_completed_order_keeps_its_own_result_when_masar_announces_one(): void
    {
        $order = $this->assignedOrder();

        app(DeliveryOrderLifecycleService::class)
            ->complete($order, DeliveryOrderResult::Delivered);

        $this->send($order->fresh(), DeliveryStatus::Returned, 'customer_refused')->assertOk();

        $order->refresh();

        $this->assertSame(DeliveryOrderResult::Delivered, $order->result);
        $this->assertSame(DeliveryOrderStatus::Completed, $order->status);
        $this->assertSame(DeliveryStatus::Returned, $order->delivery_status);
    }

    // ------------------------------------------------------------ ordering

    public function test_a_newer_version_is_applied_and_becomes_the_high_water_mark(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::Postponed, 'customer_absent', 1)->assertOk();

        $order->refresh();
        $this->assertSame(DeliveryStatus::Postponed, $order->delivery_status);
        $this->assertSame(1, $order->masar_status_version);

        $this->send($order, DeliveryStatus::Returned, 'customer_refused', 2)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $order->refresh();
        $this->assertSame(DeliveryStatus::Returned, $order->delivery_status);
        $this->assertSame('customer_refused', $order->status_reason);
        $this->assertSame(2, $order->masar_status_version);
    }

    /**
     * The defect this whole mechanism exists for.
     *
     * A postponement whose send failed, a correction to `returned` that
     * succeeded, and then the retry of the postponement arriving late. Before
     * §3.21.11 the retry was a perfectly valid new event and was applied over
     * the correction, silently reverting this system to a state Masar had
     * already withdrawn.
     */
    public function test_a_stale_version_arriving_late_cannot_revert_the_state(): void
    {
        $order = $this->assignedOrder();

        // The event Masar could not deliver at the time.
        $stale = $this->envelope($order, DeliveryStatus::Postponed, 'customer_absent', null, 1);

        // The correction, which got through first.
        $this->send($order, DeliveryStatus::Returned, 'customer_refused', 2)->assertOk();

        // Now the retry of the older one.
        $this->sendPayload($stale)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'ignored_stale')
            ->assertJsonPath('applied_status_version', 2);

        $order->refresh();

        $this->assertSame(DeliveryStatus::Returned, $order->delivery_status);
        $this->assertSame('customer_refused', $order->status_reason);
        $this->assertSame(2, $order->masar_status_version);
    }

    public function test_a_stale_event_is_logged_as_a_successful_outcome_not_an_error(): void
    {
        $order = $this->assignedOrder();

        $stale = $this->envelope($order, DeliveryStatus::Postponed, 'customer_absent', null, 1);

        $this->send($order, DeliveryStatus::Returned, 'customer_refused', 2)->assertOk();
        $this->sendPayload($stale)->assertOk();

        $event = MasarIntegrationEvent::query()->where('event_id', $stale['event_id'])->sole();

        // §3.21.11 — convergence, not a fault. Filing it as an error would make
        // an ordinary correction-then-retry look like a failure every time.
        $this->assertSame('ignored_stale', $event->result);
        $this->assertNull($event->error_code);
        $this->assertSame(200, $event->http_status);
        $this->assertSame(1, $event->status_version);
        $this->assertSame($order->getKey(), $event->delivery_order_id);
    }

    public function test_a_stale_event_replayed_answers_ignored_stale_again(): void
    {
        $order = $this->assignedOrder();

        $stale = $this->envelope($order, DeliveryStatus::Postponed, 'customer_absent', null, 1);

        $this->send($order, DeliveryStatus::Returned, 'customer_refused', 2)->assertOk();
        $this->sendPayload($stale)->assertOk()->assertJsonPath('status', 'ignored_stale');

        // Not `already_processed`: that would tell Masar the announcement took
        // effect, when the point is that a newer one had.
        $this->sendPayload($stale)
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale')
            ->assertJsonPath('applied_status_version', 2);

        $this->assertSame(1, MasarIntegrationEvent::query()->where('event_id', $stale['event_id'])->count());
    }

    public function test_two_different_events_at_the_same_version_are_a_conflict(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::Postponed, 'customer_absent', 1)->assertOk();

        // A different event id claiming a version already applied. Masar mints
        // one version per accepted change, so one of the two is wrong and the
        // receiver cannot tell which — it applies neither.
        $this->send($order, DeliveryStatus::Delivered, null, 1)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $order->refresh();

        $this->assertSame(DeliveryStatus::Postponed, $order->delivery_status);
        $this->assertSame(1, $order->masar_status_version);
    }

    public function test_a_missing_status_version_is_refused(): void
    {
        $order = $this->assignedOrder();
        $payload = $this->envelope($order);
        unset($payload['data']['status_version']);

        $this->sendPayload($payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertUntouched($order);
    }

    public function test_a_version_below_one_is_refused(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, DeliveryStatus::Delivered, null, null, 0))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertUntouched($order);
    }

    public function test_a_version_beyond_the_column_is_refused_rather_than_overflowing(): void
    {
        $order = $this->assignedOrder();
        $payload = $this->envelope($order);
        // Past BIGINT SIGNED, and past what PHP can hold as an integer at all.
        $payload['data']['status_version'] = 92233720368547758079;

        $this->sendPayload($payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertUntouched($order);
        $this->assertSame(0, $order->fresh()->masar_status_version);
    }

    // --------------------------------------------------------------- clear

    public function test_a_cleared_result_is_applied_as_with_rep(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::Postponed, 'customer_absent', 1)->assertOk();

        $this->send($order, DeliveryStatus::WithRepresentative, null, 2)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $order->refresh();

        $this->assertSame(DeliveryStatus::WithRepresentative, $order->delivery_status);
        $this->assertNull($order->status_reason);
        $this->assertSame(2, $order->masar_status_version);
    }

    public function test_with_rep_carrying_a_reason_is_refused(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, DeliveryStatus::WithRepresentative, 'customer_absent', 1)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertUntouched($order);
    }

    /**
     * B-1 and B-2 together: a clear must not be undone by the very event it
     * withdrew, arriving late.
     */
    public function test_a_stale_result_cannot_undo_a_clear(): void
    {
        $order = $this->assignedOrder();

        $postponement = $this->envelope($order, DeliveryStatus::Postponed, 'customer_absent', null, 1);

        $this->sendPayload($postponement)->assertOk();
        $this->send($order, DeliveryStatus::WithRepresentative, null, 2)->assertOk();

        // The courier's original postponement, retried after the clear.
        $late = $this->envelope($order, DeliveryStatus::Postponed, 'customer_absent', null, 1);

        $this->sendPayload($late)
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale');

        $order->refresh();

        $this->assertSame(DeliveryStatus::WithRepresentative, $order->delivery_status);
        $this->assertNull($order->status_reason);
        $this->assertSame(2, $order->masar_status_version);
    }

    public function test_the_clear_does_not_touch_the_legacy_lifecycle_columns_either(): void
    {
        $order = $this->assignedOrder();

        app(DeliveryOrderLifecycleService::class)->complete($order, DeliveryOrderResult::Delivered);

        $this->send($order->fresh(), DeliveryStatus::WithRepresentative, null, 1)->assertOk();

        $order->refresh();

        $this->assertSame(DeliveryOrderResult::Delivered, $order->result);
        $this->assertSame(DeliveryOrderStatus::Completed, $order->status);
        $this->assertSame(DeliveryStatus::WithRepresentative, $order->delivery_status);
    }

    // ------------------------------------------------------------ helpers

    private function issueToken(string $clientId, string $secret): string
    {
        $response = $this->postJson(self::TOKENS, ['client_id' => $clientId, 'client_secret' => $secret]);

        $response->assertOk()->assertJsonPath('token_type', 'Bearer');

        return $response->json('access_token');
    }

    private function send(
        DeliveryOrder $order,
        DeliveryStatus $status = DeliveryStatus::Delivered,
        ?string $reason = null,
        int $version = 1,
    ): TestResponse {
        return $this->sendPayload($this->envelope($order, $status, $reason, null, $version));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendPayload(array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson(self::EVENTS, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(
        ?DeliveryOrder $order = null,
        DeliveryStatus $status = DeliveryStatus::Delivered,
        ?string $reason = null,
        ?string $externalOrderId = null,
        int $version = 1,
    ): array {
        $instant = Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'contract_version' => MasarStatusEnvelope::CONTRACT_VERSION,
            'event_id' => (string) Str::uuid(),
            'event_type' => MasarStatusEnvelope::EVENT_TYPE,
            'occurred_at' => $instant,
            'data' => [
                'order_id' => $externalOrderId ?? (string) $order?->getKey(),
                'status_version' => $version,
                'delivery_status' => $status->value,
                'status_reason' => $reason,
                'result_occurred_at' => $instant,
            ],
        ];
    }

    private function assertUntouched(DeliveryOrder $order): void
    {
        $order->refresh();

        $this->assertSame(DeliveryStatus::WithRepresentative, $order->delivery_status);
        $this->assertNull($order->status_reason);
    }

    private function assignedOrder(): DeliveryOrder
    {
        $customer = Customer::create([
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
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);

        return app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($order, $representative);
    }
}
