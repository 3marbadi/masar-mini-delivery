<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Support\OrderStatusPresenter;
use App\Models\DeliveryOrder;
use App\Services\OperationalStatusProjection;
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
                // The operational state leads here, as it does in the orders
                // table — this widget answers "what is happening right now".
                TextColumn::make('operational_status')
                    ->label('الحالة التشغيلية')
                    ->badge()
                    ->state(fn (DeliveryOrder $record): string => OrderStatusPresenter::operationalLabel($record))
                    ->color(fn (DeliveryOrder $record): string => OrderStatusPresenter::operationalColor($record)),
                // And the company's own verdict beside it, shown only when it
                // says something `open` does not.
                TextColumn::make('administrative_state')
                    ->label('الحالة الإدارية')
                    ->badge()
                    ->state(function (DeliveryOrder $record): ?string {
                        $state = OperationalStatusProjection::administrativeFor($record);

                        return $state->dominatesDisplay() ? $state->label() : null;
                    })
                    ->color(fn (DeliveryOrder $record): string => OperationalStatusProjection::administrativeFor($record)->color())
                    ->placeholder('—'),
                TextColumn::make('value')->label('القيمة')->money('LYD'),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime(),
            ])
            ->recordUrl(fn (DeliveryOrder $record): string => DeliveryOrderResource::getUrl('view', ['record' => $record]))
            ->paginated(false);
    }
}
