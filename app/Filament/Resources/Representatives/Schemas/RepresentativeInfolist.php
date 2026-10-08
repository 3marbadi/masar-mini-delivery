<?php

namespace App\Filament\Resources\Representatives\Schemas;

use App\Livewire\MasarCredentialSection;
use App\Models\Representative;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Livewire;
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

                /*
                 * The courier's Masar login (Masar CONTRACT §13.28.16, D29).
                 *
                 * Purely additive: the five entries above are untouched, in the
                 * order they were already in.
                 *
                 * A Livewire component rather than more entries here, and `lazy()`
                 * is the reason. The state it shows is not Mini's — Masar is the
                 * sole authority (§13.28.3) — so rendering it means a live HTTP
                 * call, and a call made inline would hold the whole representative
                 * record hostage to Masar's availability. Deferred, the details
                 * render at once and the card resolves afterwards; if Masar is
                 * unreachable, one card says so and the page is still a page.
                 *
                 * The component receives the representative's primary key and
                 * nothing else. It resolves the model itself, so the identity the
                 * wire uses is read from the row rather than carried through the
                 * browser and back.
                 */
                Livewire::make(MasarCredentialSection::class, fn (Representative $record): array => [
                    'representativeId' => $record->getKey(),
                ])
                    ->lazy()
                    ->columnSpanFull(),
            ]);
    }
}
