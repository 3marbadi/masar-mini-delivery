<?php

namespace App\Filament\Resources\Representatives\Schemas;

use App\Models\Representative;
use App\Rules\LibyanMobileNumber;
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
                // Same rule, and still optional. The field has always been
                // nullable and no contract makes it required, so the rule
                // judges a number's shape and says nothing about whether one
                // must be given — an empty value passes untouched.
                TextInput::make('phone')
                    ->label('الهاتف')
                    ->tel()
                    ->maxLength(255)
                    ->rules(fn (?Representative $record): array => [
                        LibyanMobileNumber::forStoredValue($record?->phone),
                    ]),
                Toggle::make('is_active')
                    ->label('نشط')
                    ->default(true),
            ]);
    }
}
