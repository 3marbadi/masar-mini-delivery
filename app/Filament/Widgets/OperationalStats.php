<?php

namespace App\Filament\Widgets;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Resources\Representatives\RepresentativeResource;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Representative;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationalStats extends StatsOverviewWidget
{
    protected ?string $heading = 'الإحصائيات التشغيلية';

    protected function getStats(): array
    {
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

        $ordersUrl = DeliveryOrderResource::getUrl('index');

        return [
            Stat::make('إجمالي الطلبات', (int) ($orders->total_count ?? 0))->url($ordersUrl),
            Stat::make('طلبات جديدة', (int) ($orders->new_count ?? 0))->color('warning')->url($ordersUrl),
            Stat::make('طلبات مُسندة', (int) ($orders->assigned_count ?? 0))->color('info')->url($ordersUrl),
            Stat::make('طلبات مكتملة', (int) ($orders->completed_count ?? 0))->color('success')->url($ordersUrl),
            Stat::make('تم التسليم', (int) ($orders->delivered_count ?? 0))->color('success')->url($ordersUrl),
            Stat::make('لم يتم التسليم', (int) ($orders->not_delivered_count ?? 0))->color('danger')->url($ordersUrl),
            Stat::make('طلبات ملغاة', (int) ($orders->cancelled_count ?? 0))->color('gray')->url($ordersUrl),
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
}
