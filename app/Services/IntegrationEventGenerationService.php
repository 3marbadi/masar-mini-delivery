<?php

namespace App\Services;

use App\Enums\IntegrationEventType;
use App\Enums\IntegrationOutboxStatus;
use App\Exceptions\MissingOrderRecipientException;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\OrderIntegrationState;
use App\Models\Representative;
use App\Services\Integration\DestinationPayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class IntegrationEventGenerationService
{
    /**
     * The reception rate on the customer snapshot is read from the service
     * that already owns the calculation, never recomputed here. This side is
     * its only source of truth — Masar receives the finished value and passes
     * it through — so a second expression for it anywhere would be a second
     * source, which is the one thing the field must not have.
     */
    public function __construct(
        private CustomerHistoryService $history,
    ) {}

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
     * @param  Carbon|null  $occurredAt  the instant the change committed, when the caller has
     *                                   already taken it. Since D13 a location change is stamped
     *                                   on the order with the same instant this event carries
     *                                   (§13.17.8), and Masar compares the two — so they must be
     *                                   one moment rather than two calls to now() a second apart.
     */
    public function updated(DeliveryOrder $order, array $changedFields, ?Carbon $occurredAt = null): IntegrationOutbox
    {
        return $this->snapshotEvent($order, IntegrationEventType::OrderUpdated, $changedFields, $occurredAt);
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
                    'external_order_id' => (string) $order->integration_uid,
                    'previous_external_courier_id' => (string) $previousRepresentative->integration_uid,
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
                ['external_order_id' => (string) $order->integration_uid],
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
        ?Carbon $occurredAt = null,
    ): IntegrationOutbox {
        // Taken once here when the caller did not supply it, so the closure
        // below cannot take a second reading. `occurred_at` is the business
        // instant of this change and is frozen into the stored payload — the
        // sender re-posts that payload verbatim, so no retry ever refreshes it
        // (§13.17.8).
        $occurredAt ??= now();

        return $this->createEvent(
            $order,
            $type,
            fn (string $eventId, int $version): array => $this->envelope(
                $eventId,
                $type,
                $occurredAt,
                $version,
                [
                    'external_order_id' => (string) $order->integration_uid,
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

    /**
     * The order's own section of a snapshot.
     *
     * `amount` is what the goods are worth; `delivery_cost` beside it is what the
     * delivery itself was charged, and the two are never conflated (§13.3). Both
     * are decimal strings.
     *
     * The destination and the fee are appended by {@see DestinationPayload},
     * which returns nothing while the rollout flag is off — so this envelope
     * stays byte-for-byte the pre-D3 one until Masar's receiver is deployed.
     *
     * @return array<string, mixed>
     */
    private function orderSnapshot(DeliveryOrder $order): array
    {
        return [
            'external_order_id' => (string) $order->integration_uid,
            'amount' => $order->value,
        ] + DestinationPayload::forSnapshot($order);
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
     * `reception_rate` is the one value here that describes the *customer*
     * rather than this order's recipient, and it sits beside them for the same
     * reason `external_customer_id` does: it is filed under the shared profile,
     * and this is where identity legitimately meets recipient data. It is the
     * company's own figure, taken whole from CustomerHistoryService — the
     * delivery company is the source of truth for it and Masar only relays it,
     * so it is read and not derived, here or downstream.
     *
     * `null` is a value and not a gap: it says this customer has no completed
     * delivery history to produce a rate from. It is emphatically not `0`,
     * which says the opposite — there is history, and none of it was received.
     * The key is therefore always present, so the two stay distinguishable on
     * the wire instead of collapsing into one absent field.
     *
     * @return array<string, string|float|null>
     *
     * @throws MissingOrderRecipientException when the order carries no recipient of its own
     */
    private function customerSnapshot(DeliveryOrder $order): array
    {
        if ($order->recipient_name === null || $order->recipient_phone === null) {
            throw MissingOrderRecipientException::for($order->getKey());
        }

        $customer = $order->customer;

        return [
            'external_customer_id' => (string) $customer->integration_uid,
            'name' => $order->recipient_name,
            'phone' => $order->recipient_phone,
            'reception_rate' => $this->history->getCompanyReceptionSummary($customer)['reception_rate'],
        ];
    }

    /**
     * Who is carrying this order, named by the identity Masar can keep.
     *
     * `integration_uid` and not `getKey()`, and the same holds for the customer
     * and the order above. The primary key is unique inside one incarnation of
     * this database and nowhere else: rebuild it, restore it somewhere else, or
     * replace it, and the next courier inherits the integer the previous one
     * had. Masar keys its mapping on `(integration_client_id,
     * external_courier_id)`, so a reused integer does not fail to resolve — it
     * resolves to the wrong person, and that courier's orders and tours come
     * with it. The uid is minted once per row and never moves, so a rebuilt
     * database produces new couriers instead of silently claiming old ones.
     *
     * The three `external_*` ids are each the entity's own uid. None is derived
     * from another, and none is derived from a name or a phone number — both of
     * those change, and an identity that moved when a courier corrected their
     * number would be no identity at all.
     *
     * @return array<string, string|null>
     */
    private function courierSnapshot(?Representative $representative): array
    {
        return [
            'external_courier_id' => (string) $representative?->integration_uid,
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
