<?php

namespace App\Services;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Representative;
use Illuminate\Database\Eloquent\Collection;

class CustomerHistoryService
{
    /**
     * @return Collection<int, DeliveryOrder>
     */
    public function getOrderHistory(Customer $customer): Collection
    {
        return $customer->deliveryOrders()
            ->with('representative')
            ->latest()
            ->get();
    }

    /**
     * @return array{completed_count: int, delivered_count: int, not_delivered_count: int, reception_rate: float|null}
     */
    public function getCompanyReceptionSummary(Customer $customer): array
    {
        return $this->summarize($customer);
    }

    /**
     * @return array{completed_count: int, delivered_count: int, not_delivered_count: int, reception_rate: float|null}
     */
    public function getRepresentativeReceptionSummary(
        Customer $customer,
        Representative $representative,
    ): array {
        return $this->summarize($customer, $representative);
    }

    /**
     * @return array{completed_count: int, delivered_count: int, not_delivered_count: int, reception_rate: float|null}
     */
    private function summarize(Customer $customer, ?Representative $representative = null): array
    {
        $query = $customer->deliveryOrders()
            ->where('status', DeliveryOrderStatus::Completed->value)
            ->whereIn('result', [
                DeliveryOrderResult::Delivered->value,
                DeliveryOrderResult::NotDelivered->value,
            ]);

        if ($representative !== null) {
            $query->where('representative_id', $representative->getKey());
        }

        $summary = $query
            ->selectRaw('COUNT(*) as completed_count')
            ->selectRaw('SUM(result = ?) as delivered_count', [DeliveryOrderResult::Delivered->value])
            ->selectRaw('SUM(result = ?) as not_delivered_count', [DeliveryOrderResult::NotDelivered->value])
            ->toBase()
            ->first();

        $completedCount = (int) ($summary->completed_count ?? 0);
        $deliveredCount = (int) ($summary->delivered_count ?? 0);
        $notDeliveredCount = (int) ($summary->not_delivered_count ?? 0);

        return [
            'completed_count' => $completedCount,
            'delivered_count' => $deliveredCount,
            'not_delivered_count' => $notDeliveredCount,
            'reception_rate' => $completedCount === 0
                ? null
                : round(($deliveredCount / $completedCount) * 100, 2),
        ];
    }
}
