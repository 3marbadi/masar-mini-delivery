<?php

namespace App\Filament\Resources\Representatives\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class RepresentativeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')->label('الاسم'),
                TextEntry::make('phone')
                    ->label('الهاتف')
                    ->placeholder('-'),
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
            ]);
    }
}
