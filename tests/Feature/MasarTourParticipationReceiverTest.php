<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Enums\DeliveryStatus;
use App\Enums\TourParticipation;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use App\Models\OrderIntegrationState;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\Integration\MasarTourParticipationEnvelope;
use App\Services\OperationalStatusProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The participation channel's receiving half (CONTRACT §13.29 — D31, draft).
 *
 * The divisions follow the contract's own: the closed vocabulary, the unknown
 * order, idempotency in both of its forms, ordering within this channel's own
 * sequence — and, separately and at length, the two properties that make a
 * fifth inbound channel safe to have at all. That receiving a statement
 * produces no statement back, and that it writes not one column belonging to
 * the four channels beside it or to this company's own lifecycle.
 */
class MasarTourParticipationReceiverTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTS = '/api/v1/integration/events';

    private const TOKENS = '/api/v1/integration/auth/token';

    private MasarIntegrationClient $client;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 07:12:04');

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

    // ------------------------------------------------------ the three states

    public function test_a_scheduled_participation_is_applied_and_is_not_in_progress(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, TourParticipation::Scheduled)->assertOk()
            ->assertJsonPath('status', 'processed');

        $order->refresh();

        $this->assertSame(TourParticipation::Scheduled, $order->masar_participation);
        $this->assertSame(1, $order->masar_participation_version);
        $this->assertSame(1, $order->masar_participation_base_order_version);
        $this->assertSame('4120', $order->masar_tour_reference);

        // A prepared tour has begun nothing and ended nothing, so neither
        // accumulating instant is stamped.
        $this->assertNull($order->masar_participation_started_at);
        $this->assertNull($order->masar_participation_ended_at);

        $this->assertOperational($order, 'assigned');
    }

    public function test_an_active_participation_is_applied_and_reads_in_progress(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, TourParticipation::Active)->assertOk()
            ->assertJsonPath('status', 'processed');

        $order->refresh();

        $this->assertSame(TourParticipation::Active, $order->masar_participation);
        $this->assertSame('2026-10-08 07:12:04', $order->masar_participation_started_at->format('Y-m-d H:i:s'));
        $this->assertNull($order->masar_participation_ended_at);

        $this->assertOperational($order, 'in_progress');
    }

    public function test_an_ended_participation_is_applied_and_keeps_the_start_instant(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, TourParticipation::Active, version: 1)->assertOk();
        $this->send($order, TourParticipation::Ended, version: 2)->assertOk()
            ->assertJsonPath('status', 'processed');

        $order->refresh();

        $this->assertSame(TourParticipation::Ended, $order->masar_participation);
        $this->assertSame(2, $order->masar_participation_version);

        // The ending does not erase when the tour began. That is the whole
        // reason these are two columns rather than one.
        $this->assertSame('2026-10-08 07:12:04', $order->masar_participation_started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-08 07:12:04', $order->masar_participation_ended_at->format('Y-m-d H:i:s'));

        $this->assertOperational($order, 'assigned');
    }

    public function test_a_participation_outside_the_three_is_refused(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order);
        $payload['data']['participation'] = 'none';

        $this->sendPayload($payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertNoParticipation($order);
    }

    public function test_an_unknown_event_type_still_has_its_own_code(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order);
        $payload['event_type'] = 'order.tour.started';

        $this->sendPayload($payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNKNOWN_EVENT_TYPE');

        $this->assertNoParticipation($order);
    }

    public function test_an_unknown_external_order_is_refused_and_recorded(): void
    {
        $payload = $this->envelope(externalOrderId: (string) Str::uuid7());

        $this->sendPayload($payload)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

        $event = MasarIntegrationEvent::query()->where('event_id', $payload['event_id'])->firstOrFail();

        $this->assertSame('rejected', $event->result);
        $this->assertSame(1, (int) $event->participation_version);
        $this->assertNull($event->delivery_order_id);
    }

    // ------------------------------------------------------------- ordering

    public function test_a_newer_version_is_applied_and_becomes_the_high_water_mark(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, TourParticipation::Active, version: 1)->assertOk();
        $this->send($order, TourParticipation::Ended, version: 4)->assertOk()
            ->assertJsonPath('status', 'processed');

        $order->refresh();

        // Gaps are applied rather than refused: the payload carries the whole
        // current participation, not a difference from version 2.
        $this->assertSame(4, $order->masar_participation_version);
        $this->assertSame(TourParticipation::Ended, $order->masar_participation);
    }

    public function test_a_stale_version_arriving_late_cannot_revert_the_state(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, TourParticipation::Active, version: 1)->assertOk();
        $this->send($order, TourParticipation::Ended, version: 2)->assertOk();

        // The `active` for a tour that has since finished, retried late.
        $this->send($order, TourParticipation::Active, version: 1)
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale')
            ->assertJsonPath('applied_participation_version', 2);

        $order->refresh();

        $this->assertSame(TourParticipation::Ended, $order->masar_participation);
        $this->assertSame(2, $order->masar_participation_version);
        $this->assertOperational($order, 'assigned');
    }

    public function test_a_stale_event_is_logged_as_a_successful_outcome_not_an_error(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, TourParticipation::Ended, version: 3)->assertOk();

        $payload = $this->envelope($order, TourParticipation::Active, version: 2);
        $this->sendPayload($payload)->assertOk();

        $event = MasarIntegrationEvent::query()->where('event_id', $payload['event_id'])->firstOrFail();

        $this->assertSame('ignored_stale', $event->result);
        $this->assertSame(200, (int) $event->http_status);
        $this->assertSame(2, (int) $event->participation_version);
    }

    public function test_a_stale_event_replayed_answers_ignored_stale_again(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, TourParticipation::Ended, version: 3)->assertOk();

        $payload = $this->envelope($order, TourParticipation::Active, version: 2);

        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'ignored_stale');
        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'ignored_stale')
            ->assertJsonPath('applied_participation_version', 3);
    }

    public function test_the_same_event_replayed_answers_already_processed_and_applies_nothing_twice(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order, TourParticipation::Active);

        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'processed');
        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'already_processed');

        $this->assertSame(
            1,
            MasarIntegrationEvent::query()->where('event_id', $payload['event_id'])->count(),
        );

        $order->refresh();
        $this->assertSame(1, $order->masar_participation_version);
    }

    public function test_the_same_event_id_with_a_different_payload_is_a_conflict(): void
    {
        $order = $this->assignedOrder();

        $first = $this->envelope($order, TourParticipation::Active);
        $this->sendPayload($first)->assertOk();

        $second = $first;
        $second['data']['participation'] = TourParticipation::Ended->value;

        $this->sendPayload($second)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $order->refresh();
        $this->assertSame(TourParticipation::Active, $order->masar_participation);
    }

    public function test_two_different_events_at_the_same_version_are_a_conflict(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, TourParticipation::Active, version: 1)->assertOk();

        // A different event_id claiming a version already applied. Masar mints
        // one per transition, so one of the two is wrong and the receiver
        // cannot tell which.
        $payload = $this->envelope($order, TourParticipation::Ended, version: 1);

        $this->sendPayload($payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $order->refresh();
        $this->assertSame(TourParticipation::Active, $order->masar_participation);

        // A conflict is not recorded: the row it collided with already holds
        // the identity.
        $this->assertSame(
            0,
            MasarIntegrationEvent::query()->where('event_id', $payload['event_id'])->count(),
        );
    }

    public function test_a_version_below_one_is_refused(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order);
        $payload['data']['participation_version'] = 0;

        $this->sendPayload($payload)->assertStatus(422);
        $this->assertNoParticipation($order);
    }

    public function test_a_version_beyond_the_column_is_refused_rather_than_overflowing(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order);
        $payload['data']['participation_version'] = '9223372036854775808';

        $this->sendPayload($payload)->assertStatus(422);
        $this->assertNoParticipation($order);
    }

    // --------------------------------------------------- base_order_version

    public function test_a_missing_base_order_version_key_is_refused(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order);
        unset($payload['data']['base_order_version']);

        $this->sendPayload($payload)->assertStatus(422);
        $this->assertNoParticipation($order);
    }

    public function test_a_null_base_order_version_is_applied_but_is_never_operative(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order, TourParticipation::Active);
        $payload['data']['base_order_version'] = null;

        // Masar sends null for an order it holds without a mapping. That is a
        // state to record, not a body to refuse — but the fence fails closed on
        // it, so the statement never takes effect.
        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'processed');

        $order->refresh();

        $this->assertSame(TourParticipation::Active, $order->masar_participation);
        $this->assertNull($order->masar_participation_base_order_version);
        $this->assertFalse(OperationalStatusProjection::participationValidFor($order));
        $this->assertOperational($order, 'assigned');
    }

    // ------------------------------------------------------- courier identity

    public function test_the_courier_uid_is_resolved_and_kept_verbatim(): void
    {
        $order = $this->assignedOrder();
        $uid = (string) $order->representative->integration_uid;

        $payload = $this->envelope($order, TourParticipation::Active);
        $payload['data']['external_courier_id'] = $uid;

        $this->sendPayload($payload)->assertOk();

        $order->refresh();

        // Both: the uid as it arrived, and its local resolution.
        $this->assertSame($uid, $order->masar_tour_started_courier_uid);
        $this->assertSame($order->representative_id, $order->masar_tour_started_representative_id);
        $this->assertTrue(OperationalStatusProjection::participationCourierMatchesFor($order));
        $this->assertOperational($order, 'in_progress');
    }

    public function test_an_unmapped_courier_uid_is_accepted_recorded_and_not_displayed(): void
    {
        $order = $this->assignedOrder();
        $unknown = (string) Str::uuid7();

        $payload = $this->envelope($order, TourParticipation::Active);
        $payload['data']['external_courier_id'] = $unknown;

        // Accepted, not refused: Masar treats every 4xx as terminal and never
        // retries, so a refusal here would discard a true participation
        // statement for good over a representative mapping this channel does
        // not own.
        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'processed');

        $order->refresh();

        // Recorded: the uid survives so the audit can name whom Masar meant,
        // and so the repair below needs no new event.
        $this->assertSame($unknown, $order->masar_tour_started_courier_uid);
        $this->assertNull($order->masar_tour_started_representative_id);
        $this->assertSame(TourParticipation::Active, $order->masar_participation);

        // And not displayed. The fence passes — this is purely an identity
        // failure — so without the courier clauses this read `in_progress`.
        $this->assertTrue(OperationalStatusProjection::participationValidFor($order));
        $this->assertFalse(OperationalStatusProjection::participationCourierMatchesFor($order));
        $this->assertOperational($order, 'assigned');
    }

    public function test_a_courier_other_than_the_assigned_one_is_not_displayed_as_in_progress(): void
    {
        $order = $this->assignedOrder();

        $other = Representative::create([
            'name' => 'Someone else', 'phone' => '0945555555', 'is_active' => true,
        ]);

        $payload = $this->envelope($order, TourParticipation::Active);
        $payload['data']['external_courier_id'] = (string) $other->integration_uid;

        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'processed');

        $order->refresh();

        // Resolvable, and still not this order's courier. The version fence has
        // nothing to say about it — only the courier clauses do.
        $this->assertSame($other->getKey(), $order->masar_tour_started_representative_id);
        $this->assertNotSame($order->representative_id, $order->masar_tour_started_representative_id);
        $this->assertTrue(OperationalStatusProjection::participationValidFor($order));
        $this->assertFalse(OperationalStatusProjection::participationCourierMatchesFor($order));
        $this->assertOperational($order, 'assigned');
    }

    public function test_repairing_the_courier_mapping_restores_the_state_without_a_new_event(): void
    {
        $order = $this->assignedOrder();
        $uid = (string) Str::uuid7();

        $payload = $this->envelope($order, TourParticipation::Active);
        $payload['data']['external_courier_id'] = $uid;

        $this->sendPayload($payload)->assertOk();
        $order->refresh();
        $this->assertOperational($order, 'assigned');

        // The mapping is repaired: the representative this order is assigned to
        // turns out to be the courier Masar named all along.
        $order->representative->forceFill(['integration_uid' => $uid])->save();

        // Re-resolving the stored uid is a local derivation from data already
        // received. It asks nothing of Masar, mints no event, and moves neither
        // the participation nor its version — so idempotency is untouched: a
        // replay of the original event still answers `already_processed`.
        $resolved = Representative::query()->where('integration_uid', $uid)->value('id');
        $order->forceFill(['masar_tour_started_representative_id' => $resolved])->save();

        $order->refresh();
        $this->assertOperational($order, 'in_progress');
        $this->assertSame(1, $order->masar_participation_version);

        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'already_processed');
    }

    public function test_a_late_participation_event_cannot_disturb_a_standing_result(): void
    {
        foreach ([
            [DeliveryStatus::Delivered, null, 'delivered'],
            [DeliveryStatus::Postponed, 'customer_absent', 'postponed'],
            [DeliveryStatus::Returned, 'customer_refused', 'returned'],
        ] as [$status, $reason, $expected]) {
            $order = $this->assignedOrder();

            // Masar's result lands first.
            $order->forceFill([
                'delivery_status' => $status->value,
                'status_reason' => $reason,
                'masar_status_version' => 1,
            ])->save();

            // Then the `active` for the tour it was delivered on arrives late,
            // with a matching courier and a valid fence.
            $this->send($order, TourParticipation::Active)->assertOk()
                ->assertJsonPath('status', 'processed');

            $order->refresh();

            $this->assertSame($expected, OperationalStatusProjection::operationalFor($order)->value);
            $this->assertSame($status, $order->delivery_status);
            $this->assertSame(1, $order->masar_status_version);
        }
    }

    // ------------------------------------------------------------- ownership

    public function test_the_legacy_lifecycle_columns_are_left_alone(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, TourParticipation::Active)->assertOk();
        $this->send($order, TourParticipation::Ended, version: 2)->assertOk();

        $order->refresh();

        // This company's own lifecycle. CONTRACT.md line 4105 is explicit that
        // the receiver does not touch these, and `reception_rate` is computed
        // from two of them.
        $this->assertSame(DeliveryOrderStatus::Assigned, $order->status);
        $this->assertNull($order->result);
        $this->assertNull($order->completed_at);
        $this->assertNull($order->cancelled_at);
    }

    public function test_the_status_data_and_location_channels_are_left_alone(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, TourParticipation::Active)->assertOk();

        $order->refresh();

        // §13.12 — four independent domains, and this channel is a fifth. It
        // writes none of their columns and moves none of their counters.
        $this->assertSame(DeliveryStatus::WithRepresentative, $order->delivery_status);
        $this->assertNull($order->status_reason);
        $this->assertSame(0, $order->masar_status_version);
        $this->assertSame(0, (int) $order->masar_data_version);
        $this->assertSame(0, (int) $order->masar_location_version);
    }

    public function test_the_assignment_is_not_moved_by_a_participation_statement(): void
    {
        $order = $this->assignedOrder();
        $originalRepresentative = $order->representative_id;
        $originalFence = $order->assignment_order_version;

        $this->send($order, TourParticipation::Active)->assertOk();

        $order->refresh();

        $this->assertSame($originalRepresentative, $order->representative_id);
        $this->assertSame($originalFence, $order->assignment_order_version);
    }

    public function test_receiving_a_participation_statement_produces_no_outbound_event(): void
    {
        $order = $this->assignedOrder();

        $outboxBefore = IntegrationOutbox::query()->count();
        $versionBefore = OrderIntegrationState::query()
            ->where('delivery_order_id', $order->getKey())->value('current_version');

        $this->send($order, TourParticipation::Active, version: 1)->assertOk();
        $this->send($order, TourParticipation::Ended, version: 2)->assertOk();
        $this->send($order, TourParticipation::Active, version: 3)->assertOk();

        // §3.21.10 — the loop guard, kept by ownership rather than by
        // idempotency. No number can break a cycle in which every lap is a
        // genuinely new event.
        $this->assertSame($outboxBefore, IntegrationOutbox::query()->count());
        $this->assertSame(
            $versionBefore,
            OrderIntegrationState::query()
                ->where('delivery_order_id', $order->getKey())->value('current_version'),
        );
    }

    public function test_a_completed_order_keeps_its_own_result_when_masar_announces_participation(): void
    {
        $order = $this->assignedOrder();

        app(DeliveryOrderLifecycleService::class)
            ->complete($order, DeliveryOrderResult::Delivered);

        $this->send($order->fresh(), TourParticipation::Active)->assertOk();

        $order->refresh();

        $this->assertSame(DeliveryOrderResult::Delivered, $order->result);
        $this->assertSame(DeliveryOrderStatus::Completed, $order->status);
        $this->assertSame(TourParticipation::Active, $order->masar_participation);
    }

    // ------------------------------------------------------------ event log

    public function test_the_event_log_carries_only_this_channels_version(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order, TourParticipation::Active, version: 7);
        $this->sendPayload($payload)->assertOk();

        $event = MasarIntegrationEvent::query()->where('event_id', $payload['event_id'])->firstOrFail();

        $this->assertSame(7, (int) $event->participation_version);
        $this->assertSame(1, (int) $event->base_order_version);

        // §13.12 — a zero in any of these would claim this event had said
        // something about a channel it said nothing about.
        $this->assertNull($event->status_version);
        $this->assertNull($event->data_version);
        $this->assertNull($event->location_version);
        $this->assertNull($event->masar_note_id);
    }

    // ------------------------------------------------------------- contract

    public function test_the_pinned_envelope_is_accepted_and_hashes_stably(): void
    {
        $order = $this->assignedOrder();

        $pinned = [
            'contract_version' => '1.0',
            'event_id' => '0192f3a1-7c44-7b6e-9f21-4ad2c7e81b03',
            'event_type' => 'order.tour.participation.updated',
            'occurred_at' => '2026-10-08T07:12:04Z',
            'data' => [
                'order_id' => (string) $order->integration_uid,
                'participation_version' => 1,
                'participation' => 'active',
                'base_order_version' => 1,
                'external_courier_id' => (string) $order->representative->integration_uid,
                'tour_reference' => '4120',
                'tour_departure_at' => '2026-10-08T07:30:00Z',
            ],
        ];

        $this->sendPayload($pinned)->assertOk()->assertJsonPath('status', 'processed');

        // Key order on the wire must not change the identity of an event: the
        // digest is taken over a recursively key-sorted structure, and this is
        // what makes "the same event_id with the same payload" mean the same
        // thing on both sides.
        $shuffled = $pinned;
        $shuffled['data'] = array_reverse($shuffled['data'], true);

        $this->assertSame(
            MasarTourParticipationEnvelope::hash($pinned),
            MasarTourParticipationEnvelope::hash($shuffled),
        );

        // The cross-system pin. Masar's sender asserts this same digest against
        // the same literal payload in its own suite
        // (TourParticipationOutboundTest), so a drift in either end's
        // canonicalisation fails a test rather than becoming a 409 in
        // production. Computed over the contract's own literal values rather
        // than this fixture's generated uids, which is what makes it comparable
        // across two databases.
        $this->assertSame('b0fe4718f84263d22b9dd80e79861e8ee8dbe89bbdaa731f96de2aada713180b', MasarTourParticipationEnvelope::hash([
            'contract_version' => '1.0',
            'event_id' => '0192f3a1-7c44-7b6e-9f21-4ad2c7e81b03',
            'event_type' => 'order.tour.participation.updated',
            'occurred_at' => '2026-10-08T07:12:04Z',
            'data' => [
                'order_id' => '3f9c1a74-2b55-7c01-8e4d-9a1b2c3d4e5f',
                'participation_version' => 1,
                'participation' => 'active',
                'base_order_version' => 1,
                'external_courier_id' => '7d2e9b10-44a1-7c33-b5e2-0c8f1d6a9e74',
                'tour_reference' => '4120',
                'tour_departure_at' => '2026-10-08T07:30:00Z',
            ],
        ]), 'the receiver digest drifted from the digest the sender pins');

        // And a replay with the keys reordered is therefore a replay, not a
        // conflict.
        $this->sendPayload($shuffled)->assertOk()->assertJsonPath('status', 'already_processed');
    }

    // ------------------------------------------------------------ assertions

    private function assertNoParticipation(DeliveryOrder $order): void
    {
        $order->refresh();

        $this->assertSame(TourParticipation::None, $order->masar_participation);
        $this->assertSame(0, $order->masar_participation_version);
        $this->assertNull($order->masar_participation_base_order_version);
        $this->assertNull($order->masar_participation_started_at);
    }

    private function assertOperational(DeliveryOrder $order, string $expected): void
    {
        $this->assertSame(
            $expected,
            OperationalStatusProjection::operationalFor($order->fresh())->value,
        );
    }

    // --------------------------------------------------------------- helpers

    private function issueToken(string $clientId, string $secret): string
    {
        $response = $this->postJson(self::TOKENS, ['client_id' => $clientId, 'client_secret' => $secret]);

        $response->assertOk()->assertJsonPath('token_type', 'Bearer');

        return $response->json('access_token');
    }

    private function send(
        DeliveryOrder $order,
        TourParticipation $participation = TourParticipation::Active,
        int $version = 1,
    ): TestResponse {
        return $this->sendPayload($this->envelope($order, $participation, $version));
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
        TourParticipation $participation = TourParticipation::Active,
        int $version = 1,
        ?string $externalOrderId = null,
        ?int $baseOrderVersion = 1,
    ): array {
        $instant = Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'contract_version' => MasarTourParticipationEnvelope::CONTRACT_VERSION,
            'event_id' => (string) Str::uuid(),
            'event_type' => MasarTourParticipationEnvelope::EVENT_TYPE,
            'occurred_at' => $instant,
            'data' => [
                'order_id' => $externalOrderId ?? (string) $order?->integration_uid,
                'participation_version' => $version,
                'participation' => $participation->value,
                'base_order_version' => $baseOrderVersion,
                'external_courier_id' => (string) ($order?->representative?->integration_uid ?? Str::uuid7()),
                'tour_reference' => '4120',
                'tour_departure_at' => Carbon::now()->utc()->addMinutes(18)->format('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }

    private function assignedOrder(): DeliveryOrder
    {
        $customer = Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('091', true),
        ]);

        $representative = Representative::create([
            'name' => 'Representative '.uniqid(),
            'phone' => '0920000007',
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
