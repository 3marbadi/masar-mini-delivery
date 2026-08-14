<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label('الهاتف')
                    ->tel()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Toggle::make('is_active')
                    ->label('نشط')
                    ->default(true),
            ]);
    }
}
