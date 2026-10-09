<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\DeliveryStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\OperationalStatusProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The receiving half of the eight stage-5 scenarios
 * (§20 of the stage-5 brief; §13.29 — D31, draft).
 *
 * Masar's suite drives each scenario through its own services and captures the
 * envelopes it actually puts on the wire. This file takes those sequences and
 * finishes the story: the envelope arrives at the real endpoint, through the
 * real validator and processor, and an operational status comes out the other
 * side — which is the step the brief writes as «ظهور ‹جاري التوصيل›» and that no
 * amount of sender-side assertion can stand in for.
 *
 * **What is carried over and what is not.** The sequences are Masar's: the event
 * types, the participation values, the versions, the `base_order_version`
 * stamps, the arrival order. Two things are substituted, and only two —
 * `order_id` and `external_courier_id` — because those are per-deployment
 * identities: Masar addresses this company's orders by the `integration_uid`
 * this company issued, and a fixture cannot know a uid that is minted when the
 * test's own order is created. Everything the contract actually fixes is
 * unchanged, and the field-for-field agreement between the two sides is pinned
 * separately by the payload digest both suites assert.
 *
 * **What this is not.** Not two live servers. The two halves run in two
 * processes against two databases and are joined by the captured sequences and
 * that digest. Where a scenario's step belongs to Masar, this file does not
 * re-assert it.
 */
class MasarParticipationScenariosTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTS = '/api/v1/integration/events';

    private const TOKENS = '/api/v1/integration/auth/token';

    private const PARTICIPATION = 'order.tour.participation.updated';

    private const STATUS = 'order.delivery_status.updated';

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

    // ------------------------------------------------- A — a successful delivery

    /**
     * Scenario A, steps 7 and 10: «جاري التوصيل» after `active`, and «تم
     * التسليم» after the delivery result.
     *
     * Masar's capture for A is three envelopes: participation `scheduled` v1,
     * participation `active` v2, then a delivery on the status channel at
     * `status_version` 1. The two sequences are independent and the file sends
     * them in the order Masar sent them.
     */
    public function test_scenario_a_active_then_delivered(): void
    {
        $order = $this->assignedOrder();

        $this->assertOperational($order, 'assigned');

        $this->participation($order, 'scheduled', 1)->assertOk()->assertJsonPath('status', 'processed');
        $this->assertOperational($order, 'assigned');

        $this->participation($order, 'active', 2)->assertOk()->assertJsonPath('status', 'processed');
        $this->assertOperational($order, 'in_progress');

        $this->deliveryStatus($order, 'delivered', 1)->assertOk()->assertJsonPath('status', 'processed');
        $this->assertOperational($order, 'delivered');

        // The historical lifecycle is untouched: Masar's result did not close
        // the order administratively and did not write `result` or
        // `completed_at`, which stay this company's own (§13.12).
        $order->refresh();
        $this->assertSame(DeliveryOrderStatus::Assigned, $order->status);
        $this->assertNull($order->result);
        $this->assertNull($order->completed_at);
        $this->assertSame('open', OperationalStatusProjection::administrativeFor($order)->value);
    }

    // --------------------------------------------------------- B — a postponement

    /** Scenario B, step 6: «مؤجل». */
    public function test_scenario_b_active_then_postponed(): void
    {
        $order = $this->assignedOrder();

        $this->participation($order, 'scheduled', 1)->assertOk();
        $this->participation($order, 'active', 2)->assertOk();
        $this->assertOperational($order, 'in_progress');

        $this->deliveryStatus($order, 'postponed', 1, reason: 'customer_requested_deferral')->assertOk();

        $this->assertOperational($order, 'postponed');

        // A postponement outranks a live participation: the order is still in a
        // running tour, and still reads «مؤجل» rather than «جاري التوصيل».
        $order->refresh();
        $this->assertSame('active', $order->masar_participation->value);
        $this->assertSame(DeliveryStatus::Postponed, $order->delivery_status);
    }

    // --------------------------------------------------------------- C — a return

    /** Scenario C, steps 4 and 5: «راجع», and not an administrative cancellation. */
    public function test_scenario_c_a_return_is_not_a_cancellation(): void
    {
        $order = $this->assignedOrder();

        $this->participation($order, 'scheduled', 1)->assertOk();
        $this->participation($order, 'active', 2)->assertOk();
        $this->deliveryStatus($order, 'returned', 1, reason: 'customer_refused')->assertOk();

        $this->assertOperational($order, 'returned');

        $order->refresh();
        $this->assertSame('open', OperationalStatusProjection::administrativeFor($order)->value);
        $this->assertNull($order->cancelled_at);
        $this->assertNotSame(DeliveryOrderStatus::Cancelled, $order->status);
    }

    // ------------------------------------------- D — a closure with no result

    /**
     * Scenario D, steps 5 and 6: an order whose tour closed with no result goes
     * back to «مسندة», and the participation history it had is still readable.
     */
    public function test_scenario_d_a_closed_tour_returns_the_order_to_assigned(): void
    {
        $order = $this->assignedOrder();

        $this->participation($order, 'scheduled', 1)->assertOk();
        $this->participation($order, 'active', 2)->assertOk();
        $this->assertOperational($order, 'in_progress');

        $this->participation($order, 'ended', 3)->assertOk()->assertJsonPath('status', 'processed');

        $this->assertOperational($order, 'assigned');

        // Not erased — ended. The window is closed and still on the record,
        // which is what the UI reads to say when it was.
        $order->refresh();
        $this->assertSame('ended', $order->masar_participation->value);
        $this->assertSame(3, $order->masar_participation_version);
        $this->assertNotNull($order->masar_participation_ended_at);
        // No result was invented: the order is still «مع المندوب», which is the
        // starting state of the status channel and not an outcome (§3.14.1).
        $this->assertSame(DeliveryStatus::WithRepresentative, $order->delivery_status);
    }

    // ------------------------------------------------------- E — a late event

    /**
     * Scenario E, step 4: a late `active` arrives after the delivery, and the
     * order keeps reading «تم التسليم».
     *
     * Masar does not produce this envelope — its own suite proves it stops
     * announcing once a result exists — so the one sent here is a replay of the
     * `active` from scenario A, which is exactly the shape a retried or delayed
     * delivery would hand the receiver.
     */
    public function test_scenario_e_a_late_active_does_not_unseat_a_delivery(): void
    {
        $order = $this->assignedOrder();

        $this->participation($order, 'scheduled', 1)->assertOk();
        $this->deliveryStatus($order, 'delivered', 1)->assertOk();
        $this->assertOperational($order, 'delivered');

        // The straggler. It is newer on its own channel and is applied there;
        // what it must not do is change what the order reads.
        $this->participation($order, 'active', 2)->assertOk()->assertJsonPath('status', 'processed');

        $order->refresh();
        $this->assertSame('active', $order->masar_participation->value);
        $this->assertOperational($order, 'delivered');

        // And this is deliberately *not* flagged as a conflict. A late
        // participation beside a result is not a contradiction — the projection
        // already settles it by precedence, results outranking participation —
        // so raising a conflict here would put a warning on an order that is
        // simply correct. `hasConflict` is reserved for the one thing precedence
        // cannot settle: an administrative outcome recorded here that disagrees
        // with the result Masar recorded there.
        $this->assertFalse(OperationalStatusProjection::hasConflict($order));
    }

    // ------------------------------------------------------ F — a reassignment

    /**
     * Scenario F, step 6: A → B → A with the `ended` lost entirely, and the
     * order still reads «مسندة».
     *
     * This is the false-revival case the whole fence exists for. No `ended` ever
     * arrives: the only participation this order holds is the `active` A's tour
     * produced, stamped with the order version Masar knew at the time.
     */
    public function test_scenario_f_a_round_trip_reassignment_with_a_lost_ended_stays_assigned(): void
    {
        $order = $this->assignedOrder();
        $a = $order->representative;

        $this->participation($order, 'scheduled', 1)->assertOk();
        $this->participation($order, 'active', 2, baseOrderVersion: 1)->assertOk();
        $this->assertOperational($order, 'in_progress');

        // The wire goes dark. B takes the order, then A takes it back — two
        // assignments, each moving the fence, and no `ended` in between.
        $b = $this->representative('ب');
        $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $b);
        $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $a);

        $this->assertOperational($order, 'assigned');

        $order->refresh();

        // Both guards are what refuse it, and each would refuse it alone.
        $this->assertFalse(
            OperationalStatusProjection::participationValidFor($order),
            'the version fence let a pre-reassignment active through',
        );
        $this->assertSame('active', $order->masar_participation->value);
        $this->assertGreaterThan(
            (int) $order->masar_participation_base_order_version,
            (int) $order->assignment_order_version,
        );
    }

    // ------------------------------------------- G — an outage and a recovery

    /**
     * Scenario G, step 7: after a recovery the order shows the newest correct
     * state, whatever order the backlog arrived in.
     *
     * Masar's capture for G is the same three transitions, delivered after the
     * outage with their backoffs expiring at different times — so `ended` can
     * precede `active` on the wire. This sends them in that order on purpose.
     */
    public function test_scenario_g_a_backlog_delivered_out_of_order_settles_on_the_newest(): void
    {
        $order = $this->assignedOrder();

        $this->participation($order, 'scheduled', 1)->assertOk();

        // v3 first. Built once and kept, because the replay below has to be the
        // same event and not merely the same shape.
        $ended = $this->participationPayload($order, 'ended', 3);
        $this->sendPayload($ended)->assertOk()->assertJsonPath('status', 'processed');
        $this->assertOperational($order, 'assigned');

        // v2 after it, and refused as stale rather than applied.
        $this->participation($order, 'active', 2)
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale');

        $order->refresh();
        $this->assertSame('ended', $order->masar_participation->value);
        $this->assertSame(3, $order->masar_participation_version);
        $this->assertOperational($order, 'assigned');

        // A true replay — the same event, re-sent — is absorbed.
        $this->sendPayload($ended)->assertOk()->assertJsonPath('status', 'already_processed');

        // A *different* event claiming the same version is not a replay, and is
        // refused rather than guessed at (§12's tenth ordering case).
        $this->participation($order, 'ended', 3)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $this->assertSame(3, $order->fresh()->masar_participation_version);
    }

    // ------------------------------------------------------- H — a scheduled tour

    /**
     * Scenario H, steps 3, 5 and 8: `scheduled` arrives, the departure hour
     * passes with nothing else arriving and the order still reads «مسندة», and
     * only the `active` that follows the courier's confirmation moves it.
     */
    public function test_scenario_h_a_scheduled_tour_shows_assigned_until_the_courier_confirms(): void
    {
        $order = $this->assignedOrder();

        $this->participation($order, 'scheduled', 1, departsAt: '2026-10-08T14:00:00Z')->assertOk();

        $this->assertOperational($order, 'assigned');

        // The hour arrives. Nothing is sent, because Masar sends nothing — and
        // the receiver has no clock in its projection, so the mere passage of
        // the departure time cannot move anything on this side either.
        Carbon::setTestNow('2026-10-08 14:30:00');
        $this->assertOperational($order, 'assigned');

        // Seven hours on, the bearer token from setUp has aged out. Masar's
        // client re-authenticates in exactly this situation, so the test does
        // too rather than pretending a token lives for ever.
        $this->token = $this->postJson(self::TOKENS, [
            'client_id' => 'masar',
            'client_secret' => 'masar-secret',
        ])->assertOk()->json('access_token');

        $order->refresh();
        $this->assertSame('scheduled', $order->masar_participation->value);
        $this->assertSame('2026-10-08 14:00:00', $order->masar_tour_departure_at->format('Y-m-d H:i:s'));

        // The confirmation.
        $this->participation($order, 'active', 2)->assertOk();
        $this->assertOperational($order, 'in_progress');

        Carbon::setTestNow(self::NOW);
    }

    // ------------------------------- I — recovery after a repaired mapping

    /**
     * The receiving end of stage-5B §1 step 7: the order shows the right state
     * when the *first* announcement it ever gets is a recovery.
     *
     * Masar held two earlier transitions it could not address — the order had no
     * integration mapping, so `scheduled` v1 and `active` v2 were written
     * `failed` and never left. Once the mapping was repaired the reconciler
     * minted `active` at v3, and that is the only participation this side will
     * ever see. So version 3 arrives against a stored version of 0, with no
     * predecessor, and must be applied rather than treated as a gap.
     */
    public function test_scenario_i_a_recovery_announcement_is_applied_though_it_is_the_first_one_seen(): void
    {
        $order = $this->assignedOrder();

        $this->assertSame(0, $order->masar_participation_version);
        $this->assertOperational($order, 'assigned');

        $this->participation($order, 'active', 3)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $order->refresh();
        $this->assertSame('active', $order->masar_participation->value);
        $this->assertSame(3, $order->masar_participation_version);
        $this->assertOperational($order, 'in_progress');
    }

    /**
     * And the versions that never left cannot un-apply it if they somehow turn
     * up afterwards.
     *
     * They are dead rows at Masar and nothing will ever post them, but the
     * receiver's guarantee should not rest on that: a lower version is stale by
     * §13.29's ordering rule whether or not its sender still exists.
     */
    public function test_scenario_i_the_versions_that_never_left_would_be_refused_as_stale(): void
    {
        $order = $this->assignedOrder();

        $this->participation($order, 'active', 3)->assertOk();
        $this->assertOperational($order, 'in_progress');

        foreach ([1, 2] as $version) {
            $this->participation($order, 'scheduled', $version)
                ->assertOk()
                ->assertJsonPath('status', 'ignored_stale');
        }

        $order->refresh();
        $this->assertSame('active', $order->masar_participation->value);
        $this->assertSame(3, $order->masar_participation_version);
        $this->assertOperational($order, 'in_progress');
    }

    // ------------------------------------------------------------- machinery

    private function participation(
        DeliveryOrder $order,
        string $participation,
        int $version,
        ?int $baseOrderVersion = null,
        string $departsAt = '2026-10-08T07:30:00Z',
    ): TestResponse {
        return $this->sendPayload(
            $this->participationPayload($order, $participation, $version, $baseOrderVersion, $departsAt),
        );
    }

    /** @return array<string, mixed> */
    private function participationPayload(
        DeliveryOrder $order,
        string $participation,
        int $version,
        ?int $baseOrderVersion = null,
        string $departsAt = '2026-10-08T07:30:00Z',
    ): array {
        return [
            'contract_version' => '1.0',
            'event_id' => (string) Str::uuid(),
            'event_type' => self::PARTICIPATION,
            'occurred_at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'data' => [
                'order_id' => (string) $order->integration_uid,
                'participation_version' => $version,
                'participation' => $participation,
                'base_order_version' => $baseOrderVersion ?? (int) $order->assignment_order_version,
                'external_courier_id' => (string) $order->representative->integration_uid,
                'tour_reference' => '1',
                'tour_departure_at' => $departsAt,
            ],
        ];
    }

    private function deliveryStatus(
        DeliveryOrder $order,
        string $status,
        int $version,
        ?string $reason = null,
    ): TestResponse {
        return $this->sendPayload([
            'contract_version' => '1.0',
            'event_id' => (string) Str::uuid(),
            'event_type' => self::STATUS,
            'occurred_at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'data' => [
                'order_id' => (string) $order->integration_uid,
                'status_version' => $version,
                'delivery_status' => $status,
                'status_reason' => $reason,
                'result_occurred_at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function sendPayload(array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson(self::EVENTS, $payload);
    }

    private function representative(string $name): Representative
    {
        return Representative::create([
            'name' => $name.' '.uniqid(),
            'phone' => '092'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'is_active' => true,
        ]);
    }

    private function assignedOrder(): DeliveryOrder
    {
        $customer = Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => '091'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        ]);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);

        return app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($order, $this->representative('كريم'));
    }

    private function assertOperational(DeliveryOrder $order, string $expected): void
    {
        $this->assertSame(
            $expected,
            OperationalStatusProjection::operationalFor($order->fresh())->value,
        );
    }
}
