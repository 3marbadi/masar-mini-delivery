<?php

namespace App\Filament\Resources\Representatives\Pages;

use App\Filament\Resources\Representatives\RepresentativeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRepresentatives extends ListRecords
{
    protected static string $resource = RepresentativeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
