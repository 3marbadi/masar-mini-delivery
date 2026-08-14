<?php

namespace App\Filament\Widgets;

use App\Enums\DeliveryOrderStatus;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Models\DeliveryOrder;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrdersNeedingAttention extends StatsOverviewWidget
{
    protected ?string $heading = 'طلبات تحتاج متابعتك';

    protected ?string $description = 'الطلبات الجديدة التي لم تُسند إلى مندوب بعد';

    protected function getStats(): array
    {
        return [
            Stat::make(
                'طلبات جديدة غير مسندة',
                DeliveryOrder::query()->where('status', DeliveryOrderStatus::NewOrder)->count(),
            )
                ->description('اضغط لعرض قائمة الطلبات')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('warning')
                ->url(DeliveryOrderResource::getUrl('index')),
        ];
    }
}
