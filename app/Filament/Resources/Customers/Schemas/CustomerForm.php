<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use App\Rules\LibyanMobileNumber;
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
                // The approved Libyan mobile rule, applied at the one place a
                // person enters a customer's number.
                //
                // `forStoredValue` rather than a bare rule, and the difference
                // is what makes editing a legacy record possible: rows written
                // before this rule may hold anything, and an operator fixing a
                // customer's *name* must not be blocked by a number they never
                // touched. Submitting the stored value unchanged passes;
                // submitting any different value — including another invalid one
                // — is judged on its merits. A new record has no stored value,
                // so it gets no exemption.
                //
                // UNIQUE is untouched, and nothing is normalised: `+218…` is
                // refused rather than rewritten, because a silent rewrite on a
                // UNIQUE column could collide two existing customers.
                TextInput::make('phone')
                    ->label('الهاتف')
                    ->tel()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->rules(fn (?Customer $record): array => [
                        LibyanMobileNumber::forStoredValue($record?->phone),
                    ]),
                Toggle::make('is_active')
                    ->label('نشط')
                    ->default(true),
            ]);
    }
}
