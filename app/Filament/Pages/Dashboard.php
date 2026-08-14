<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Resources\Representatives\RepresentativeResource;
use App\Filament\Widgets\OperationalStats;
use App\Filament\Widgets\OrdersNeedingAttention;
use App\Filament\Widgets\RecentOrders;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'لوحة التحكم';

    protected static ?string $navigationLabel = 'لوحة التحكم';

    public function getSubheading(): string|Htmlable|null
    {
        return 'نظرة سريعة على عمليات التوصيل';
    }

    public function getWidgets(): array
    {
        return [
            OrdersNeedingAttention::class,
            OperationalStats::class,
            RecentOrders::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createOrder')
                ->label('أضف طلب')
                ->icon(Heroicon::OutlinedCube)
                ->url(DeliveryOrderResource::getUrl('create')),
            Action::make('createCustomer')
                ->label('أضف عميل')
                ->icon(Heroicon::OutlinedUserPlus)
                ->url(CustomerResource::getUrl('create')),
            Action::make('createRepresentative')
                ->label('أضف مندوب')
                ->icon(Heroicon::OutlinedTruck)
                ->url(RepresentativeResource::getUrl('create')),
        ];
    }
}
