<?php

namespace App\Services;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Exceptions\InvalidDeliveryOrderTransitionException;
use App\Models\DeliveryOrder;
use App\Models\Representative;
use Illuminate\Support\Facades\DB;

class DeliveryOrderLifecycleService
{
    public function __construct(
        private IntegrationEventGenerationService $events,
    ) {}

    public function assignRepresentative(
        DeliveryOrder $order,
        Representative $representative,
    ): DeliveryOrder {
        return DB::transaction(function () use ($order, $representative): DeliveryOrder {
            $lockedOrder = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $lockedRepresentative = Representative::query()->findOrFail($representative->getKey());

            if (! in_array($lockedOrder->status, [DeliveryOrderStatus::NewOrder, DeliveryOrderStatus::Assigned], true)) {
                throw new InvalidDeliveryOrderTransitionException(
                    "Cannot assign a representative to an order with status [{$lockedOrder->status->value}].",
                );
            }

            if (! $lockedRepresentative->is_active) {
                throw new InvalidDeliveryOrderTransitionException('Cannot assign an inactive representative.');
            }

            if (
                $lockedOrder->status === DeliveryOrderStatus::Assigned
                && $lockedOrder->representative_id === $lockedRepresentative->getKey()
            ) {
                return $lockedOrder;
            }

            $this->assertDestinationIsPriced($lockedOrder);

            $wasAssigned = $lockedOrder->status === DeliveryOrderStatus::Assigned;
            $previousRepresentative = $wasAssigned ? $lockedOrder->representative : null;

            $lockedOrder->forceFill([
                'representative_id' => $lockedRepresentative->getKey(),
                'status' => DeliveryOrderStatus::Assigned,
                'result' => null,
                'completed_at' => null,
                'cancelled_at' => null,
            ])->save();
            $lockedOrder->load(['customer', 'representative', 'integrationState']);

            $event = (
                $wasAssigned
                && $previousRepresentative !== null
                && ($lockedOrder->integrationState?->current_version ?? 0) >= 1
            )
                ? $this->events->reassigned($lockedOrder, $previousRepresentative)
                : $this->events->assigned($lockedOrder);

            // The fence Masar's participation statements are judged against
            // (§13.29 — D31, draft). It is the `order_version` of *this* event,
            // read off the row the generator just wrote, and never a value
            // predicted before it or re-read from `integrationState` after it:
            // `current_version` moves for `order.updated` and `order.cancelled`
            // too, so taking it here rather than from the event would raise the
            // fence on changes that are not assignments and would then discard
            // a perfectly good participation statement.
            //
            // Written in this transaction, after the event and with it. A fence
            // that committed without its event — or an event without its fence
            // — would leave the two disagreeing about which assignment the next
            // announcement belongs to, which is the whole thing this column
            // exists to settle.
            //
            // `forceFill` on one column: this is bookkeeping about an event
            // already raised, not a change Masar needs to hear about, so it
            // raises nothing of its own. The idempotent no-op branch above
            // returned long before reaching here, so a reassignment to the
            // courier who already holds the order does not move the fence —
            // nothing was reassigned.
            $lockedOrder->forceFill([
                'assignment_order_version' => (int) $event->order_version,
            ])->save();

            return $lockedOrder->refresh();
        });
    }

    /**
     * Refuses to hand a courier an order with a destination but no price (D2;
     * PLAN §4.3, §9).
     *
     * **The exact condition, and why it is this narrow.** An order is refused
     * only when it *has* a city and its fee is null — which is to say when it is
     * destined for one of the four cities the source file prices at nothing.
     * Assignment is the moment the delivery becomes a commitment to a courier,
     * and committing to one at an undecided price is the thing PLAN §9 says must
     * not happen until a pricing policy exists.
     *
     * **An order with no city is not touched, and that is the whole point of the
     * `city_id` test.** Every order placed before this catalog existed has a null
     * fee because it has no destination, not because its price is undecided. A
     * guard that read the fee alone would refuse all of them at once, which would
     * take a working system off the road to enforce a rule about cities they do
     * not have. `city_id` is what separates "no price was decided" from "this
     * order never had a destination".
     *
     * **Reassignment is refused too.** Moving an unpriced order from one courier
     * to another does not make it priced, and allowing it would leave a way for
     * such an order to keep circulating. The idempotent branch above has already
     * returned by this point, so re-assigning the courier who already holds the
     * order remains the no-op it was.
     *
     * **What this is not.** It is not an approval permission, a manual override,
     * or an audit trail — D2 introduces none of those (PLAN §9 leaves the choice
     * between "price manually with authority and audit" and "block until priced"
     * to the project owner). It is the second of those two, in its smallest
     * form: the order saves, it is visible, it says plainly that it has no price,
     * and it cannot be given to a courier. Whoever adds the pricing policy
     * deletes or relaxes exactly this method.
     */
    private function assertDestinationIsPriced(DeliveryOrder $order): void
    {
        if ($order->city_id === null || $order->delivery_fee_lyd !== null) {
            return;
        }

        throw new InvalidDeliveryOrderTransitionException(
            "Cannot assign order [{$order->getKey()}] to a representative: its city [{$order->city_name}] has no "
            .'decided delivery price, and an unpriced order is not a priced one. A price must be approved for that '
            .'city before the order can be given to a courier.',
        );
    }

    public function complete(
        DeliveryOrder $order,
        DeliveryOrderResult $result,
    ): DeliveryOrder {
        return DB::transaction(function () use ($order, $result): DeliveryOrder {
            $lockedOrder = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($lockedOrder->status !== DeliveryOrderStatus::Assigned) {
                throw new InvalidDeliveryOrderTransitionException(
                    "Cannot complete an order with status [{$lockedOrder->status->value}].",
                );
            }

            if ($lockedOrder->representative_id === null) {
                throw new InvalidDeliveryOrderTransitionException(
                    'Cannot complete an order without an assigned representative.',
                );
            }

            $lockedOrder->forceFill([
                'status' => DeliveryOrderStatus::Completed,
                'result' => $result,
                'completed_at' => now(),
                'cancelled_at' => null,
            ])->save();

            return $lockedOrder->refresh();
        });
    }

    public function cancel(DeliveryOrder $order): DeliveryOrder
    {
        return DB::transaction(function () use ($order): DeliveryOrder {
            $lockedOrder = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if (! in_array($lockedOrder->status, [DeliveryOrderStatus::NewOrder, DeliveryOrderStatus::Assigned], true)) {
                throw new InvalidDeliveryOrderTransitionException(
                    "Cannot cancel an order with status [{$lockedOrder->status->value}].",
                );
            }

            $previousStatus = $lockedOrder->status;
            $lockedOrder->forceFill([
                'status' => DeliveryOrderStatus::Cancelled,
                'result' => null,
                'cancelled_at' => now(),
                'completed_at' => null,
            ])->save();
            $lockedOrder->load(['customer', 'representative', 'integrationState']);

            if (($lockedOrder->integrationState?->current_version ?? 0) >= 1) {
                $this->events->cancelled($lockedOrder, [
                    'order.status' => [
                        'old' => $previousStatus->value,
                        'new' => DeliveryOrderStatus::Cancelled->value,
                    ],
                    'order.cancelled_at' => [
                        'old' => null,
                        'new' => $lockedOrder->cancelled_at->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
                    ],
                ]);
            }

            return $lockedOrder->refresh();
        });
    }
}
