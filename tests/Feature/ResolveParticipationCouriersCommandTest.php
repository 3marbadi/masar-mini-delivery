<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\OperationalStatus;
use App\Enums\TourParticipation;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use App\Models\IntegrationOutbox;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\Integration\MasarTourParticipationEnvelope;
use App\Services\OperationalStatusProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Repairing a courier mapping after the event has already been applied.
 *
 * The scenario end to end: Masar announces that an order joined a started tour
 * and names a courier this database cannot match; the receiver keeps the
 * statement and the uid but leaves the local reference null; the projection
 * declines to show the order as being delivered by a courier it cannot name;
 * the mapping is then repaired, and this command fills the reference in.
 *
 * Two things it must not do, both tested here. It must not touch idempotency —
 * no event is minted, the participation version does not move, and a replay of
 * the original event still answers `already_processed`. And it must not become a
 * way round the guards: filling the reference in satisfies the courier check and
 * nothing else, so an order whose participation a reassignment has superseded
 * stays assigned after a successful repair.
 */
class ResolveParticipationCouriersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTS = '/api/v1/integration/events';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 07:12:04');

        MasarIntegrationClient::create([
            'name' => 'Masar',
            'client_id' => 'masar',
            'client_secret_hash' => Hash::make('masar-secret'),
            'status' => 'active',
        ]);

        $this->token = $this->postJson('/api/v1/integration/auth/token', [
            'client_id' => 'masar', 'client_secret' => 'masar-secret',
        ])->json('access_token');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_whole_repair_cycle_from_unmapped_courier_to_in_progress(): void
    {
        $order = $this->assignedOrder();
        $uid = (string) Str::uuid7();

        // 1. `active` arrives naming a courier this database does not know.
        $payload = $this->participationPayload($order, $uid);
        $this->send($payload)->assertOk()->assertJsonPath('status', 'processed');

        $order->refresh();
        $this->assertSame($uid, $order->masar_tour_started_courier_uid);
        $this->assertNull($order->masar_tour_started_representative_id);

        // 2. So it reads assigned, not in progress.
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));

        // 3. The mapping is repaired: the courier holding this order turns out
        //    to be the one Masar named.
        $order->representative->forceFill(['integration_uid' => $uid])->save();

        // Still assigned — the stored reference is what the projection reads,
        // and nothing has filled it in yet.
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order->fresh()));

        // 4. The repair runs.
        $this->artisan('masar:resolve-participation-couriers', ['--apply' => true])
            ->assertSuccessful();

        // 5. And now it reads in progress, because the participation is still
        //    valid for the current assignment.
        $order->refresh();
        $this->assertSame($order->representative_id, $order->masar_tour_started_representative_id);
        $this->assertSame(OperationalStatus::InProgress, OperationalStatusProjection::operationalFor($order));

        // 6. Idempotency untouched: the version did not move, the event log was
        //    not rewritten, and the original event still replays as processed.
        $this->assertSame(1, $order->masar_participation_version);
        $this->assertSame(1, MasarIntegrationEvent::query()->where('event_id', $payload['event_id'])->count());
        $this->send($payload)->assertOk()->assertJsonPath('status', 'already_processed');
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $order = $this->assignedOrder();
        $uid = (string) Str::uuid7();

        $this->send($this->participationPayload($order, $uid))->assertOk();
        $order->representative->forceFill(['integration_uid' => $uid])->save();

        // The default, with no --apply.
        $this->artisan('masar:resolve-participation-couriers')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertNull($order->fresh()->masar_tour_started_representative_id);
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order->fresh()));
    }

    public function test_running_it_again_changes_nothing_further(): void
    {
        $order = $this->assignedOrder();
        $uid = (string) Str::uuid7();

        $this->send($this->participationPayload($order, $uid))->assertOk();
        $order->representative->forceFill(['integration_uid' => $uid])->save();

        $this->artisan('masar:resolve-participation-couriers', ['--apply' => true])->assertSuccessful();

        $after = $order->fresh();
        $touchedAt = $after->updated_at;

        // The second pass finds no candidates at all: the selection is
        // "a uid recorded and no local row matched", and that is no longer true.
        $this->artisan('masar:resolve-participation-couriers', ['--apply' => true])->assertSuccessful();

        $again = $order->fresh();
        $this->assertSame($after->masar_tour_started_representative_id, $again->masar_tour_started_representative_id);
        $this->assertSame(1, $again->masar_participation_version);
        $this->assertEquals($touchedAt, $again->updated_at);
    }

    public function test_an_unmapped_uid_is_reported_and_left_alone(): void
    {
        $order = $this->assignedOrder();

        // The mapping is never repaired, so there is nothing to resolve.
        $this->send($this->participationPayload($order, (string) Str::uuid7()))->assertOk();

        $this->artisan('masar:resolve-participation-couriers', ['--apply' => true])
            ->assertSuccessful();

        $this->assertNull($order->fresh()->masar_tour_started_representative_id);
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order->fresh()));
    }

    public function test_a_conflicting_resolution_is_refused_and_reported_as_a_failure(): void
    {
        $order = $this->assignedOrder();
        $uid = (string) Str::uuid7();

        $this->send($this->participationPayload($order, $uid))->assertOk();

        // The recorded reference points at the order's own courier, while the
        // uid Masar sent now names somebody else — the mapping was repaired to
        // point elsewhere, or the uid was reassigned. Either way, choosing
        // between two couriers is not a decision a repair tool gets to make.
        Representative::create([
            'name' => 'آخر', 'phone' => '0941111111', 'is_active' => true,
        ])->forceFill(['integration_uid' => $uid])->save();

        $order->forceFill(['masar_tour_started_representative_id' => $order->representative_id])->save();

        $this->artisan('masar:resolve-participation-couriers', ['--apply' => true])
            ->assertFailed();

        // Left exactly as it was.
        $this->assertSame($order->representative_id, $order->fresh()->masar_tour_started_representative_id);
    }

    public function test_the_repair_does_not_bypass_the_assignment_fence(): void
    {
        $order = $this->assignedOrder();
        $uid = (string) Str::uuid7();

        $this->send($this->participationPayload($order, $uid))->assertOk();

        // A reassignment supersedes the participation: the fence rises past the
        // version Masar built its statement on.
        $other = Representative::create([
            'name' => 'مندوب ثانٍ', 'phone' => '0932222222', 'is_active' => true,
        ]);
        $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $other);
        $other->forceFill(['integration_uid' => $uid])->save();

        $this->artisan('masar:resolve-participation-couriers', ['--apply' => true])->assertSuccessful();

        $order->refresh();

        // The reference was filled in — the courier check now passes — and the
        // order is *still* assigned, because the fence is a separate condition
        // and the repair does not touch it. Filling in an identity is not a way
        // round a superseded statement.
        $this->assertSame($other->getKey(), $order->masar_tour_started_representative_id);
        $this->assertTrue(OperationalStatusProjection::participationCourierMatchesFor($order));
        $this->assertFalse(OperationalStatusProjection::participationValidFor($order));
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));
    }

    public function test_the_repair_writes_no_other_column_and_raises_no_outbound_event(): void
    {
        $order = $this->assignedOrder();
        $uid = (string) Str::uuid7();

        $this->send($this->participationPayload($order, $uid))->assertOk();
        $order->representative->forceFill(['integration_uid' => $uid])->save();

        $before = $order->fresh();
        $outboxBefore = IntegrationOutbox::query()->count();
        $versionBefore = (int) $before->integrationState->current_version;

        $this->artisan('masar:resolve-participation-couriers', ['--apply' => true])->assertSuccessful();

        $after = $order->fresh();

        // The one column it may write.
        $this->assertNotSame($before->masar_tour_started_representative_id, $after->masar_tour_started_representative_id);

        // And nothing else — not the participation, not its version, not the
        // uid, not the result, not the lifecycle.
        foreach ([
            'masar_participation', 'masar_participation_version', 'masar_participation_base_order_version',
            'masar_tour_started_courier_uid', 'delivery_status', 'status_reason', 'masar_status_version',
            'status', 'result', 'completed_at', 'cancelled_at', 'representative_id', 'assignment_order_version',
        ] as $column) {
            $this->assertEquals(
                $before->getAttribute($column),
                $after->getAttribute($column),
                "the repair changed [{$column}]",
            );
        }

        // No outbound event, no outbox row, no movement of the outbound
        // sequence: the repair asks Masar nothing and tells it nothing.
        $this->assertSame($outboxBefore, IntegrationOutbox::query()->count());
        $this->assertSame($versionBefore, (int) $after->integrationState->current_version);
    }

    public function test_a_newer_statement_arriving_first_is_not_repaired_with_the_old_uid(): void
    {
        $order = $this->assignedOrder();
        $firstUid = (string) Str::uuid7();
        $secondUid = (string) Str::uuid7();

        $this->send($this->participationPayload($order, $firstUid))->assertOk();

        // A newer participation statement arrives carrying a different courier,
        // which replaces the recorded uid.
        $this->send($this->participationPayload($order, $secondUid, version: 2))->assertOk();

        // The *old* uid is now mapped. The repair must resolve what is recorded
        // now, not what was recorded when the problem was first noticed.
        Representative::create([
            'name' => 'مندوب قديم', 'phone' => '0933333333', 'is_active' => true,
        ])->forceFill(['integration_uid' => $firstUid])->save();

        $this->artisan('masar:resolve-participation-couriers', ['--apply' => true])->assertSuccessful();

        $order->refresh();

        $this->assertSame($secondUid, $order->masar_tour_started_courier_uid);
        $this->assertNull($order->masar_tour_started_representative_id);
        $this->assertSame(2, $order->masar_participation_version);
    }

    // --------------------------------------------------------------- helpers

    /** @param array<string, mixed> $payload */
    private function send(array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson(self::EVENTS, $payload);
    }

    /** @return array<string, mixed> */
    private function participationPayload(DeliveryOrder $order, string $courierUid, int $version = 1): array
    {
        $instant = Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'contract_version' => MasarTourParticipationEnvelope::CONTRACT_VERSION,
            'event_id' => (string) Str::uuid(),
            'event_type' => MasarTourParticipationEnvelope::EVENT_TYPE,
            'occurred_at' => $instant,
            'data' => [
                'order_id' => (string) $order->integration_uid,
                'participation_version' => $version,
                'participation' => TourParticipation::Active->value,
                'base_order_version' => (int) $order->assignment_order_version,
                'external_courier_id' => $courierUid,
                'tour_reference' => '4120',
                'tour_departure_at' => $instant,
            ],
        ];
    }

    private function assignedOrder(): DeliveryOrder
    {
        $customer = Customer::create(['name' => 'عميل', 'phone' => '0912345678']);
        $representative = Representative::create([
            'name' => 'مندوب', 'phone' => '0921234567', 'is_active' => true,
        ]);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder->value,
        ]);

        return app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $representative);
    }
}
