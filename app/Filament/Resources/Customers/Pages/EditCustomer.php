<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Services\CustomerUpdateService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(CustomerUpdateService::class)->update($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
