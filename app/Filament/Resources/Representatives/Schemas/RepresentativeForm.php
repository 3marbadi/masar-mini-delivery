<?php

namespace App\Filament\Resources\Representatives\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class RepresentativeForm
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
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label('نشط')
                    ->default(true),
            ]);
    }
}
