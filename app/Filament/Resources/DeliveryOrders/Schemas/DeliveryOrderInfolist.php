<?php

namespace App\Filament\Resources\DeliveryOrders\Schemas;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class DeliveryOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')->label('رقم الطلب'),
                TextEntry::make('value')->label('القيمة')->money('LYD'),
                TextEntry::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (DeliveryOrderStatus $state): string => match ($state) {
                        DeliveryOrderStatus::NewOrder => 'جديد',
                        DeliveryOrderStatus::Assigned => 'مُسند',
                        DeliveryOrderStatus::Completed => 'مكتمل',
                        DeliveryOrderStatus::Cancelled => 'ملغي',
                    }),
                TextEntry::make('result')
                    ->label('النتيجة')
                    ->badge()
                    ->formatStateUsing(fn (?DeliveryOrderResult $state): string => match ($state) {
                        DeliveryOrderResult::Delivered => 'تم التسليم',
                        DeliveryOrderResult::NotDelivered => 'لم يتم التسليم',
                        null => '—',
                    })
                    ->placeholder('—'),
                TextEntry::make('customer.name')
                    ->label('العميل'),
                TextEntry::make('customer.phone')->label('هاتف العميل'),
                TextEntry::make('representative.name')
                    ->label('المندوب')
                    ->placeholder('غير مسند'),
                TextEntry::make('representative.phone')
                    ->label('هاتف المندوب')
                    ->placeholder('—'),
                TextEntry::make('location_link')
                    ->label('رابط الموقع')
                    ->url(fn (?string $state): ?string => $state)
                    ->openUrlInNewTab()
                    ->placeholder('—'),
                TextEntry::make('latitude')
                    ->label('خط العرض')
                    ->placeholder('—'),
                TextEntry::make('longitude')
                    ->label('خط الطول')
                    ->placeholder('—'),
                TextEntry::make('completed_at')
                    ->label('تاريخ الإكمال')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('cancelled_at')
                    ->label('تاريخ الإلغاء')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime()
                    ->placeholder('—'),
            ]);
    }
}
