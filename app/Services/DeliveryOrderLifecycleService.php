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

            if (
                $wasAssigned
                && $previousRepresentative !== null
                && ($lockedOrder->integrationState?->current_version ?? 0) >= 1
            ) {
                $this->events->reassigned($lockedOrder, $previousRepresentative);
            } else {
                $this->events->assigned($lockedOrder);
            }

            return $lockedOrder->refresh();
        });
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
