<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeliveryOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveryOrders';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('representative.name')
                    ->label('المندوب')
                    ->placeholder('غير مسند'),
                TextColumn::make('value')->label('القيمة')->money('LYD'),
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
                    ->formatStateUsing(fn (?DeliveryOrderResult $state): string => match ($state) {
                        DeliveryOrderResult::Delivered => 'تم التسليم',
                        DeliveryOrderResult::NotDelivered => 'لم يتم التسليم',
                        null => '—',
                    })
                    ->placeholder('—'),
                TextColumn::make('completed_at')->label('تاريخ الإكمال')->dateTime()->placeholder('—'),
                TextColumn::make('cancelled_at')->label('تاريخ الإلغاء')->dateTime()->placeholder('—'),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record): string => DeliveryOrderResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
