<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use App\Services\CustomerHistoryService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')->label('الاسم'),
                TextEntry::make('phone')->label('الهاتف'),
                IconEntry::make('is_active')
                    ->label('نشط')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime()
                    ->placeholder('-'),
                Section::make('ملخص سجل الاستلام')
                    ->schema(function (Customer $record): array {
                        $summary = app(CustomerHistoryService::class)
                            ->getCompanyReceptionSummary($record);

                        return [
                            TextEntry::make('history_completed_count')
                                ->label('الطلبات المكتملة المحتسبة')
                                ->state($summary['completed_count']),
                            TextEntry::make('history_delivered_count')
                                ->label('تم استلامها')
                                ->state($summary['delivered_count']),
                            TextEntry::make('history_not_delivered_count')
                                ->label('لم يتم استلامها')
                                ->state($summary['not_delivered_count']),
                            TextEntry::make('history_reception_rate')
                                ->label('نسبة الاستلام لدى الشركة')
                                ->state($summary['reception_rate'] === null
                                    ? 'لا يوجد سجل سابق'
                                    : number_format($summary['reception_rate'], 2).'%'),
                        ];
                    })
                    ->columns(4)
                    ->columnSpanFull(),
            ]);
    }
}
