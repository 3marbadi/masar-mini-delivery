<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\OperationalStatusProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What a refusal is worth once it has been recorded (§3.21.6, §3.21.7).
 *
 * This file exists because a stage-5B claim was wrong. That report said a
 * `404 ORDER_NOT_FOUND` could be recovered by requeueing the same event once the
 * order existed — and it said so on the strength of a *sender-side* test whose
 * faked receiver answered `200` on the retry. The real receiver does no such
 * thing: `MasarTourParticipationEventProcessor::duplicate()` replays a recorded
 * rejection verbatim, deliberately, so that the answer to one event does not
 * depend on when it was asked.
 *
 * The cases below pin which refusals are sticky and which are not, because the
 * sender's recovery strategy is only correct if it matches this exactly:
 *
 *   - **`404 ORDER_NOT_FOUND` — recorded, and sticky for ever.** No amount of
 *     repairing the order makes the same `event_id` succeed.
 *   - **`409 VERSION_CONFLICT` — deliberately not recorded** (`recordRejection`
 *     returns early), so nothing is replayed and the request is judged afresh.
 *   - **Request-level `422`** — refused before the processor runs, so no event
 *     row is written and a later retry is judged afresh.
 *
 * The consequence for Masar is in that repository: an announcement refused `404`
 * can only be replaced by a **new** event at a **new** version, never re-sent.
 */
class MasarRejectionReplayTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTS = '/api/v1/integration/events';

    private const TOKENS = '/api/v1/integration/auth/token';

    private const PARTICIPATION = 'order.tour.participation.updated';

    private const NOW = '2026-10-08 07:00:00';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);

        MasarIntegrationClient::create([
            'name' => 'Masar',
            'client_id' => 'masar',
            'client_secret_hash' => Hash::make('masar-secret'),
            'status' => 'active',
        ]);

        $this->token = $this->postJson(self::TOKENS, [
            'client_id' => 'masar',
            'client_secret' => 'masar-secret',
        ])->assertOk()->json('access_token');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------- the sticky refusal

    /**
     * The whole of stage-5C §A, in one test.
     *
     * An announcement arrives for an order this side does not have; it is
     * refused `404` and the refusal is recorded. The order is then created with
     * exactly that identity, and the very same event — same `event_id`, same
     * payload, same digest, same version — is re-sent. It is refused again.
     */
    public function test_a_recorded_404_is_replayed_even_after_the_order_exists(): void
    {
        $uid = (string) Str::uuid7();
        $envelope = $this->participationPayload($uid, 'active', 1);

        // 1 & 2 — the order does not exist here.
        $this->sendPayload($envelope)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

        // 3 — what the receiver recorded.
        $recorded = MasarIntegrationEvent::query()->sole();
        $this->assertSame($envelope['event_id'], $recorded->event_id);
        $this->assertSame('rejected', $recorded->result);
        $this->assertSame('ORDER_NOT_FOUND', $recorded->error_code);
        $this->assertSame(404, (int) $recorded->http_status);
        $this->assertSame($uid, $recorded->external_order_id);
        $this->assertNull($recorded->delivery_order_id);
        // The claimed version is kept, which is the first thing anyone asks
        // when the two systems disagree.
        $this->assertSame(1, (int) $recorded->participation_version);

        // 4 — the order is created, with the identity the event named.
        $order = $this->assignedOrder($uid);
        $this->assertSame($uid, $order->integration_uid);

        // 5 & 6 — the identical event, re-sent.
        $this->sendPayload($envelope)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

        // Nothing was applied, and nothing new was recorded: the stored
        // rejection answered, and it will answer every future retry too.
        $order->refresh();
        $this->assertSame(0, $order->masar_participation_version);
        $this->assertSame(1, MasarIntegrationEvent::query()->count());
        $this->assertOperational($order, 'assigned');
    }

    /** Ten replays change nothing, so this is a property and not a timing artefact. */
    public function test_the_recorded_rejection_survives_repeated_replays(): void
    {
        $uid = (string) Str::uuid7();
        $envelope = $this->participationPayload($uid, 'active', 1);

        $this->sendPayload($envelope)->assertStatus(404);
        $order = $this->assignedOrder($uid);

        foreach (range(1, 10) as $ignored) {
            $this->sendPayload($envelope)
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
        }

        $this->assertSame(0, (int) $order->fresh()->masar_participation_version);
        $this->assertSame(1, MasarIntegrationEvent::query()->count());
    }

    /**
     * A **new** event for the same order is accepted, which is the only repair
     * that works.
     *
     * Same order, same participation, a new `event_id` and a higher version: the
     * recorded rejection names an identity that is not this one, so nothing is
     * replayed and the announcement applies.
     */
    public function test_a_new_event_at_a_new_version_is_accepted_after_a_recorded_404(): void
    {
        $uid = (string) Str::uuid7();

        $this->sendPayload($this->participationPayload($uid, 'active', 1))->assertStatus(404);

        $order = $this->assignedOrder($uid);

        $this->sendPayload($this->participationPayload(
            $uid,
            'active',
            2,
            (string) $order->representative->integration_uid,
        ))->assertOk()->assertJsonPath('status', 'processed');

        $order->refresh();
        $this->assertSame(2, $order->masar_participation_version);
        $this->assertSame('active', $order->masar_participation->value);
        $this->assertOperational($order, 'in_progress');
    }

    /**
     * And a new `event_id` re-using the *rejected* version is refused.
     *
     * Not by the rejection record — by the ordering rule, since version 1 is no
     * longer above the applied 0 once... in fact here nothing has been applied,
     * so this case is about the sender's own sequencing: a replacement has to
     * take a *new* version, and this test fixes what happens if it does not.
     */
    public function test_a_new_event_re_using_the_rejected_version_is_still_accepted_when_nothing_was_applied(): void
    {
        $uid = (string) Str::uuid7();

        $this->sendPayload($this->participationPayload($uid, 'active', 1))->assertStatus(404);

        $order = $this->assignedOrder($uid);

        // Version 1 against an applied 0 is newer, so it applies. Recorded here
        // so the sender's choice to mint a *higher* version is understood as
        // belonging to its own sequence rather than as something the receiver
        // demands.
        $this->sendPayload($this->participationPayload($uid, 'active', 1))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame(1, (int) $order->fresh()->masar_participation_version);
    }

    // --------------------------------------- the refusals that are not sticky

    /**
     * `409 VERSION_CONFLICT` is deliberately not recorded, so it is judged
     * afresh.
     *
     * Two different events claiming one version: the second is refused, nothing
     * is written for it, and once the real state has moved on it can be
     * replaced without its own id standing in the way.
     */
    public function test_a_409_is_not_recorded_and_does_not_stick(): void
    {
        $order = $this->assignedOrder();
        $uid = (string) $order->integration_uid;

        $this->sendPayload($this->participationPayload($uid, 'active', 1))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $conflicting = $this->participationPayload($uid, 'ended', 1);

        $this->sendPayload($conflicting)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        // No row for the refused event: `recordRejection` returns early for a
        // conflict, because the row it collided with already holds the identity.
        $this->assertNull(
            MasarIntegrationEvent::query()->where('event_id', $conflicting['event_id'])->first(),
        );

        // So the sender can still say what it means, at its own next version.
        $this->sendPayload($this->participationPayload($uid, 'ended', 2))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame('ended', $order->fresh()->masar_participation->value);
    }

    /**
     * A request the validator refuses never reaches the processor, so no event
     * row exists and a retry after the receiver is upgraded is judged afresh.
     *
     * This is why the sender's `422` recovery story *is* sound while its `404`
     * one was not: the two refusals come from different layers, and only one of
     * them remembers.
     */
    public function test_a_request_level_refusal_records_nothing(): void
    {
        $order = $this->assignedOrder();

        $unknown = $this->participationPayload((string) $order->integration_uid, 'active', 1);
        $unknown['event_type'] = 'order.something.nobody.knows';

        $this->sendPayload($unknown)->assertStatus(422);

        $this->assertSame(0, MasarIntegrationEvent::query()->count());

        // The same event id, now with a type the receiver understands, applies.
        $known = $this->participationPayload((string) $order->integration_uid, 'active', 1);
        $known['event_id'] = $unknown['event_id'];

        $this->sendPayload($known)->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame(1, (int) $order->fresh()->masar_participation_version);
    }

    /** A replay carrying different bytes under a used id is a conflict, not a replay. */
    public function test_the_same_event_id_with_a_different_payload_is_refused(): void
    {
        $order = $this->assignedOrder();
        $uid = (string) $order->integration_uid;

        $first = $this->participationPayload($uid, 'active', 1);
        $this->sendPayload($first)->assertOk();

        $tampered = $first;
        $tampered['data']['participation'] = 'ended';

        $this->sendPayload($tampered)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $this->assertSame('active', $order->fresh()->masar_participation->value);
    }

    /**
     * A recorded `404` belongs to one client, as §3.21.6's identity says.
     *
     * Recorded rather than asserted as desirable: there is one integration
     * client in production, so this is a property of the index and not a
     * feature anyone relies on — but it is the reason the stickiness cannot be
     * worked around by re-authenticating.
     */
    public function test_the_rejection_is_scoped_to_the_client_that_earned_it(): void
    {
        $uid = (string) Str::uuid7();
        $envelope = $this->participationPayload($uid, 'active', 1);

        $this->sendPayload($envelope)->assertStatus(404);

        MasarIntegrationClient::create([
            'name' => 'Masar Two',
            'client_id' => 'masar-2',
            'client_secret_hash' => Hash::make('other-secret'),
            'status' => 'active',
        ]);

        $otherToken = $this->postJson(self::TOKENS, [
            'client_id' => 'masar-2',
            'client_secret' => 'other-secret',
        ])->assertOk()->json('access_token');

        $this->assignedOrder($uid);

        // A different client's identical event is a different event, and is
        // judged on the state of the world now.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->postJson(self::EVENTS, $envelope)
            ->assertOk()
            ->assertJsonPath('status', 'processed');
    }

    // ------------------------------------------------------------ machinery

    /** @return array<string, mixed> */
    private function participationPayload(
        string $externalOrderId,
        string $participation,
        int $version,
        ?string $externalCourierId = null,
    ): array {
        return [
            'contract_version' => '1.0',
            'event_id' => (string) Str::uuid(),
            'event_type' => self::PARTICIPATION,
            'occurred_at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'data' => [
                'order_id' => $externalOrderId,
                'participation_version' => $version,
                'participation' => $participation,
                'base_order_version' => 1,
                // A uid naming no local representative by default: these cases
                // are about whether a refusal is remembered, not about courier
                // matching. A case that asserts «جاري التوصيل» passes the real
                // one, because the projection's courier guard requires it.
                'external_courier_id' => $externalCourierId ?? (string) Str::uuid7(),
                'tour_reference' => '4120',
                'tour_departure_at' => '2026-10-08T07:30:00Z',
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function sendPayload(array $payload): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson(self::EVENTS, $payload);
    }

    private function assignedOrder(?string $uid = null): DeliveryOrder
    {
        $customer = Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => '091'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        ]);

        $representative = Representative::create([
            'name' => 'Rep '.uniqid(),
            'phone' => '092'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'is_active' => true,
        ]);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);

        $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $representative);

        if ($uid !== null) {
            // `integration_uid` is minted on creation and is not a field anyone
            // may set, so a fixture that needs a known one writes it directly —
            // which is also what makes "the order Masar was told about" and
            // "the order that now exists" the same row.
            DB::table('delivery_orders')
                ->where('id', $order->id)
                ->update(['integration_uid' => $uid]);

            $order->refresh();
        }

        return $order;
    }

    private function assertOperational(DeliveryOrder $order, string $expected): void
    {
        $this->assertSame(
            $expected,
            OperationalStatusProjection::operationalFor($order->fresh())->value,
        );
    }
}
