<?php

namespace App\Services;

use App\Enums\DeliveryOrderStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CustomerUpdateService
{
    public function __construct(
        private IntegrationEventGenerationService $events,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function update(Customer $customer, array $attributes): Customer
    {
        return DB::transaction(function () use ($customer, $attributes): Customer {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->getKey());
            $lockedCustomer->fill(Arr::only($attributes, ['name', 'phone', 'is_active']));

            $changes = [];

            foreach (['name', 'phone'] as $field) {
                if ($lockedCustomer->isDirty($field)) {
                    $changes['customer.'.$field] = [
                        'old' => $lockedCustomer->getOriginal($field),
                        'new' => $lockedCustomer->getAttribute($field),
                    ];
                }
            }

            if (! $lockedCustomer->isDirty()) {
                return $lockedCustomer;
            }

            $lockedCustomer->save();

            if ($changes !== []) {
                DeliveryOrder::query()
                    ->where('customer_id', $lockedCustomer->getKey())
                    ->where('status', DeliveryOrderStatus::Assigned->value)
                    ->whereHas('integrationState', fn ($query) => $query->where('current_version', '>=', 1))
                    ->with(['customer', 'representative'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (DeliveryOrder $order) => $this->events->updated($order, $changes));
            }

            return $lockedCustomer->refresh();
        });
    }
}
