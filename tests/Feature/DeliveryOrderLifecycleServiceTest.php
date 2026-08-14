<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Exceptions\InvalidDeliveryOrderTransitionException;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeliveryOrderLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryOrderLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DeliveryOrderLifecycleService::class);
        Carbon::setTestNow('2026-08-14 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_new_order_can_be_assigned_and_then_reassigned_to_active_representatives(): void
    {
        $order = $this->createOrder();
        $first = $this->createRepresentative('First');
        $second = $this->createRepresentative('Second');

        $assigned = $this->service->assignRepresentative($order, $first);

        $this->assertSame(DeliveryOrderStatus::Assigned, $assigned->status);
        $this->assertSame($first->id, $assigned->representative_id);
        $this->assertNull($assigned->result);
        $this->assertNull($assigned->completed_at);
        $this->assertNull($assigned->cancelled_at);

        $reassigned = $this->service->assignRepresentative($assigned, $second);

        $this->assertSame(DeliveryOrderStatus::Assigned, $reassigned->status);
        $this->assertSame($second->id, $reassigned->representative_id);
    }

    public function test_assignment_to_inactive_representative_is_rejected_without_changes(): void
    {
        $order = $this->createOrder();
        $inactive = $this->createRepresentative('Inactive', false);

        try {
            $this->service->assignRepresentative($order, $inactive);
            $this->fail('Expected assignment to be rejected.');
        } catch (InvalidDeliveryOrderTransitionException) {
            $order->refresh();
            $this->assertSame(DeliveryOrderStatus::NewOrder, $order->status);
            $this->assertNull($order->representative_id);
        }
    }

    public function test_assignment_is_rejected_for_terminal_states(): void
    {
        $representative = $this->createRepresentative('Active');

        foreach ([DeliveryOrderStatus::Completed, DeliveryOrderStatus::Cancelled] as $status) {
            $order = $this->createOrder(['status' => $status]);

            try {
                $this->service->assignRepresentative($order, $representative);
                $this->fail("Expected assignment from [{$status->value}] to be rejected.");
            } catch (InvalidDeliveryOrderTransitionException) {
                $this->assertSame($status, $order->fresh()->status);
            }
        }
    }

    public function test_assigning_the_same_representative_is_an_idempotent_no_op(): void
    {
        $representative = $this->createRepresentative('Active');
        $order = $this->createOrder([
            'status' => DeliveryOrderStatus::Assigned,
            'representative_id' => $representative->id,
        ]);
        $originalUpdatedAt = $order->updated_at;

        Carbon::setTestNow('2026-08-14 13:00:00');
        $result = $this->service->assignRepresentative($order, $representative);

        $this->assertTrue($result->is($order));
        $this->assertTrue($order->fresh()->updated_at->equalTo($originalUpdatedAt));
    }

    public function test_assigned_order_can_be_completed_with_each_supported_result(): void
    {
        foreach (DeliveryOrderResult::cases() as $result) {
            $representative = $this->createRepresentative('Representative '.$result->value);
            $order = $this->createOrder([
                'status' => DeliveryOrderStatus::Assigned,
                'representative_id' => $representative->id,
            ]);

            $completed = $this->service->complete($order, $result);

            $this->assertSame(DeliveryOrderStatus::Completed, $completed->status);
            $this->assertSame($result, $completed->result);
            $this->assertTrue($completed->completed_at->equalTo(now()));
            $this->assertNull($completed->cancelled_at);
            $this->assertSame($representative->id, $completed->representative_id);
        }
    }

    public function test_completion_is_rejected_from_new_and_terminal_states(): void
    {
        foreach (
            [DeliveryOrderStatus::NewOrder, DeliveryOrderStatus::Completed, DeliveryOrderStatus::Cancelled] as $status
        ) {
            $order = $this->createOrder(['status' => $status]);

            $this->expectCompletionToFail($order);
            $this->assertSame($status, $order->fresh()->status);
        }
    }

    public function test_assigned_order_without_representative_cannot_be_completed(): void
    {
        $order = $this->createOrder(['status' => DeliveryOrderStatus::Assigned]);

        $this->expectCompletionToFail($order);
        $this->assertSame(DeliveryOrderStatus::Assigned, $order->fresh()->status);
    }

    public function test_inactive_representative_does_not_prevent_completion_after_assignment(): void
    {
        $representative = $this->createRepresentative('Representative');
        $order = $this->service->assignRepresentative($this->createOrder(), $representative);
        $representative->update(['is_active' => false]);

        $completed = $this->service->complete($order, DeliveryOrderResult::Delivered);

        $this->assertSame(DeliveryOrderStatus::Completed, $completed->status);
        $this->assertSame($representative->id, $completed->representative_id);
    }

    public function test_new_order_can_be_cancelled_without_a_delivery_result(): void
    {
        $cancelled = $this->service->cancel($this->createOrder());

        $this->assertSame(DeliveryOrderStatus::Cancelled, $cancelled->status);
        $this->assertNull($cancelled->result);
        $this->assertTrue($cancelled->cancelled_at->equalTo(now()));
        $this->assertNull($cancelled->completed_at);
    }

    public function test_assigned_order_can_be_cancelled_while_preserving_representative(): void
    {
        $representative = $this->createRepresentative('Representative');
        $order = $this->service->assignRepresentative($this->createOrder(), $representative);

        $cancelled = $this->service->cancel($order);

        $this->assertSame(DeliveryOrderStatus::Cancelled, $cancelled->status);
        $this->assertNull($cancelled->result);
        $this->assertSame($representative->id, $cancelled->representative_id);
    }

    public function test_cancellation_is_rejected_for_terminal_states(): void
    {
        foreach ([DeliveryOrderStatus::Completed, DeliveryOrderStatus::Cancelled] as $status) {
            $order = $this->createOrder(['status' => $status]);

            try {
                $this->service->cancel($order);
                $this->fail("Expected cancellation from [{$status->value}] to be rejected.");
            } catch (InvalidDeliveryOrderTransitionException) {
                $this->assertSame($status, $order->fresh()->status);
            }
        }
    }

    public function test_completion_preserves_customer_representative_and_result_history(): void
    {
        $customer = $this->createCustomer();
        $representative = $this->createRepresentative('Representative A');
        $order = $this->createOrder(['customer_id' => $customer->id]);

        $order = $this->service->assignRepresentative($order, $representative);
        $order = $this->service->complete($order, DeliveryOrderResult::Delivered);

        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($representative->id, $order->representative_id);
        $this->assertSame(DeliveryOrderStatus::Completed, $order->status);
        $this->assertSame(DeliveryOrderResult::Delivered, $order->result);
    }

    private function expectCompletionToFail(DeliveryOrder $order): void
    {
        try {
            $this->service->complete($order, DeliveryOrderResult::Delivered);
            $this->fail('Expected completion to be rejected.');
        } catch (InvalidDeliveryOrderTransitionException) {
            $this->assertTrue(true);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOrder(array $attributes = []): DeliveryOrder
    {
        return DeliveryOrder::create(array_merge([
            'customer_id' => $this->createCustomer()->id,
            'representative_id' => null,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
            'result' => null,
        ], $attributes));
    }

    private function createCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('phone-', true),
        ]);
    }

    private function createRepresentative(string $name, bool $active = true): Representative
    {
        return Representative::create([
            'name' => $name,
            'is_active' => $active,
        ]);
    }
}
