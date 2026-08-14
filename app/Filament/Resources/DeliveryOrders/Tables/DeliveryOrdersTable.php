<?php

namespace App\Filament\Resources\DeliveryOrders\Tables;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DeliveryOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable(),
                TextColumn::make('customer.phone')
                    ->label('هاتف العميل')
                    ->searchable(),
                TextColumn::make('representative.name')
                    ->label('المندوب')
                    ->placeholder('غير مسند')
                    ->searchable(),
                TextColumn::make('value')
                    ->label('القيمة')
                    ->money('LYD')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (DeliveryOrderStatus $state): string => match ($state) {
                        DeliveryOrderStatus::NewOrder => 'جديد',
                        DeliveryOrderStatus::Assigned => 'مُسند',
                        DeliveryOrderStatus::Completed => 'مكتمل',
                        DeliveryOrderStatus::Cancelled => 'ملغي',
                    }),
                TextColumn::make('result')
                    ->label('النتيجة')
                    ->badge()
                    ->formatStateUsing(fn (?DeliveryOrderResult $state): string => match ($state) {
                        DeliveryOrderResult::Delivered => 'تم التسليم',
                        DeliveryOrderResult::NotDelivered => 'لم يتم التسليم',
                        null => '—',
                    })
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        DeliveryOrderStatus::NewOrder->value => 'جديد',
                        DeliveryOrderStatus::Assigned->value => 'مُسند',
                        DeliveryOrderStatus::Completed->value => 'مكتمل',
                        DeliveryOrderStatus::Cancelled->value => 'ملغي',
                    ]),
                SelectFilter::make('result')
                    ->label('النتيجة')
                    ->options([
                        DeliveryOrderResult::Delivered->value => 'تم التسليم',
                        DeliveryOrderResult::NotDelivered->value => 'لم يتم التسليم',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeliveryOrderResource::assignRepresentativeAction(),
                DeliveryOrderResource::reassignRepresentativeAction(),
                DeliveryOrderResource::completeAction(),
                DeliveryOrderResource::cancelAction(),
            ])
            ->toolbarActions([]);
    }
}
