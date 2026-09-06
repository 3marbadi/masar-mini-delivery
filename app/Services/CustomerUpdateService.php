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
                $this->restateRecipients($lockedCustomer, $changes);

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

    /**
     * Carry the profile's new name and number onto the orders it speaks for
     * (CONTRACT §13.14 — v5.1, D7).
     *
     * Since v5.1 the recipient an order announces is its own snapshot, and never
     * a read of this row. That is what stops one order's screen from changing
     * because a *different* order was corrected. But it cuts both ways: without
     * this step the operator's edit here would be announced as a
     * `customer.name` change whose `current_snapshot` still carried the old
     * name, and Masar — which takes the applied value from the snapshot, not
     * from `changed_fields` — would write the old name back over the change it
     * had just been told about. The envelope has to agree with itself.
     *
     * So the copy happens here, once, at the moment the operator states the new
     * value. It is the same act as the creation-time seeding and the migration's
     * backfill: a write into each order's own column, after which the order owns
     * it again. It is not a read-time fallback, and the distinction is the one
     * D7 turns on — what is forbidden is *deriving* an order's recipient from
     * the shared row whenever it is read, not the company restating who receives
     * its own orders.
     *
     * Only open orders. A completed or cancelled order is history: its recipient
     * is who received it, and a later correction to the person's profile does not
     * reach back into what already happened. Every order that Masar has been told
     * about and could still act on is in this set, which is what keeps the two
     * systems convergent.
     *
     * That this overwrites a courier's earlier correction on such an order is
     * deliberate and is the contract's own direction: the delivery company is
     * authoritative over its own order, and each affected order is told
     * individually in the fan-out below (§13.14.4).
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    private function restateRecipients(Customer $customer, array $changes): void
    {
        $columns = [];

        if (array_key_exists('customer.name', $changes)) {
            $columns['recipient_name'] = $customer->name;
        }

        if (array_key_exists('customer.phone', $changes)) {
            $columns['recipient_phone'] = $customer->phone;
        }

        if ($columns === []) {
            return;
        }

        // The alternate number is untouched. It has no source in this system's
        // `customers` table at all — only a Masar correction can ever set it —
        // so there is nothing here to restate, and clearing it would destroy the
        // one value this side cannot reproduce (§13.14.1).
        DeliveryOrder::query()
            ->where('customer_id', $customer->getKey())
            ->whereIn('status', [DeliveryOrderStatus::NewOrder->value, DeliveryOrderStatus::Assigned->value])
            ->update($columns);
    }
}
