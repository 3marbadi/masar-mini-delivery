<?php

namespace App\Filament\Resources\DeliveryOrders\Pages;

use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDeliveryOrder extends ViewRecord
{
    protected static string $resource = DeliveryOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeliveryOrderResource::assignRepresentativeAction(),
            DeliveryOrderResource::reassignRepresentativeAction(),
            DeliveryOrderResource::completeAction(),
            DeliveryOrderResource::cancelAction(),
        ];
    }
}
