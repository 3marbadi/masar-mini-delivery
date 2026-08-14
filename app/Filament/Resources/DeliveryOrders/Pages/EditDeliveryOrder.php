<?php

namespace App\Filament\Resources\DeliveryOrders\Pages;

use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Services\DeliveryOrderUpdateService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDeliveryOrder extends EditRecord
{
    protected static string $resource = DeliveryOrderResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(DeliveryOrderUpdateService::class)->update($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
