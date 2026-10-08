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
                // The operator's only location input. Coordinates are deliberately
                // absent: this company owns the link, Masar owns turning it into a
                // position, and Masar announces the result back onto this order
                // through the location channel (§3.7, D13). So `latitude` and
                // `longitude` are values this side *receives*, never values an
                // operator is asked to look up by hand.
                //
                // They stay on the table and in `$fillable` for that same reason.
                // Removing these two inputs removes a data-entry task, not a field:
                // `MasarLocationWriter` still writes both, and the view page still
                // shows them.
                TextInput::make('location_link')
                    ->label('رابط الموقع')
                    ->maxLength(255),
            ]);
    }
}
