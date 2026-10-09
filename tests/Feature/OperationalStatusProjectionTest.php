<?php

namespace Tests\Feature;

use App\Enums\AdministrativeState;
use App\Enums\DeliveryOrderStatus;
use App\Enums\OperationalStatus;
use App\Enums\TourParticipation;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\Integration\MasarTourParticipationEnvelope;
use App\Services\OperationalStatusProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The projection, and the two properties the whole design rests on.
 *
 * **One: PHP and SQL cannot disagree.** The badge an operator reads, the filter
 * that found the row and the number in the dashboard are three renderings of one
 * rule table, and a drift between them would be the least debuggable bug this
 * feature could have. So every combination is written to the database, read back
 * through the compiled `CASE`, evaluated again in PHP, and both are compared
 * against a third answer written longhand in this file — an independent
 * reimplementation that never calls the class under test. Two execution engines
 * and a hand-written oracle have to agree on all twelve hundred rows.
 *
 * **Two: a superseded participation fact can never come back to life.** Masar's
 * `ended` may be delayed, refused or lost for good, and the order must still
 * stop reading `in_progress` the moment it is reassigned — including when it
 * comes back to the courier who started the tour. That is the one scenario the
 * previous design got wrong, and it is tested here end to end through the real
 * services and the real endpoint rather than by constructing a row.
 */
class OperationalStatusProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTS = '/api/v1/integration/events';

    private const TOKENS = '/api/v1/integration/auth/token';

    /** (status, result, has a representative) */
    private const LIFECYCLES = [
        ['new', null, false],
        ['assigned', null, true],
        ['completed', 'delivered', true],
        ['completed', 'not_delivered', true],
        ['cancelled', null, true],
    ];

    /**
     * (delivery_status, masar_status_version, status_reason).
     *
     * Five pairs rather than twenty, because the rest cannot exist: the writer
     * moves the status and its version together, so a non-`with_rep` value at
     * version zero is unreachable, and `delivery_orders_status_reason_check`
     * binds a reason to the two states that carry one.
     */
    private const EXECUTIONS = [
        ['with_rep', 0, null],
        ['with_rep', 1, null],
        ['delivered', 1, null],
        ['postponed', 1, 'customer_absent'],
        ['returned', 1, 'customer_refused'],
    ];

    /** (base_order_version, assignment_order_version) — null, behind, level, ahead. */
    private const FENCES = [
        [null, 3],
        [2, 3],
        [3, 3],
        [4, 3],
    ];

    /**
     * Which courier Masar said began the participation.
     *
     * The second guard, and orthogonal to the fence: `other` and `none` both
     * pass every fence and must still never read `in_progress`.
     */
    private const COURIERS = ['match', 'other', 'none'];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 07:12:04');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------- the equivalence

    public function test_php_and_sql_agree_with_a_hand_written_oracle_on_every_combination(): void
    {
        $rows = $this->seedMatrix();

        $this->assertCount(1200, $rows);

        [$operationalSql, $operationalBindings] = OperationalStatusProjection::operationalSql();
        [$administrativeSql, $administrativeBindings] = OperationalStatusProjection::administrativeSql();
        [$conflictSql, $conflictBindings] = OperationalStatusProjection::conflictSql();

        // One statement for four hundred rows and three expressions. Asking per
        // row would be twelve hundred queries, and the point is the agreement
        // rather than the round trips.
        $compiled = DB::table('delivery_orders')
            ->selectRaw(
                "id, ({$operationalSql}) as op, ({$administrativeSql}) as admin, ({$conflictSql}) as conflict",
                array_merge($operationalBindings, $administrativeBindings, $conflictBindings),
            )
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $models = DeliveryOrder::query()->orderBy('id')->get()->keyBy('id');

        foreach ($rows as $row) {
            $id = $row['id'];
            $label = $this->describe($row);

            $expectedOperational = $this->oracleOperational($row);
            $expectedAdministrative = $this->oracleAdministrative($row);
            $expectedConflict = $this->oracleConflict($row);

            // SQL against the oracle.
            $this->assertSame($expectedOperational, $compiled[$id]->op, "SQL operational, {$label}");
            $this->assertSame($expectedAdministrative, $compiled[$id]->admin, "SQL administrative, {$label}");
            $this->assertSame($expectedConflict ? '1' : '0', (string) $compiled[$id]->conflict, "SQL conflict, {$label}");

            // PHP against the oracle — and so, transitively, against SQL.
            $model = $models[$id];

            $this->assertSame(
                $expectedOperational,
                OperationalStatusProjection::operationalFor($model)->value,
                "PHP operational, {$label}",
            );
            $this->assertSame(
                $expectedAdministrative,
                OperationalStatusProjection::administrativeFor($model)->value,
                "PHP administrative, {$label}",
            );
            $this->assertSame(
                $expectedConflict,
                OperationalStatusProjection::hasConflict($model),
                "PHP conflict, {$label}",
            );
        }
    }

    public function test_every_operational_filter_returns_exactly_the_expected_rows(): void
    {
        $rows = $this->seedMatrix();

        [$sql, $bindings] = OperationalStatusProjection::operationalSql();

        foreach (OperationalStatus::cases() as $case) {
            $expected = collect($rows)
                ->filter(fn (array $row): bool => $this->oracleOperational($row) === $case->value)
                ->pluck('id')->sort()->values()->all();

            $actual = DB::table('delivery_orders')
                ->whereRaw("({$sql}) = ?", array_merge($bindings, [$case->value]))
                ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

            $this->assertSame($expected, $actual, "filter [{$case->value}]");
        }

        // Every row is matched by exactly one filter, which is what makes the
        // six a partition rather than six overlapping predicates.
        $this->assertSame(
            1200,
            collect(OperationalStatus::cases())->sum(
                fn (OperationalStatus $case): int => DB::table('delivery_orders')
                    ->whereRaw("({$sql}) = ?", array_merge($bindings, [$case->value]))->count(),
            ),
        );
    }

    public function test_every_administrative_filter_returns_exactly_the_expected_rows(): void
    {
        $rows = $this->seedMatrix();

        [$sql, $bindings] = OperationalStatusProjection::administrativeSql();

        foreach (AdministrativeState::cases() as $case) {
            $expected = collect($rows)
                ->filter(fn (array $row): bool => $this->oracleAdministrative($row) === $case->value)
                ->pluck('id')->sort()->values()->all();

            $actual = DB::table('delivery_orders')
                ->whereRaw("({$sql}) = ?", array_merge($bindings, [$case->value]))
                ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

            $this->assertSame($expected, $actual, "filter [{$case->value}]");
        }
    }

    public function test_the_projection_can_order_and_count_in_sql(): void
    {
        $this->seedMatrix();

        [$sql, $bindings] = OperationalStatusProjection::operationalSql();

        // The two shapes the interface needs beyond filtering: a sortable
        // column and a grouped tally for the dashboard.
        $ordered = DB::table('delivery_orders')
            ->selectRaw("({$sql}) as op", $bindings)
            ->orderByRaw("({$sql}) asc", $bindings)
            ->pluck('op')->all();

        $this->assertSame($ordered, collect($ordered)->sort()->values()->all());

        // Grouped by the select alias, not by a second copy of the expression.
        // MySQL's `only_full_group_by` will not accept the repeated `CASE`: two
        // identical expressions built from bound placeholders are not matched as
        // equal, so it reports the select list as non-aggregated. The alias is
        // both correct and cheaper, and prompt 4's dashboard counters must use
        // the same shape.
        $tally = DB::table('delivery_orders')
            ->selectRaw("({$sql}) as op, COUNT(*) as total", $bindings)
            ->groupBy('op')
            ->pluck('total', 'op')->all();

        $this->assertSame(1200, array_sum($tally));
        $this->assertSame(
            collect(OperationalStatus::codes())->filter(fn (string $code) => isset($tally[$code]))->count(),
            count($tally),
        );
    }

    // ---------------------------------------------------------------- nulls

    public function test_a_null_base_order_version_is_never_operative_in_either_rendering(): void
    {
        $order = $this->rowFor([
            'masar_participation' => 'active',
            'masar_participation_version' => 1,
            'masar_participation_base_order_version' => null,
            'assignment_order_version' => 0,
        ]);

        // The divergence this guard exists for: `NULL >= 0` is unknown in SQL
        // and `null >= 0` is *true* in PHP. Without the interpreter's
        // three-valued rule, an order Masar holds without a mapping would read
        // as validly in progress here and as merely assigned in the filter.
        $this->assertFalse(OperationalStatusProjection::participationValidFor($order));
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));
        $this->assertSame('assigned', $this->compiledOperationalFor($order));
    }

    public function test_an_unassigned_order_with_no_participation_reads_new_in_both_renderings(): void
    {
        $order = $this->rowFor(['status' => 'new', 'representative_id' => null]);

        $this->assertSame(OperationalStatus::NewOrder, OperationalStatusProjection::operationalFor($order));
        $this->assertSame('new', $this->compiledOperationalFor($order));
        $this->assertSame(AdministrativeState::Open, OperationalStatusProjection::administrativeFor($order));
    }

    // ----------------------------------------------------------------- time

    public function test_the_projection_does_not_depend_on_the_current_time(): void
    {
        $order = $this->rowFor([
            'masar_participation' => 'scheduled',
            'masar_participation_version' => 1,
            'masar_participation_base_order_version' => 3,
            'assignment_order_version' => 3,
            'masar_tour_departure_at' => '2026-10-08 14:00:00',
        ]);

        foreach (['2026-10-08 07:00:00', '2026-10-08 14:00:01', '2027-01-01 00:00:00'] as $instant) {
            Carbon::setTestNow($instant);

            $this->assertSame(
                OperationalStatus::Assigned,
                OperationalStatusProjection::operationalFor($order->fresh()),
                "at {$instant}",
            );
            $this->assertSame('assigned', $this->compiledOperationalFor($order->fresh()), "at {$instant}");
        }
    }

    public function test_scheduled_becomes_in_progress_only_by_an_event_never_by_the_clock(): void
    {
        $order = $this->rowFor([
            'masar_participation' => 'scheduled',
            'masar_participation_version' => 1,
            'masar_participation_base_order_version' => 3,
            'assignment_order_version' => 3,
            'masar_tour_departure_at' => '2026-10-08 07:00:00',
        ]);

        // The departure hour has already passed, and that changes nothing.
        Carbon::setTestNow('2026-10-09 12:00:00');
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order->fresh()));

        // Only Masar saying so moves it.
        $order->forceFill([
            'masar_participation' => 'active',
            'masar_participation_version' => 2,
        ])->save();

        $this->assertSame(OperationalStatus::InProgress, OperationalStatusProjection::operationalFor($order->fresh()));
    }

    // -------------------------------------------------------- the precedence

    public function test_a_standing_result_outranks_an_active_participation(): void
    {
        foreach ([['delivered', null, 'delivered'], ['postponed', 'customer_absent', 'postponed'], ['returned', 'customer_refused', 'returned']] as [$deliveryStatus, $reason, $expected]) {
            $order = $this->rowFor([
                'delivery_status' => $deliveryStatus,
                'status_reason' => $reason,
                'masar_status_version' => 1,
                'masar_participation' => 'active',
                'masar_participation_version' => 1,
                'masar_participation_base_order_version' => 3,
                'assignment_order_version' => 3,
            ]);

            // A late `active` arriving after a result cannot move the order back
            // into `in_progress`, and a postponed order admitted to a fresh tour
            // still shows why it is in trouble. One ordering settles both.
            $this->assertSame($expected, OperationalStatusProjection::operationalFor($order)->value);
            $this->assertSame($expected, $this->compiledOperationalFor($order));
            $this->assertTrue(OperationalStatusProjection::participationValidFor($order));
        }
    }

    // ------------------------------------------------------ courier identity

    public function test_an_unresolvable_courier_is_never_in_progress_in_either_rendering(): void
    {
        // The fence passes — this is purely an identity failure — so before the
        // courier clauses existed this read `in_progress` in both renderings.
        $order = $this->rowFor([
            'masar_participation' => 'active',
            'masar_participation_version' => 1,
            'masar_participation_base_order_version' => 5,
            'assignment_order_version' => 5,
            'masar_tour_started_courier_uid' => '0192f3a1-7c44-7b6e-9f21-4ad2c7e81b03',
            'masar_tour_started_representative_id' => null,
        ]);

        $this->assertTrue(OperationalStatusProjection::participationValidFor($order));
        $this->assertFalse(OperationalStatusProjection::participationCourierMatchesFor($order));
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));
        $this->assertSame('assigned', $this->compiledOperationalFor($order));
    }

    public function test_a_courier_other_than_the_assigned_one_is_never_in_progress(): void
    {
        $stranger = Representative::create([
            'name' => 'Stranger', 'phone' => '0941111111', 'is_active' => true,
        ]);

        $order = $this->rowFor([
            'masar_participation' => 'active',
            'masar_participation_version' => 1,
            'masar_participation_base_order_version' => 5,
            'assignment_order_version' => 5,
            'masar_tour_started_representative_id' => $stranger->getKey(),
        ]);

        $this->assertTrue(OperationalStatusProjection::participationValidFor($order));
        $this->assertFalse(OperationalStatusProjection::participationCourierMatchesFor($order));
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));
        $this->assertSame('assigned', $this->compiledOperationalFor($order));
    }

    public function test_an_unassigned_order_is_never_in_progress_however_active_the_participation(): void
    {
        // The sharpest form of the defect: execution claimed for an order with
        // no courier at all. Rule 4 precedes rule 5, so without the courier
        // clauses nothing stopped it.
        $order = $this->rowFor([
            'status' => 'new',
            'representative_id' => null,
            'masar_participation' => 'active',
            'masar_participation_version' => 1,
            'masar_participation_base_order_version' => 5,
            'assignment_order_version' => 5,
            'masar_tour_started_representative_id' => null,
        ]);

        $this->assertSame(OperationalStatus::NewOrder, OperationalStatusProjection::operationalFor($order));
        $this->assertSame('new', $this->compiledOperationalFor($order));
    }

    public function test_the_two_guards_are_independent_and_neither_alone_suffices(): void
    {
        $stranger = Representative::create([
            'name' => 'Stranger', 'phone' => '0942222222', 'is_active' => true,
        ]);

        // Fence only: courier matches, fence overtaken.
        $fenceOnly = $this->rowFor([
            'masar_participation' => 'active',
            'masar_participation_version' => 1,
            'masar_participation_base_order_version' => 1,
            'assignment_order_version' => 9,
        ]);
        $this->assertFalse(OperationalStatusProjection::participationValidFor($fenceOnly));
        $this->assertTrue(OperationalStatusProjection::participationCourierMatchesFor($fenceOnly));
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($fenceOnly));

        // Courier only: fence valid, courier wrong.
        $courierOnly = $this->rowFor([
            'masar_participation' => 'active',
            'masar_participation_version' => 1,
            'masar_participation_base_order_version' => 9,
            'assignment_order_version' => 9,
            'masar_tour_started_representative_id' => $stranger->getKey(),
        ]);
        $this->assertTrue(OperationalStatusProjection::participationValidFor($courierOnly));
        $this->assertFalse(OperationalStatusProjection::participationCourierMatchesFor($courierOnly));
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($courierOnly));

        // Both: the only combination that earns `in_progress`.
        $both = $this->rowFor([
            'masar_participation' => 'active',
            'masar_participation_version' => 1,
            'masar_participation_base_order_version' => 9,
            'assignment_order_version' => 9,
        ]);
        $this->assertTrue(OperationalStatusProjection::participationValidFor($both));
        $this->assertTrue(OperationalStatusProjection::participationCourierMatchesFor($both));
        $this->assertSame(OperationalStatus::InProgress, OperationalStatusProjection::operationalFor($both));
        $this->assertSame('in_progress', $this->compiledOperationalFor($both));
    }

    // ------------------------------------------------- the reassignment fence

    public function test_false_revival_is_impossible_after_a_to_b_to_a_even_with_ended_lost(): void
    {
        $this->seedIntegrationClient();

        [$order, $a, $b] = $this->orderWithTwoRepresentatives();

        // 1. Assigned to A.
        $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $a);
        $this->assertSame(1, $order->assignment_order_version);
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));

        // 2. Masar says the tour began, built on the assignment it had applied.
        $this->sendParticipation($order, TourParticipation::Active, 1, 1)->assertOk();
        $order->refresh();
        $this->assertSame(OperationalStatus::InProgress, OperationalStatusProjection::operationalFor($order));
        $this->assertSame('in_progress', $this->compiledOperationalFor($order));

        // 3. Reassigned to B. The `ended` Masar would raise is now assumed lost
        //    for good — nothing below ever delivers it.
        $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $b);
        $this->assertSame(2, $order->assignment_order_version);
        $this->assertSame(TourParticipation::Active, $order->masar_participation);
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));
        $this->assertSame('assigned', $this->compiledOperationalFor($order));

        // 4. Reassigned back to A, with no new tour and no event of any kind.
        $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $a);
        $this->assertSame(3, $order->assignment_order_version);

        // The courier matches again and the participation row still says
        // `active` — and the order is still only assigned. This is the case the
        // earlier design got wrong.
        $this->assertSame($a->getKey(), $order->representative_id);
        $this->assertSame(TourParticipation::Active, $order->masar_participation);
        $this->assertSame(1, $order->masar_participation_base_order_version);
        $this->assertFalse(OperationalStatusProjection::participationValidFor($order));
        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));
        $this->assertSame('assigned', $this->compiledOperationalFor($order));

        // 5. And only a genuinely new tour — a statement built on the current
        //    assignment — brings it back.
        $this->sendParticipation($order, TourParticipation::Active, 2, 3)->assertOk();
        $order->refresh();
        $this->assertSame(OperationalStatus::InProgress, OperationalStatusProjection::operationalFor($order));
    }

    public function test_many_reassignments_never_let_the_fence_fall(): void
    {
        $this->seedIntegrationClient();

        [$order, $a, $b] = $this->orderWithTwoRepresentatives();

        $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $a);
        $this->sendParticipation($order, TourParticipation::Active, 1, 1)->assertOk();
        $order->refresh();

        $previous = $order->assignment_order_version;

        for ($i = 0; $i < 10; $i++) {
            $target = $i % 2 === 0 ? $b : $a;
            $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $target);

            // Monotonic by construction, which is the whole proof: once the
            // fence passes the version Masar built its statement on, no further
            // assignment can bring it back down.
            $this->assertGreaterThan($previous, $order->assignment_order_version);
            $previous = $order->assignment_order_version;

            $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));
        }
    }

    public function test_an_idempotent_reassignment_to_the_same_courier_does_not_move_the_fence(): void
    {
        $this->seedIntegrationClient();

        [$order, $a] = $this->orderWithTwoRepresentatives();

        $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $a);
        $this->sendParticipation($order, TourParticipation::Active, 1, 1)->assertOk();
        $order->refresh();

        $this->assertSame(OperationalStatus::InProgress, OperationalStatusProjection::operationalFor($order));

        // Nothing was reassigned, so nothing is fenced off. The no-op branch
        // returns before the fence is touched.
        $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $a);

        $this->assertSame(1, $order->assignment_order_version);
        $this->assertSame(OperationalStatus::InProgress, OperationalStatusProjection::operationalFor($order->fresh()));
    }

    public function test_the_fence_is_the_order_version_of_the_assignment_event_itself(): void
    {
        $this->seedIntegrationClient();

        [$order, $a, $b] = $this->orderWithTwoRepresentatives();

        $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $a);
        $this->assertSame(1, $order->assignment_order_version);

        // An unrelated outbound event moves `current_version` and must not move
        // the fence: taking the number from `integrationState` instead of from
        // the assignment event would raise it on changes that are not
        // assignments, and would then discard a perfectly good participation
        // statement.
        app(\App\Services\CustomerUpdateService::class)
            ->update($order->customer, ['name' => 'Renamed '.uniqid()]);

        $order->refresh();
        $this->assertSame(1, $order->assignment_order_version);
        $this->assertGreaterThan(1, (int) $order->integrationState->current_version);

        // The next real reassignment takes its own event's version, whatever
        // the counter has reached.
        $order = app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $b);
        $expected = (int) $order->integrationOutboxEvents()->orderByDesc('order_version')->first()->order_version;

        $this->assertSame($expected, $order->assignment_order_version);
    }

    // ------------------------------------------------------ historical rows

    public function test_rows_predating_the_channel_read_exactly_as_before(): void
    {
        // What the migration leaves behind: no participation, no fence, no
        // backfill. `assignment_order_version = 0` is permissive, and there is
        // no stale participation fact on such a row for it to revive.
        $order = $this->rowFor([
            'status' => 'assigned',
            'masar_participation' => 'none',
            'masar_participation_version' => 0,
            'masar_participation_base_order_version' => null,
            'assignment_order_version' => 0,
        ]);

        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));
        $this->assertSame('assigned', $this->compiledOperationalFor($order));
        $this->assertSame(AdministrativeState::Open, OperationalStatusProjection::administrativeFor($order));
        $this->assertFalse(OperationalStatusProjection::hasConflict($order));

        $completed = $this->rowFor([
            'status' => 'completed',
            'result' => 'delivered',
            'masar_participation' => 'none',
            'masar_participation_base_order_version' => null,
            'assignment_order_version' => 0,
        ]);

        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($completed));
        $this->assertSame(AdministrativeState::CompletedLocally, OperationalStatusProjection::administrativeFor($completed));
        $this->assertFalse(OperationalStatusProjection::hasConflict($completed));
    }

    // --------------------------------------------------------- rule hygiene

    public function test_the_vocabularies_the_rule_table_assumes_are_intact(): void
    {
        OperationalStatusProjection::assertVocabulariesIntact();

        $this->addToAssertionCount(1);
    }

    public function test_an_unsafe_table_alias_is_refused_rather_than_interpolated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OperationalStatusProjection::operationalSql('delivery_orders`; DROP TABLE users; --');
    }

    public function test_the_compiled_expression_binds_every_literal(): void
    {
        [$sql, $bindings] = OperationalStatusProjection::operationalSql();

        // Only identifiers reach the statement. Nothing from the rule table's
        // value side is written into it, so there is no path from a literal to
        // the SQL text.
        foreach (['delivered', 'postponed', 'returned', 'active', 'in_progress', 'assigned', 'new'] as $literal) {
            $this->assertStringNotContainsString("'{$literal}'", $sql);
        }

        $this->assertContains('delivered', $bindings);
        $this->assertContains('active', $bindings);
        $this->assertSame(substr_count($sql, '?'), count($bindings));
    }

    // ---------------------------------------------------------- the oracle

    /**
     * The expected operational status, written longhand and independently.
     *
     * Deliberately does not call `OperationalStatusProjection`: a test that
     * asked the implementation what it should answer would pass for any
     * implementation. This is a second statement of the rule, and its job is to
     * catch an error in the first one's content or ordering, while the
     * PHP-against-SQL comparison catches an error in either interpreter.
     *
     * @param  array<string, mixed>  $row
     */
    private function oracleOperational(array $row): string
    {
        $version = (int) $row['masar_status_version'];
        $deliveryStatus = $row['delivery_status'];

        if ($version >= 1 && $deliveryStatus === 'delivered') {
            return 'delivered';
        }

        if ($version >= 1 && $deliveryStatus === 'postponed') {
            return 'postponed';
        }

        if ($version >= 1 && $deliveryStatus === 'returned') {
            return 'returned';
        }

        $base = $row['masar_participation_base_order_version'];
        $fenced = $base !== null && (int) $base >= (int) $row['assignment_order_version'];

        // The second guard, written out separately so the oracle states the
        // same two independent conditions the rule does.
        $startedBy = $row['masar_tour_started_representative_id'] ?? null;
        $courierHolds = $startedBy !== null
            && $row['representative_id'] !== null
            && (int) $startedBy === (int) $row['representative_id'];

        if ($row['masar_participation'] === 'active' && $fenced && $courierHolds) {
            return 'in_progress';
        }

        return $row['representative_id'] !== null ? 'assigned' : 'new';
    }

    /** @param  array<string, mixed>  $row */
    private function oracleAdministrative(array $row): string
    {
        return match ($row['status']) {
            'cancelled' => 'cancelled',
            'completed' => 'completed_locally',
            default => 'open',
        };
    }

    /** @param  array<string, mixed>  $row */
    private function oracleConflict(array $row): bool
    {
        if ((int) $row['masar_status_version'] < 1) {
            return false;
        }

        $deliveryStatus = $row['delivery_status'];
        $status = $row['status'];
        $result = $row['result'];

        if ($status === 'cancelled' && in_array($deliveryStatus, ['delivered', 'postponed', 'returned'], true)) {
            return true;
        }

        if ($status === 'completed' && $result === 'delivered' && in_array($deliveryStatus, ['postponed', 'returned'], true)) {
            return true;
        }

        return $status === 'completed' && $result === 'not_delivered' && $deliveryStatus === 'delivered';
    }

    // ---------------------------------------------------------------- setup

    /**
     * Twelve hundred rows in one statement — five lifecycles by five execution
     * states by four participations by four fences by three couriers.
     *
     * @return list<array<string, mixed>>
     */
    private function seedMatrix(): array
    {
        $customer = Customer::create(['name' => 'Matrix', 'phone' => '0911234567']);
        $assigned = Representative::create([
            'name' => 'Matrix courier', 'phone' => '0921234567', 'is_active' => true,
        ]);
        $stranger = Representative::create([
            'name' => 'Another courier', 'phone' => '0931234567', 'is_active' => true,
        ]);

        $now = Carbon::now();
        $rows = [];

        foreach (self::LIFECYCLES as [$status, $result, $hasRepresentative]) {
            foreach (self::EXECUTIONS as [$deliveryStatus, $statusVersion, $statusReason]) {
                foreach (TourParticipation::cases() as $participation) {
                    foreach (self::FENCES as [$base, $assignment]) {
                        foreach (self::COURIERS as $courier) {
                            $rows[] = [
                                'integration_uid' => (string) Str::uuid7(),
                                'customer_id' => $customer->id,
                                'representative_id' => $hasRepresentative ? $assigned->id : null,
                                'value' => '25.00',
                                'status' => $status,
                                'result' => $result,
                                'completed_at' => $status === 'completed' ? $now : null,
                                'cancelled_at' => $status === 'cancelled' ? $now : null,
                                'delivery_status' => $deliveryStatus,
                                'status_reason' => $statusReason,
                                'masar_status_version' => $statusVersion,
                                'masar_participation' => $participation->value,
                                'masar_participation_version' => $participation === TourParticipation::None ? 0 : 1,
                                'masar_participation_base_order_version' => $base,
                                'masar_tour_started_representative_id' => match ($courier) {
                                    'match' => $assigned->id,
                                    'other' => $stranger->id,
                                    default => null,
                                },
                                'assignment_order_version' => $assignment,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                }
            }
        }

        DB::table('delivery_orders')->insert($rows);

        $ids = DB::table('delivery_orders')->orderBy('id')->pluck('id')->all();

        foreach ($rows as $index => $row) {
            $rows[$index]['id'] = (int) $ids[$index];
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $overrides */
    private function rowFor(array $overrides): DeliveryOrder
    {
        $customer = Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('091', true),
        ]);

        $representative = Representative::create([
            'name' => 'Representative '.uniqid(),
            'phone' => '0931234567',
            'is_active' => true,
        ]);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'value' => '25.00',
            'status' => DeliveryOrderStatus::Assigned,
        ]);

        // The courier defaults to the one the order is assigned to, so a case
        // that means to isolate the *fence* is not silently failing the courier
        // guard instead. Cases about identity override it explicitly.
        $order->forceFill(array_merge([
            'representative_id' => $representative->id,
            'masar_tour_started_representative_id' => $representative->id,
            'assignment_order_version' => 0,
        ], $overrides))->save();

        return $order->refresh();
    }

    private function compiledOperationalFor(DeliveryOrder $order): string
    {
        [$sql, $bindings] = OperationalStatusProjection::operationalSql();

        // `where('id', ...)` and not `whereKey`: this is the query builder, not
        // the Eloquent one, and `whereKey` there would filter on a column named
        // `key`.
        return (string) DB::table('delivery_orders')
            ->where('id', $order->getKey())
            ->selectRaw("({$sql}) as op", $bindings)
            ->value('op');
    }

    /** @return array{0: DeliveryOrder, 1: Representative, 2: Representative} */
    private function orderWithTwoRepresentatives(): array
    {
        $customer = Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('091', true),
        ]);

        $a = Representative::create(['name' => 'Courier A', 'phone' => '0911111111', 'is_active' => true]);
        $b = Representative::create(['name' => 'Courier B', 'phone' => '0922222222', 'is_active' => true]);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'value' => '25.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);

        return [$order, $a, $b];
    }

    private function seedIntegrationClient(): void
    {
        MasarIntegrationClient::create([
            'name' => 'Masar',
            'client_id' => 'masar',
            'client_secret_hash' => Hash::make('masar-secret'),
            'status' => 'active',
        ]);

        $response = $this->postJson(self::TOKENS, ['client_id' => 'masar', 'client_secret' => 'masar-secret']);
        $response->assertOk();

        $this->token = $response->json('access_token');
    }

    private function sendParticipation(
        DeliveryOrder $order,
        TourParticipation $participation,
        int $version,
        ?int $baseOrderVersion,
    ): TestResponse {
        $instant = Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');

        return $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson(self::EVENTS, [
            'contract_version' => MasarTourParticipationEnvelope::CONTRACT_VERSION,
            'event_id' => (string) Str::uuid(),
            'event_type' => MasarTourParticipationEnvelope::EVENT_TYPE,
            'occurred_at' => $instant,
            'data' => [
                'order_id' => (string) $order->integration_uid,
                'participation_version' => $version,
                'participation' => $participation->value,
                'base_order_version' => $baseOrderVersion,
                'external_courier_id' => (string) $order->representative->integration_uid,
                'tour_reference' => '4120',
                'tour_departure_at' => $instant,
            ],
        ]);
    }

    /** @param  array<string, mixed>  $row */
    private function describe(array $row): string
    {
        return sprintf(
            'status=%s result=%s rep=%s ds=%s v_s=%s part=%s base=%s fence=%s courier=%s',
            $row['status'],
            $row['result'] ?? 'null',
            $row['representative_id'] === null ? 'null' : (string) $row['representative_id'],
            $row['delivery_status'],
            $row['masar_status_version'],
            $row['masar_participation'],
            $row['masar_participation_base_order_version'] ?? 'null',
            $row['assignment_order_version'],
            $row['masar_tour_started_representative_id'] ?? 'null',
        );
    }
}
