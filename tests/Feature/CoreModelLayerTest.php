<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Representative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CoreModelLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_representative_can_be_created_and_exposes_boolean_and_orders(): void
    {
        $representative = Representative::create([
            'name' => 'Representative A',
            'phone' => null,
            'is_active' => 1,
        ]);
        $customer = $this->createCustomer('1001');
        $order = $this->createOrder($customer, $representative);

        $this->assertTrue($representative->is_active);
        $this->assertTrue($representative->deliveryOrders->contains($order));
    }

    public function test_customer_can_be_created_and_exposes_boolean_and_orders(): void
    {
        $customer = $this->createCustomer('1002');
        $order = $this->createOrder($customer);

        $this->assertTrue($customer->is_active);
        $this->assertTrue($customer->deliveryOrders->contains($order));
    }

    public function test_delivery_order_relationships_enum_casts_nullable_result_and_dates(): void
    {
        $customer = $this->createCustomer('1003');
        $representative = Representative::create([
            'name' => 'Representative B',
            'is_active' => true,
        ]);
        $order = $this->createOrder($customer, null, [
            'status' => DeliveryOrderStatus::Completed,
            'result' => null,
            'completed_at' => '2026-08-14 10:00:00',
            'cancelled_at' => '2026-08-14 11:00:00',
        ]);

        $this->assertTrue($order->customer->is($customer));
        $this->assertNull($order->representative);
        $this->assertSame(DeliveryOrderStatus::Completed, $order->status);
        $this->assertNull($order->result);
        $this->assertInstanceOf(Carbon::class, $order->completed_at);
        $this->assertInstanceOf(Carbon::class, $order->cancelled_at);
        $this->assertSame('10.00', $order->value);

        $order->update([
            'representative_id' => $representative->id,
            'result' => DeliveryOrderResult::Delivered,
        ]);
        $order->refresh();

        $this->assertTrue($order->representative->is($representative));
        $this->assertSame(DeliveryOrderResult::Delivered, $order->result);
    }

    public function test_customer_history_foundation_returns_orders_with_representatives_and_results(): void
    {
        $customer = $this->createCustomer('1004');
        $representativeA = Representative::create(['name' => 'Representative A']);
        $representativeB = Representative::create(['name' => 'Representative B']);

        $this->createOrder($customer, $representativeA, ['result' => DeliveryOrderResult::Delivered]);
        $this->createOrder($customer, $representativeB, ['result' => DeliveryOrderResult::NotDelivered]);
        $this->createOrder($customer, $representativeA, ['result' => DeliveryOrderResult::Delivered]);

        $orders = $customer->deliveryOrders()->with('representative')->get();

        $this->assertCount(3, $orders);
        $this->assertTrue($orders[0]->representative->is($representativeA));
        $this->assertSame(DeliveryOrderResult::Delivered, $orders[0]->result);
        $this->assertTrue($orders[1]->representative->is($representativeB));
        $this->assertSame(DeliveryOrderResult::NotDelivered, $orders[1]->result);
        $this->assertTrue($orders[2]->representative->is($representativeA));
        $this->assertSame(DeliveryOrderResult::Delivered, $orders[2]->result);
    }

    private function createCustomer(string $phone): Customer
    {
        return Customer::create([
            'name' => 'Customer '.$phone,
            'phone' => $phone,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOrder(
        Customer $customer,
        ?Representative $representative = null,
        array $attributes = [],
    ): DeliveryOrder {
        return DeliveryOrder::create(array_merge([
            'customer_id' => $customer->id,
            'representative_id' => $representative?->id,
            'value' => '10.00',
            'status' => DeliveryOrderStatus::Completed,
            'result' => DeliveryOrderResult::Delivered,
        ], $attributes));
    }
}
