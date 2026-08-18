<?php

namespace App\Services;

use App\Models\DeliveryOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DeliveryOrderUpdateService
{
    private const FIELDS = ['value', 'location_link', 'latitude', 'longitude'];

    private const PATHS = [
        'value' => 'order.amount',
        'location_link' => 'location.location_link',
        'latitude' => 'location.latitude',
        'longitude' => 'location.longitude',
    ];

    public function __construct(
        private IntegrationEventGenerationService $events,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function update(DeliveryOrder $order, array $attributes): DeliveryOrder
    {
        return DB::transaction(function () use ($order, $attributes): DeliveryOrder {
            $lockedOrder = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $lockedOrder->fill(Arr::only($attributes, self::FIELDS));

            $changes = [];

            foreach (self::FIELDS as $field) {
                if (! $lockedOrder->isDirty($field)) {
                    continue;
                }

                $changes[self::PATHS[$field]] = [
                    'old' => $this->serialize($lockedOrder->getOriginal($field), $field),
                    'new' => $this->serialize($lockedOrder->getAttribute($field), $field),
                ];
            }

            if ($changes === []) {
                return $lockedOrder;
            }

            $lockedOrder->save();
            $lockedOrder->load(['customer', 'representative', 'integrationState']);

            if (($lockedOrder->integrationState?->current_version ?? 0) >= 1) {
                $this->events->updated($lockedOrder, $changes);
            }

            return $lockedOrder->refresh();
        });
    }

    private function serialize(mixed $value, string $field): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            'value' => number_format((float) $value, 2, '.', ''),
            'latitude', 'longitude' => number_format((float) $value, 7, '.', ''),
            default => $value,
        };
    }
}
