<?php

namespace App\Filament\Resources\DeliveryOrders\Pages;

use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryOrders extends ListRecords
{
    protected static string $resource = DeliveryOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
