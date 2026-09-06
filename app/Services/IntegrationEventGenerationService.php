<?php

namespace App\Services;

use App\Enums\IntegrationEventType;
use App\Enums\IntegrationOutboxStatus;
use App\Exceptions\MissingOrderRecipientException;
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
                    'order' => $this->orderSnapshot($order),
                    'customer' => $this->customerSnapshot($order),
                    'courier' => $this->courierSnapshot($order->representative),
                    'location' => $this->locationSnapshot($order),
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
        return $this->createEvent(
            $order,
            IntegrationEventType::OrderReassigned,
            fn (string $eventId, int $version): array => $this->envelope(
                $eventId,
                IntegrationEventType::OrderReassigned,
                now(),
                $version,
                [
                    'external_order_id' => (string) $order->getKey(),
                    'previous_external_courier_id' => (string) $previousRepresentative->getKey(),
                    'courier' => $this->courierSnapshot($order->representative),
                ],
            ),
        );
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changedFields
     */
    public function cancelled(DeliveryOrder $order, array $changedFields): IntegrationOutbox
    {
        return $this->createEvent(
            $order,
            IntegrationEventType::OrderCancelled,
            fn (string $eventId, int $version): array => $this->envelope(
                $eventId,
                IntegrationEventType::OrderCancelled,
                now(),
                $version,
                ['external_order_id' => (string) $order->getKey()],
            ),
        );
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changedFields
     */
    private function snapshotEvent(
        DeliveryOrder $order,
        IntegrationEventType $type,
        array $changedFields,
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
                    'external_order_id' => (string) $order->getKey(),
                    'changed_fields' => $changedFields,
                    'current_snapshot' => $this->currentSnapshot($order),
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
            'event_id' => $eventId,
            'event_type' => $type->value,
            'occurred_at' => $this->date($occurredAt),
            'order_version' => $version,
            'data' => $data,
        ];
    }

    /** @return array<string, mixed> */
    private function currentSnapshot(DeliveryOrder $order): array
    {
        return [
            'order' => $this->orderSnapshot($order),
            'customer' => $this->customerSnapshot($order),
            'courier' => $this->courierSnapshot($order->representative),
            'location' => $this->locationSnapshot($order),
        ];
    }

    /** @return array<string, string> */
    private function orderSnapshot(DeliveryOrder $order): array
    {
        return [
            'external_order_id' => (string) $order->getKey(),
            'amount' => $order->value,
        ];
    }

    /**
     * Who receives this order, and the customer it is filed under.
     *
     * The identity stays the shared customer's — that is what
     * `external_customer_id` means, and Masar resolves its own customer row by
     * it. Identity is not recipient data, and this is the one place the two
     * legitimately sit side by side.
     *
     * The name and the number come from the order's own recipient snapshot
     * (§13.14, D7) and **from nowhere else**. Since the data channel opened they
     * can differ from the profile: a courier corrected them on this order, Masar
     * announced the correction, and it was applied here to this order alone.
     *
     * **There is deliberately no fallback to `$order->customer`.** It would be
     * the natural-looking repair for a null snapshot and it is exactly what D7
     * forbids, for a reason that outlives the null it would paper over: reading
     * the profile here re-couples a per-order value to a row every order of that
     * person shares. One order's announcement would then carry a name that
     * belongs to another order's history — and, worse, would carry the profile's
     * name back to Masar, where it is applied over the very correction Masar's
     * own courier made. A fallback is not a safety net here; it is the loop.
     *
     * A null snapshot is therefore an error and not a case to handle. Every row
     * that existed when the columns were added was backfilled and every order
     * since carries a recipient, so this is unreachable in ordinary operation;
     * if it is reached, failing here fails inside the caller's transaction, and
     * the local change rolls back with it. The alternative is an envelope Masar
     * refuses for a `customer.name` its own contract requires — discovered
     * later, in the outbox, by nobody.
     *
     * @return array<string, string>
     *
     * @throws MissingOrderRecipientException when the order carries no recipient of its own
     */
    private function customerSnapshot(DeliveryOrder $order): array
    {
        if ($order->recipient_name === null || $order->recipient_phone === null) {
            throw MissingOrderRecipientException::for($order->getKey());
        }

        return [
            'external_customer_id' => (string) $order->customer->getKey(),
            'name' => $order->recipient_name,
            'phone' => $order->recipient_phone,
        ];
    }

    /** @return array<string, string|null> */
    private function courierSnapshot(?Representative $representative): array
    {
        return [
            'external_courier_id' => (string) $representative?->getKey(),
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

    private function date(?Carbon $date): ?string
    {
        return $date?->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
