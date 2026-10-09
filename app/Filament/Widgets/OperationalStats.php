<?php

namespace App\Filament\Widgets;

use App\Enums\AdministrativeState;
use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Enums\OperationalStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Resources\Representatives\RepresentativeResource;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Representative;
use App\Services\OperationalStatusProjection;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's figures, in two clearly separated families.
 *
 * **The historical family is unchanged, deliberately and down to the SQL.** The
 * `status` counters and the two `result` counters below are the same expressions
 * this widget has always run. They are what `reception_rate` and every
 * completion figure are computed from, and redefining "completed" as "Masar
 * says delivered" would silently rewrite every customer's history — and, with
 * it, the value this company sends Masar in every outbound envelope. So they
 * stay, and they keep their own names.
 *
 * **The operational family is new, and is labelled so it cannot be mistaken for
 * the other.** «طلبات مكتملة (محلياً)» and «تم التسليم (مَسار)» are different
 * questions with different answers, and an order can legitimately be one and
 * not the other. Wherever two figures could be confused, each says whose fact
 * it is.
 *
 * Both families are counted in the database rather than in PHP, and the
 * operational one through the same rule table the badges and filters use — so a
 * figure here and a filtered list there cannot disagree.
 */
class OperationalStats extends StatsOverviewWidget
{
    protected ?string $heading = 'الإحصائيات التشغيلية';

    protected function getStats(): array
    {
        // ---- unchanged: the company's own lifecycle ------------------------
        $orders = DeliveryOrder::query()
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(status = ?) as new_count', [DeliveryOrderStatus::NewOrder->value])
            ->selectRaw('SUM(status = ?) as assigned_count', [DeliveryOrderStatus::Assigned->value])
            ->selectRaw('SUM(status = ?) as completed_count', [DeliveryOrderStatus::Completed->value])
            ->selectRaw('SUM(status = ? AND result = ?) as delivered_count', [
                DeliveryOrderStatus::Completed->value,
                DeliveryOrderResult::Delivered->value,
            ])
            ->selectRaw('SUM(status = ? AND result = ?) as not_delivered_count', [
                DeliveryOrderStatus::Completed->value,
                DeliveryOrderResult::NotDelivered->value,
            ])
            ->selectRaw('SUM(status = ?) as cancelled_count', [DeliveryOrderStatus::Cancelled->value])
            ->toBase()
            ->first();

        $operational = self::tally(...OperationalStatusProjection::operationalSql());
        $administrative = self::tally(...OperationalStatusProjection::administrativeSql());
        $conflicts = self::conflictCount();

        $ordersUrl = DeliveryOrderResource::getUrl('index');

        return [
            Stat::make('إجمالي الطلبات', (int) ($orders->total_count ?? 0))->url($ordersUrl),

            // ---- the six operational states ----
            Stat::make('جديدة', $operational[OperationalStatus::NewOrder->value] ?? 0)
                ->description('لم تُسند إلى مندوب')->color('gray')->url($ordersUrl),
            Stat::make('مسندة', $operational[OperationalStatus::Assigned->value] ?? 0)
                ->description('مُسندة ولم يبدأ تنفيذها')->color('info')->url($ordersUrl),
            Stat::make('جاري التوصيل', $operational[OperationalStatus::InProgress->value] ?? 0)
                ->description('ضمن جولة بدأت، بلا نتيجة بعد')->color('primary')->url($ordersUrl),
            Stat::make('مؤجل', $operational[OperationalStatus::Postponed->value] ?? 0)
                ->description('تأجيل أعلنه مَسار')->color('warning')->url($ordersUrl),
            Stat::make('راجع', $operational[OperationalStatus::Returned->value] ?? 0)
                ->description('رجوع أعلنه مَسار')->color('danger')->url($ordersUrl),
            Stat::make('تم التسليم (مَسار)', $operational[OperationalStatus::Delivered->value] ?? 0)
                ->description('نتيجة تسليم قائمة من مَسار')->color('success')->url($ordersUrl),

            // ---- the administrative dimension ----
            Stat::make('ملغي إدارياً', $administrative[AdministrativeState::Cancelled->value] ?? 0)
                ->description('سحبته شركة التوصيل')->color('danger')->url($ordersUrl),
            Stat::make('مكتمل محلياً', $administrative[AdministrativeState::CompletedLocally->value] ?? 0)
                ->description('أغلقته شركة التوصيل بنتيجتها')->color('success')->url($ordersUrl),
            Stat::make('تعارض بين المصدرين', $conflicts)
                ->description('قرار الشركة يخالف نتيجة مَسار')
                ->color($conflicts > 0 ? 'danger' : 'gray')
                ->url($ordersUrl),

            // ---- the unchanged historical figures, named as such ----
            Stat::make('طلبات جديدة (سجل الشركة)', (int) ($orders->new_count ?? 0))->color('warning')->url($ordersUrl),
            Stat::make('طلبات مُسندة (سجل الشركة)', (int) ($orders->assigned_count ?? 0))->color('info')->url($ordersUrl),
            Stat::make('طلبات مكتملة (محلياً)', (int) ($orders->completed_count ?? 0))->color('success')->url($ordersUrl),
            Stat::make('تم التسليم (سجل الشركة)', (int) ($orders->delivered_count ?? 0))
                ->description('يدخل في معدل الاستلام')->color('success')->url($ordersUrl),
            Stat::make('لم يتم التسليم (سجل الشركة)', (int) ($orders->not_delivered_count ?? 0))->color('danger')->url($ordersUrl),
            Stat::make('طلبات ملغاة (سجل الشركة)', (int) ($orders->cancelled_count ?? 0))->color('gray')->url($ordersUrl),

            Stat::make(
                'المندوبون النشطون',
                Representative::query()->where('is_active', true)->count(),
            )->url(RepresentativeResource::getUrl('index')),
            Stat::make(
                'العملاء النشطون',
                Customer::query()->where('is_active', true)->count(),
            )->url(CustomerResource::getUrl('index')),
        ];
    }

    /**
     * Count the orders in each value of one projection, in a single query.
     *
     * **Grouped by the select alias, and that is a MySQL requirement rather
     * than a style choice.** Repeating the `CASE` in the `GROUP BY` fails under
     * `only_full_group_by`, which this server has enabled: two textually
     * identical expressions built from bound placeholders are not matched as
     * equal, so the select list is reported as non-aggregated. The alias is
     * both accepted and cheaper, and the stage 3 equivalence test pins the same
     * shape.
     *
     * @param  list<scalar>  $bindings
     * @return array<string, int>
     */
    private static function tally(string $sql, array $bindings): array
    {
        return DB::table('delivery_orders')
            ->selectRaw("({$sql}) as state, COUNT(*) as total", $bindings)
            ->groupBy('state')
            ->pluck('total', 'state')
            ->map(static fn ($total): int => (int) $total)
            ->all();
    }

    private static function conflictCount(): int
    {
        [$sql, $bindings] = OperationalStatusProjection::conflictSql();

        return (int) DB::table('delivery_orders')
            ->whereRaw("({$sql}) = '1'", $bindings)
            ->count();
    }
}
