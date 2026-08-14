<?php

namespace App\Filament\Resources\Representatives\Pages;

use App\Filament\Resources\Representatives\RepresentativeResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditRepresentative extends EditRecord
{
    protected static string $resource = RepresentativeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
