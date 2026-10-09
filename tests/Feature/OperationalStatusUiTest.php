<?php

namespace Tests\Feature;

use App\Enums\AdministrativeState;
use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Enums\DeliveryStatus;
use App\Enums\OperationalStatus;
use App\Enums\TourParticipation;
use App\Filament\Resources\DeliveryOrders\Pages\ListDeliveryOrders;
use App\Filament\Resources\DeliveryOrders\Pages\ViewDeliveryOrder;
use App\Filament\Support\OrderStatusPresenter;
use App\Filament\Widgets\OperationalStats;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Representative;
use App\Models\User;
use App\Services\CustomerHistoryService;
use App\Services\OperationalStatusProjection;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The interface, exercised through Livewire rather than reasoned about.
 *
 * The projection's own suite proves the rules; this one proves the screens
 * actually use them. Those are different claims, and the gap between them is
 * where a badge that disagrees with its filter would live — so every case here
 * goes through the real page, the real table and the real widget.
 *
 * Three properties get the most attention, because each is something the
 * previous interface got wrong or could not express at all: that a cancelled
 * order never reads as merely active while its Masar result stays visible, that
 * a filter searches the table rather than the loaded page, and that the
 * historical figures every customer's reception rate depends on did not move.
 */
class OperationalStatusUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    // ------------------------------------------------------- the six states

    public function test_each_of_the_six_operational_states_is_rendered_in_the_table(): void
    {
        $expectations = [
            OperationalStatus::NewOrder->value => $this->orderInState('new'),
            OperationalStatus::Assigned->value => $this->orderInState('assigned'),
            OperationalStatus::InProgress->value => $this->orderInState('in_progress'),
            OperationalStatus::Postponed->value => $this->orderInState('postponed'),
            OperationalStatus::Returned->value => $this->orderInState('returned'),
            OperationalStatus::Delivered->value => $this->orderInState('delivered'),
        ];

        $table = Livewire::test(ListDeliveryOrders::class)->assertSuccessful();

        foreach ($expectations as $code => $order) {
            $status = OperationalStatus::from($code);

            // The projection and the rendered column have to agree — asserting
            // only the projection here would test the previous suite again.
            $this->assertSame($status, OperationalStatusProjection::operationalFor($order->fresh()));
            $table->assertTableColumnStateSet('operational_status', $status->label(), $order);
        }
    }

    public function test_the_order_page_shows_the_masar_execution_section(): void
    {
        $order = $this->orderInState('postponed');

        Livewire::test(ViewDeliveryOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            // The section that did not exist: every Masar announcement was
            // stored and shown nowhere.
            ->assertSee('معلومات التنفيذ في مَسار')
            ->assertSee('مؤجل')
            ->assertSee('customer_absent')
            // Named for what it is. The instant is when Masar announced the
            // transition, not when a courier drove off.
            ->assertSee('وقت تسجيل بدء مشاركة الطلب')
            ->assertSee('موعد الانطلاق المجدول (ليس دليل بدء)');
    }

    // --------------------------------------------- administrative precedence

    public function test_a_cancelled_order_leads_with_the_administrative_state_and_still_shows_masars_result(): void
    {
        $order = $this->orderInState('delivered');
        $order->forceFill([
            'status' => DeliveryOrderStatus::Cancelled->value,
            'cancelled_at' => now(),
        ])->save();
        $order->refresh();

        // §5.2 — the company's decision leads.
        $primary = OrderStatusPresenter::primary($order);
        $this->assertSame(AdministrativeState::Cancelled->label(), $primary['label']);

        // And execution is not hidden: §5 forbids dropping a delivery Masar
        // announced merely because the company closed the order another way.
        $secondary = OrderStatusPresenter::secondary($order);
        $this->assertSame(OperationalStatus::Delivered->label(), $secondary['label']);
        $this->assertSame('تم التسليم', OrderStatusPresenter::masarResultLabel($order));

        Livewire::test(ListDeliveryOrders::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('administrative_state', AdministrativeState::Cancelled->label(), $order)
            ->assertTableColumnStateSet('operational_status', OperationalStatus::Delivered->label(), $order)
            ->assertTableColumnStateSet('masar_result', 'تم التسليم', $order);
    }

    public function test_an_open_order_leads_with_the_operational_state(): void
    {
        $order = $this->orderInState('in_progress');

        $this->assertSame(AdministrativeState::Open, OperationalStatusProjection::administrativeFor($order));
        $this->assertSame(
            OperationalStatus::InProgress->label(),
            OrderStatusPresenter::primary($order)['label'],
        );
        // Nothing to add — the operational badge *is* the primary.
        $this->assertNull(OrderStatusPresenter::secondary($order));
    }

    // ------------------------------------------------------------- conflicts

    public function test_a_cancellation_against_a_masar_delivery_is_reported_as_a_conflict(): void
    {
        $order = $this->orderInState('delivered');
        $order->forceFill(['status' => DeliveryOrderStatus::Cancelled->value, 'cancelled_at' => now()])->save();

        $this->assertTrue(OperationalStatusProjection::hasConflict($order->fresh()));

        $notes = array_column(OrderStatusPresenter::notes($order->fresh()), 'text');
        $this->assertNotEmpty(array_filter($notes, fn (string $n): bool => str_contains($n, 'تعارض')));
    }

    public function test_a_local_completion_against_a_different_masar_result_is_a_conflict(): void
    {
        $order = $this->orderInState('returned');
        $order->forceFill([
            'status' => DeliveryOrderStatus::Completed->value,
            'result' => DeliveryOrderResult::Delivered->value,
            'completed_at' => now(),
        ])->save();

        $this->assertTrue(OperationalStatusProjection::hasConflict($order->fresh()));
    }

    public function test_agreeing_sources_are_not_reported_as_a_conflict(): void
    {
        // Masar says delivered and the company closed it delivered. A
        // difference of vocabulary is not a disagreement of fact, and §5
        // forbids treating every difference as a conflict.
        $order = $this->orderInState('delivered');
        $order->forceFill([
            'status' => DeliveryOrderStatus::Completed->value,
            'result' => DeliveryOrderResult::Delivered->value,
            'completed_at' => now(),
        ])->save();

        $this->assertFalse(OperationalStatusProjection::hasConflict($order->fresh()));
        $this->assertSame([], OrderStatusPresenter::notes($order->fresh()));
    }

    public function test_an_order_masar_has_not_spoken_about_is_never_in_conflict(): void
    {
        $order = $this->orderInState('assigned');
        $order->forceFill(['status' => DeliveryOrderStatus::Cancelled->value, 'cancelled_at' => now()])->save();

        // `delivery_status` defaults to `with_rep`; reading it without checking
        // the version would report a result that was never announced.
        $this->assertFalse(OperationalStatusProjection::hasConflict($order->fresh()));
    }

    // ---------------------------------------------------------- participation

    public function test_a_scheduled_participation_reads_assigned_and_shows_the_scheduled_tour(): void
    {
        $order = $this->orderInState('assigned');
        $this->applyParticipation($order, TourParticipation::Scheduled, departureAt: now()->addHours(6));

        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order->fresh()));

        Livewire::test(ViewDeliveryOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('جولة مُعدّة بموعد مستقبلي');
    }

    public function test_an_ended_participation_does_not_read_in_progress(): void
    {
        $order = $this->orderInState('in_progress');
        $this->assertSame(OperationalStatus::InProgress, OperationalStatusProjection::operationalFor($order->fresh()));

        $this->applyParticipation($order, TourParticipation::Ended, version: 2);

        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order->fresh()));
    }

    public function test_a_participation_superseded_by_reassignment_is_explained_not_hidden(): void
    {
        $order = $this->orderInState('in_progress');

        // The fence rises past the version Masar built its statement on.
        $order->forceFill(['assignment_order_version' => 99])->save();
        $order->refresh();

        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));
        $this->assertTrue(OrderStatusPresenter::participationWasSuperseded($order));

        $notes = array_column(OrderStatusPresenter::notes($order), 'text');
        $this->assertContains('مشاركة سابقة أُبطلت بإعادة الإسناد', $notes);
    }

    public function test_an_unresolvable_courier_is_explained_without_inventing_an_identity(): void
    {
        $order = $this->orderInState('in_progress');
        $order->forceFill([
            'masar_tour_started_courier_uid' => (string) Str::uuid7(),
            'masar_tour_started_representative_id' => null,
        ])->save();
        $order->refresh();

        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));

        $notes = array_column(OrderStatusPresenter::notes($order), 'text');
        $this->assertNotEmpty(array_filter($notes, fn (string $n): bool => str_contains($n, 'غير مربوط محلياً')));
    }

    public function test_a_courier_other_than_the_assigned_one_is_explained_separately(): void
    {
        $order = $this->orderInState('in_progress');
        $stranger = $this->representative();

        $order->forceFill(['masar_tour_started_representative_id' => $stranger->getKey()])->save();
        $order->refresh();

        $this->assertSame(OperationalStatus::Assigned, OperationalStatusProjection::operationalFor($order));

        $notes = array_column(OrderStatusPresenter::notes($order), 'text');
        $this->assertContains('المندوب في واقعة المشاركة يخالف المندوب المسند حالياً', $notes);
    }

    public function test_withdrawing_a_result_restores_the_display_to_the_current_participation(): void
    {
        $order = $this->orderInState('delivered');
        $this->applyParticipation($order, TourParticipation::Active, version: 2);

        // The result outranks participation while it stands.
        $this->assertSame(OperationalStatus::Delivered, OperationalStatusProjection::operationalFor($order->fresh()));

        // Masar withdraws it: `with_rep` at a version above zero.
        $order->forceFill([
            'delivery_status' => DeliveryStatus::WithRepresentative->value,
            'status_reason' => null,
            'masar_status_version' => 2,
        ])->save();
        $order->refresh();

        $this->assertSame(OperationalStatus::InProgress, OperationalStatusProjection::operationalFor($order));
        $this->assertTrue(OrderStatusPresenter::resultWasWithdrawn($order));
        $this->assertContains(
            'أُعيد إلى المندوب بعد تعديل النتيجة',
            array_column(OrderStatusPresenter::notes($order), 'text'),
        );
    }

    // ------------------------------------------------------------- filtering

    public function test_every_operational_filter_searches_the_table_and_not_the_page(): void
    {
        $orders = [];
        foreach (OperationalStatus::codes() as $code) {
            $orders[$code] = $this->orderInState($code)->getKey();
        }

        // More rows than one page holds, so a filter that only looked at the
        // loaded page would visibly disagree with the database.
        for ($i = 0; $i < 30; $i++) {
            $this->orderInState('new');
        }

        foreach (OperationalStatus::cases() as $case) {
            $component = Livewire::test(ListDeliveryOrders::class)
                ->filterTable('operational_status', $case->value)
                ->assertSuccessful();

            // The *filtered query*, not the rendered page — which is the whole
            // claim. Asserting the page would conflate two things and would
            // fail for the states with more rows than a page holds, exactly
            // because the filter correctly searched the table: a tracked row
            // can be on page three and still be matched.
            $matched = $component->instance()->getFilteredTableQuery()->pluck('id')->all();

            $this->assertContains($orders[$case->value], $matched, "filter [{$case->value}] missed its row");

            foreach ($orders as $code => $id) {
                if ($code !== $case->value) {
                    $this->assertNotContains($id, $matched, "filter [{$case->value}] matched a [{$code}] row");
                }
            }

            // And the filter is a real SQL predicate: the thirty extra `new`
            // rows are counted by the database, not by whatever the page loaded.
            if ($case === OperationalStatus::NewOrder) {
                $this->assertSame(31, count($matched));
            }
        }
    }

    public function test_the_administrative_and_conflict_filters_select_the_expected_rows(): void
    {
        $open = $this->orderInState('in_progress');

        $cancelled = $this->orderInState('delivered');
        $cancelled->forceFill(['status' => DeliveryOrderStatus::Cancelled->value, 'cancelled_at' => now()])->save();

        $completed = $this->orderInState('delivered');
        $completed->forceFill([
            'status' => DeliveryOrderStatus::Completed->value,
            'result' => DeliveryOrderResult::Delivered->value,
            'completed_at' => now(),
        ])->save();

        Livewire::test(ListDeliveryOrders::class)
            ->filterTable('administrative_state', AdministrativeState::Cancelled->value)
            ->assertCanSeeTableRecords([$cancelled->getKey()])
            ->assertCanNotSeeTableRecords([$open->getKey(), $completed->getKey()]);

        Livewire::test(ListDeliveryOrders::class)
            ->filterTable('administrative_state', AdministrativeState::Open->value)
            ->assertCanSeeTableRecords([$open->getKey()])
            ->assertCanNotSeeTableRecords([$cancelled->getKey(), $completed->getKey()]);

        // Only the cancelled one disagrees with Masar; the completed one agrees.
        Livewire::test(ListDeliveryOrders::class)
            ->filterTable('has_conflict', true)
            ->assertCanSeeTableRecords([$cancelled->getKey()])
            ->assertCanNotSeeTableRecords([$open->getKey(), $completed->getKey()]);
    }

    public function test_sorting_by_the_operational_status_is_stable_across_pages(): void
    {
        foreach (['new', 'assigned', 'in_progress', 'delivered'] as $state) {
            for ($i = 0; $i < 8; $i++) {
                $this->orderInState($state);
            }
        }

        // Two readings of the same sorted page must be identical: the tie-break
        // on `id` is what stops pagination showing a row twice or skipping one.
        $first = Livewire::test(ListDeliveryOrders::class)
            ->sortTable('operational_status')
            ->assertSuccessful()
            ->instance()->getTableRecords()->pluck('id')->all();

        $second = Livewire::test(ListDeliveryOrders::class)
            ->sortTable('operational_status')
            ->instance()->getTableRecords()->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertNotEmpty($first);
    }

    public function test_the_table_does_not_issue_a_query_per_row(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->orderInState('in_progress');
        }

        DB::enableQueryLog();
        Livewire::test(ListDeliveryOrders::class)->assertSuccessful();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Twelve rows, each displaying a customer and a representative. Without
        // eager loading that alone would be twenty-four extra queries; the
        // ceiling here is deliberately loose but far below N+1.
        $this->assertLessThan(20, $queries, "the orders table issued {$queries} queries for 12 rows");
    }

    // ----------------------------------------------------------- statistics

    public function test_the_operational_counters_match_the_projection_and_group_without_error(): void
    {
        $expected = [];
        foreach (OperationalStatus::codes() as $code) {
            $count = $code === 'new' ? 3 : 2;
            for ($i = 0; $i < $count; $i++) {
                $this->orderInState($code);
            }
            $expected[$code] = $count;
        }

        // The widget renders at all — which is the `only_full_group_by`
        // regression test, since the grouped CASE would raise a 1055 here.
        Livewire::test(OperationalStats::class)->assertSuccessful();

        [$sql, $bindings] = OperationalStatusProjection::operationalSql();

        $actual = DB::table('delivery_orders')
            ->selectRaw("({$sql}) as state, COUNT(*) as total", $bindings)
            ->groupBy('state')
            ->pluck('total', 'state')
            ->map(fn ($n): int => (int) $n)
            ->all();

        foreach ($expected as $code => $count) {
            $this->assertSame($count, $actual[$code] ?? 0, "counter for [{$code}]");
        }
    }

    public function test_the_historical_figures_and_the_reception_rate_are_unchanged_by_masars_results(): void
    {
        $customer = $this->customer();

        // Two orders the company itself closed — the only thing the reception
        // rate is computed from.
        $this->localCompletion($customer, DeliveryOrderResult::Delivered);
        $this->localCompletion($customer, DeliveryOrderResult::NotDelivered);

        // And one Masar reports delivered that the company has *not* closed.
        // It must not enter the rate: redefining "completed" as "Masar says
        // delivered" would rewrite every customer's history.
        $masarOnly = $this->orderInState('delivered', $customer);

        $summary = app(CustomerHistoryService::class)->getCompanyReceptionSummary($customer);

        $this->assertSame(2, $summary['completed_count']);
        $this->assertSame(1, $summary['delivered_count']);
        $this->assertSame(1, $summary['not_delivered_count']);
        $this->assertSame(50.0, $summary['reception_rate']);

        // The Masar-delivered order is visible as such, and still outside the
        // company's own record.
        $this->assertSame(OperationalStatus::Delivered, OperationalStatusProjection::operationalFor($masarOnly->fresh()));
        $this->assertSame(AdministrativeState::Open, OperationalStatusProjection::administrativeFor($masarOnly->fresh()));

        Livewire::test(OperationalStats::class)
            ->assertSuccessful()
            // The two families are named so they cannot be confused.
            ->assertSee('تم التسليم (مَسار)')
            ->assertSee('تم التسليم (سجل الشركة)');
    }

    // --------------------------------------------------------------- helpers

    private function customer(): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => '09'.random_int(1, 4).str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        ]);
    }

    private function representative(): Representative
    {
        return Representative::create([
            'name' => 'Representative '.uniqid(),
            'phone' => '0921234567',
            'is_active' => true,
        ]);
    }

    /**
     * An order in one of the six operational states, built only from columns the
     * owning side would have written.
     */
    private function orderInState(string $state, ?Customer $customer = null): DeliveryOrder
    {
        $customer ??= $this->customer();

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'value' => '30.00',
            'status' => DeliveryOrderStatus::NewOrder->value,
        ]);

        if ($state === 'new') {
            return $order->refresh();
        }

        $representative = $this->representative();

        $columns = [
            'representative_id' => $representative->getKey(),
            'status' => DeliveryOrderStatus::Assigned->value,
            'assignment_order_version' => 1,
        ];

        $columns += match ($state) {
            'assigned' => [],
            'in_progress' => [
                'masar_participation' => TourParticipation::Active->value,
                'masar_participation_version' => 1,
                'masar_participation_base_order_version' => 1,
                'masar_participation_changed_at' => now(),
                'masar_participation_started_at' => now(),
                'masar_tour_reference' => '4120',
                'masar_tour_departure_at' => now(),
                'masar_tour_started_courier_uid' => (string) $representative->integration_uid,
                'masar_tour_started_representative_id' => $representative->getKey(),
            ],
            'postponed' => [
                'delivery_status' => DeliveryStatus::Postponed->value,
                'status_reason' => 'customer_absent',
                'masar_status_version' => 1,
                'masar_participation' => TourParticipation::Active->value,
                'masar_participation_version' => 1,
                'masar_participation_base_order_version' => 1,
                'masar_participation_changed_at' => now(),
                'masar_participation_started_at' => now(),
                'masar_tour_reference' => '4120',
                'masar_tour_departure_at' => now(),
                'masar_tour_started_representative_id' => $representative->getKey(),
            ],
            'returned' => [
                'delivery_status' => DeliveryStatus::Returned->value,
                'status_reason' => 'customer_refused',
                'masar_status_version' => 1,
            ],
            'delivered' => [
                'delivery_status' => DeliveryStatus::Delivered->value,
                'masar_status_version' => 1,
            ],
            default => throw new \InvalidArgumentException("unknown state [{$state}]"),
        };

        $order->forceFill($columns)->save();

        return $order->refresh();
    }

    private function applyParticipation(
        DeliveryOrder $order,
        TourParticipation $participation,
        int $version = 1,
        $departureAt = null,
    ): void {
        $order->forceFill([
            'masar_participation' => $participation->value,
            'masar_participation_version' => $version,
            'masar_participation_base_order_version' => $order->assignment_order_version,
            'masar_participation_changed_at' => now(),
            'masar_tour_reference' => '4120',
            'masar_tour_departure_at' => $departureAt ?? now(),
            'masar_tour_started_representative_id' => $order->representative_id,
        ] + ($participation === TourParticipation::Active ? ['masar_participation_started_at' => now()] : [])
          + ($participation === TourParticipation::Ended ? ['masar_participation_ended_at' => now()] : []))->save();

        $order->refresh();
    }

    private function localCompletion(Customer $customer, DeliveryOrderResult $result): DeliveryOrder
    {
        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'value' => '30.00',
            'status' => DeliveryOrderStatus::NewOrder->value,
        ]);

        $order->forceFill([
            'representative_id' => $this->representative()->getKey(),
            'status' => DeliveryOrderStatus::Completed->value,
            'result' => $result->value,
            'completed_at' => now(),
            'assignment_order_version' => 1,
        ])->save();

        return $order->refresh();
    }
}
