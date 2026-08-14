<?php

namespace App\Services;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Enums\IntegrationEventType;
use App\Enums\IntegrationOutboxStatus;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\OrderIntegrationState;
use App\Models\Representative;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class IntegrationEventGenerationService
{
    public function assigned(DeliveryOrder $order): IntegrationOutbox
    {
        $occurredAt = now();

        return $this->createEvent(
            $order,
            IntegrationEventType::OrderAssigned,
            fn (string $eventId, int $version): array => $this->envelope(
                $eventId,
                IntegrationEventType::OrderAssigned,
                $occurredAt,
                $version,
                [
                    'order' => array_merge($this->orderSnapshot($order), [
                        'status' => DeliveryOrderStatus::Assigned->value,
                        'assigned_at' => $this->date($occurredAt),
                    ]),
                    'customer' => $this->customerSnapshot($order),
                    'representative' => $this->representativeSnapshot($order->representative),
                    'location' => $this->locationSnapshot($order),
                    'customer_history' => $this->customerHistory($order),
                ],
            ),
        );
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changedFields
     */
    public function updated(DeliveryOrder $order, array $changedFields): IntegrationOutbox
    {
        return $this->snapshotEvent($order, IntegrationEventType::OrderUpdated, $changedFields);
    }

    public function reassigned(
        DeliveryOrder $order,
        Representative $previousRepresentative,
    ): IntegrationOutbox {
        $changedFields = [
            'representative.external_representative_id' => [
                'old' => (string) $previousRepresentative->getKey(),
                'new' => (string) $order->representative_id,
            ],
        ];

        return $this->createEvent(
            $order,
            IntegrationEventType::OrderReassigned,
            fn (string $eventId, int $version): array => $this->envelope(
                $eventId,
                IntegrationEventType::OrderReassigned,
                now(),
                $version,
                [
                    'previous_representative' => $this->representativeSnapshot($previousRepresentative),
                    'new_representative' => $this->representativeSnapshot($order->representative),
                    'changed_fields' => $changedFields,
                    'current_snapshot' => $this->currentSnapshot($order),
                ],
            ),
        );
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changedFields
     */
    public function cancelled(DeliveryOrder $order, array $changedFields): IntegrationOutbox
    {
        return $this->snapshotEvent($order, IntegrationEventType::OrderCancelled, $changedFields, true);
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changedFields
     */
    private function snapshotEvent(
        DeliveryOrder $order,
        IntegrationEventType $type,
        array $changedFields,
        bool $includeCancellationState = false,
    ): IntegrationOutbox {
        return $this->createEvent(
            $order,
            $type,
            fn (string $eventId, int $version): array => $this->envelope(
                $eventId,
                $type,
                now(),
                $version,
                [
                    'changed_fields' => $changedFields,
                    'current_snapshot' => $this->currentSnapshot($order, $includeCancellationState),
                ],
            ),
        );
    }

    /**
     * @param  callable(string, int): array<string, mixed>  $payloadBuilder
     */
    private function createEvent(
        DeliveryOrder $order,
        IntegrationEventType $type,
        callable $payloadBuilder,
    ): IntegrationOutbox {
        $state = OrderIntegrationState::query()
            ->where('delivery_order_id', $order->getKey())
            ->lockForUpdate()
            ->first();

        if ($state === null) {
            $state = OrderIntegrationState::create(['delivery_order_id' => $order->getKey()]);
        }

        $version = $state->current_version + 1;
        $eventId = (string) Str::uuid7();
        $payload = $payloadBuilder($eventId, $version);

        $event = IntegrationOutbox::create([
            'event_id' => $eventId,
            'delivery_order_id' => $order->getKey(),
            'event_type' => $type,
            'order_version' => $version,
            'payload' => $payload,
            'status' => IntegrationOutboxStatus::Pending,
            'attempts' => 0,
        ]);

        $state->forceFill(['current_version' => $version])->save();

        return $event;
    }

    /** @return array<string, mixed> */
    private function envelope(
        string $eventId,
        IntegrationEventType $type,
        Carbon $occurredAt,
        int $version,
        array $data,
    ): array {
        return [
            'contract_version' => '1.0',
            'source_system' => config('services.masar.source_system'),
            'event_id' => $eventId,
            'event_type' => $type->value,
            'occurred_at' => $this->date($occurredAt),
            'order_version' => $version,
            'data' => $data,
        ];
    }

    /** @return array<string, mixed> */
    private function currentSnapshot(DeliveryOrder $order, bool $cancelled = false): array
    {
        $orderSnapshot = $this->orderSnapshot($order);

        if ($cancelled) {
            $orderSnapshot = array_merge($orderSnapshot, [
                'status' => DeliveryOrderStatus::Cancelled->value,
                'result' => null,
                'cancelled_at' => $this->date($order->cancelled_at),
            ]);
        }

        return [
            'order' => $orderSnapshot,
            'customer' => $this->customerSnapshot($order),
            'representative' => $this->representativeSnapshot($order->representative),
            'location' => $this->locationSnapshot($order),
        ];
    }

    /** @return array<string, string> */
    private function orderSnapshot(DeliveryOrder $order): array
    {
        return [
            'external_order_id' => (string) $order->getKey(),
            'value' => $order->value,
            'created_at' => $this->date($order->created_at),
        ];
    }

    /** @return array<string, string> */
    private function customerSnapshot(DeliveryOrder $order): array
    {
        return [
            'external_customer_id' => (string) $order->customer->getKey(),
            'name' => $order->customer->name,
            'phone' => $order->customer->phone,
        ];
    }

    /** @return array<string, string|null> */
    private function representativeSnapshot(?Representative $representative): array
    {
        return [
            'external_representative_id' => (string) $representative?->getKey(),
            'name' => $representative?->name,
            'phone' => $representative?->phone,
        ];
    }

    /** @return array<string, string|null> */
    private function locationSnapshot(DeliveryOrder $order): array
    {
        return [
            'location_link' => $order->location_link,
            'latitude' => $order->latitude,
            'longitude' => $order->longitude,
        ];
    }

    /** @return array<int, array<string, string>> */
    private function customerHistory(DeliveryOrder $order): array
    {
        return DeliveryOrder::query()
            ->where('customer_id', $order->customer_id)
            ->whereKeyNot($order->getKey())
            ->where('status', DeliveryOrderStatus::Completed->value)
            ->whereIn('result', [
                DeliveryOrderResult::Delivered->value,
                DeliveryOrderResult::NotDelivered->value,
            ])
            ->whereNotNull('representative_id')
            ->whereNotNull('completed_at')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get()
            ->map(fn (DeliveryOrder $historicalOrder): array => [
                'external_order_id' => (string) $historicalOrder->getKey(),
                'external_representative_id' => (string) $historicalOrder->representative_id,
                'result' => $historicalOrder->result->value,
                'completed_at' => $this->date($historicalOrder->completed_at),
            ])
            ->all();
    }

    private function date(?Carbon $date): ?string
    {
        return $date?->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
