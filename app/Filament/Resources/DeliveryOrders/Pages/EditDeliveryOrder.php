<?php

namespace App\Filament\Resources\DeliveryOrders\Pages;

use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Services\DeliveryOrderUpdateService;
use Filament\Actions\Action;
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

    /**
     * Save, with a confirmation step when the destination city has changed.
     *
     * Changing the city reprices the order, and a reprice is not something an
     * operator should be able to do by brushing past a dropdown. The form already
     * shows the old fee and the new one side by side the moment the city changes
     * (PLAN §5.2.7); this is the explicit "yes, apply it" before anything is
     * written.
     *
     * **It is a confirmation, not a boundary.** The integrity guarantee is that
     * no save occurs at all until the form is submitted — opening the form,
     * reopening it, or changing the city and walking away writes nothing, and
     * `DeliveryOrderUpdateService` touches no row when nothing moved. This modal
     * makes the repricing deliberate for the person doing it; it is not what
     * stops anything from happening.
     *
     * An unchanged city gets the ordinary one-click save, so every other edit —
     * the value, the location link — is exactly the operation it was before D2.
     */
    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->requiresConfirmation(fn (): bool => $this->destinationCityHasChanged())
            ->modalHeading('تأكيد تغيير المدينة')
            ->modalDescription('سيُحتسب سعر التوصيل من جديد وفق سعر المدينة الجديدة، ويُحفظ على هذا الطلب.')
            ->modalSubmitActionLabel('تأكيد وحفظ');
    }

    private function destinationCityHasChanged(): bool
    {
        $submitted = $this->data['city_id'] ?? null;

        if (blank($submitted)) {
            // Covers the synchronised order too: its city field is disabled, so
            // it is not in the form state at all and there is nothing to confirm.
            return false;
        }

        return (int) $submitted !== (int) $this->record->city_id;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
