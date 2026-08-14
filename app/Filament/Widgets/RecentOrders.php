<?php

namespace App\Filament\Widgets;

use App\Enums\DeliveryOrderStatus;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Models\DeliveryOrder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentOrders extends TableWidget
{
    public function table(Table $table): Table
    {
        return $table
            ->heading('أحدث الطلبات')
            ->query(fn (): Builder => DeliveryOrder::query()
                ->with(['customer', 'representative'])
                ->whereKey(DeliveryOrder::query()->latest()->limit(5)->pluck('id'))
                ->latest())
            ->columns([
                TextColumn::make('id')->label('رقم الطلب'),
                TextColumn::make('customer.name')->label('العميل'),
                TextColumn::make('representative.name')->label('المندوب')->placeholder('غير مسند'),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (DeliveryOrderStatus $state): string => match ($state) {
                        DeliveryOrderStatus::NewOrder => 'جديد',
                        DeliveryOrderStatus::Assigned => 'مُسند',
                        DeliveryOrderStatus::Completed => 'مكتمل',
                        DeliveryOrderStatus::Cancelled => 'ملغي',
                    }),
                TextColumn::make('value')->label('القيمة')->money('LYD'),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime(),
            ])
            ->recordUrl(fn (DeliveryOrder $record): string => DeliveryOrderResource::getUrl('view', ['record' => $record]))
            ->paginated(false);
    }
}
