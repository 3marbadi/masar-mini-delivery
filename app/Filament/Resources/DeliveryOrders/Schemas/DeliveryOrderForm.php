<?php

namespace App\Filament\Resources\DeliveryOrders\Schemas;

use App\Models\Customer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DeliveryOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->label('العميل')
                    ->relationship(
                        'customer',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Customer $record): string => $record->name.' — '.$record->phone)
                    ->searchable(['name', 'phone'])
                    ->preload()
                    ->required()
                    ->visibleOn('create'),
                TextInput::make('customer.name')
                    ->label('العميل')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),
                TextInput::make('value')
                    ->label('القيمة')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01),
                TextInput::make('location_link')
                    ->label('رابط الموقع')
                    ->maxLength(255),
                TextInput::make('latitude')
                    ->label('خط العرض')
                    ->numeric()
                    ->minValue(-90)
                    ->maxValue(90),
                TextInput::make('longitude')
                    ->label('خط الطول')
                    ->numeric()
                    ->minValue(-180)
                    ->maxValue(180),
            ]);
    }
}
