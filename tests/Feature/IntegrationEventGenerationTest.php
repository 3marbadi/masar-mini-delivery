<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Enums\IntegrationEventType;
use App\Enums\IntegrationOutboxStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\OrderIntegrationState;
use App\Models\Representative;
use App\Services\CustomerUpdateService;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\DeliveryOrderUpdateService;
use App\Services\IntegrationEventGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class IntegrationEventGenerationTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryOrderLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-14 12:00:00');
        $this->lifecycle = app(DeliveryOrderLifecycleService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_first_assignment_creates_v1_pending_event_with_contract_payload(): void
    {
        $customer = $this->customer();
        $representative = $this->representative('Current');
        $order = $this->order($customer, [
            'value' => '120.00',
            'location_link' => 'maps.example/order',
            'latitude' => '32.8872000',
            'longitude' => '13.1913000',
        ]);

        $this->lifecycle->assignRepresentative($order, $representative);

        $state = OrderIntegrationState::whereBelongsTo($order)->firstOrFail();
        $event = IntegrationOutbox::whereBelongsTo($order)->firstOrFail();
        $payload = $event->payload;

        $this->assertSame(1, $state->current_version);
        $this->assertNull($state->assigned_transmitted_at);
        $this->assertSame(IntegrationEventType::OrderAssigned, $event->event_type);
        $this->assertSame(IntegrationOutboxStatus::Pending, $event->status);
        $this->assertSame(0, $event->attempts);
        $this->assertSame($event->event_id, $payload['event_id']);
        $this->assertSame('1.0', $payload['contract_version']);
        $this->assertArrayNotHasKey('source_system', $payload);
        $this->assertSame('120.00', $payload['data']['order']['amount']);
        $this->assertSame('32.8872000', $payload['data']['location']['latitude']);
        $this->assertSame((string) $representative->id, $payload['data']['courier']['external_courier_id']);
        $this->assertArrayNotHasKey('customer_history', $payload['data']);
    }

    public function test_reassignment_increments_version_and_same_representative_is_no_op(): void
    {
        $first = $this->representative('First');
        $second = $this->representative('Second');
        $order = $this->lifecycle->assignRepresentative($this->order(), $first);

        $originalUpdatedAt = $order->updated_at;
        Carbon::setTestNow('2026-08-14 12:05:00');
        $this->lifecycle->assignRepresentative($order, $first);
        $this->assertSame(1, $order->integrationState()->value('current_version'));
        $this->assertSame(1, $order->integrationOutboxEvents()->count());
        $this->assertTrue($order->fresh()->updated_at->equalTo($originalUpdatedAt));

        $this->lifecycle->assignRepresentative($order->fresh(), $second);
        $event = $order->integrationOutboxEvents()->where('order_version', 2)->firstOrFail();

        $this->assertSame(IntegrationEventType::OrderReassigned, $event->event_type);
        $this->assertSame((string) $first->id, $event->payload['data']['previous_external_courier_id']);
        $this->assertSame((string) $second->id, $event->payload['data']['courier']['external_courier_id']);
    }

    public function test_completion_delivered_is_local_and_does_not_create_event_or_increment_version(): void
    {
        $order = $this->lifecycle->assignRepresentative($this->order(), $this->representative());

        $completed = $this->lifecycle->complete($order, DeliveryOrderResult::Delivered);

        $this->assertSame(DeliveryOrderStatus::Completed, $completed->status);
        $this->assertSame(DeliveryOrderResult::Delivered, $completed->result);
        $this->assertTrue($completed->completed_at->equalTo(now()));
        $this->assertSame(1, $completed->integrationState()->value('current_version'));
        $this->assertSame(1, $completed->integrationOutboxEvents()->count());
    }

    public function test_completion_not_delivered_is_local_and_does_not_create_event_or_increment_version(): void
    {
        $order = $this->lifecycle->assignRepresentative($this->order(), $this->representative());

        $completed = $this->lifecycle->complete($order, DeliveryOrderResult::NotDelivered);

        $this->assertSame(DeliveryOrderStatus::Completed, $completed->status);
        $this->assertSame(DeliveryOrderResult::NotDelivered, $completed->result);
        $this->assertSame(1, $completed->integrationState()->value('current_version'));
        $this->assertSame(1, $completed->integrationOutboxEvents()->count());
    }

    public function test_assigned_cancellation_creates_event_but_unseen_new_cancellation_does_not(): void
    {
        $assigned = $this->lifecycle->assignRepresentative($this->order(), $this->representative());
        $cancelled = $this->lifecycle->cancel($assigned);
        $event = $cancelled->integrationOutboxEvents()->where('order_version', 2)->firstOrFail();

        $this->assertSame(IntegrationEventType::OrderCancelled, $event->event_type);
        $this->assertSame((string) $assigned->id, $event->payload['data']['external_order_id']);
        $this->assertSame(['external_order_id'], array_keys($event->payload['data']));
        $this->assertSame($assigned->representative_id, $cancelled->representative_id);

        $new = $this->lifecycle->cancel($this->order());
        $this->assertNull($new->integrationState);
        $this->assertSame(0, $new->integrationOutboxEvents()->count());
    }

    public function test_order_updates_emit_once_after_integration_and_no_op_or_pre_assignment_emit_nothing(): void
    {
        $updates = app(DeliveryOrderUpdateService::class);
        $new = $this->order();
        $updates->update($new, ['value' => '30.00', 'latitude' => '32.0000000']);
        $this->assertNull($new->fresh()->integrationState);

        $assigned = $this->lifecycle->assignRepresentative($new->fresh(), $this->representative());
        $updated = $updates->update($assigned, ['value' => '35.00', 'latitude' => '33.0000000']);
        $event = $updated->integrationOutboxEvents()->where('order_version', 2)->firstOrFail();

        $this->assertSame(['order.amount', 'location.latitude'], array_keys($event->payload['data']['changed_fields']));
        $this->assertSame('35.00', $event->payload['data']['current_snapshot']['order']['amount']);
        $updates->update($updated, ['value' => '35.00', 'latitude' => '33.0000000']);
        $this->assertSame(2, $updated->integrationState()->value('current_version'));
        $this->assertSame(2, $updated->integrationOutboxEvents()->count());
    }

    public function test_customer_name_and_phone_fan_out_to_pending_and_transmitted_integrated_assigned_orders(): void
    {
        $customer = $this->customer();
        $representative = $this->representative();
        $first = $this->lifecycle->assignRepresentative($this->order($customer), $representative);
        $second = $this->lifecycle->assignRepresentative($this->order($customer), $representative);
        $pending = $this->lifecycle->assignRepresentative($this->order($customer), $representative);
        $completed = $this->lifecycle->assignRepresentative($this->order($customer), $representative);
        $this->lifecycle->complete($completed, DeliveryOrderResult::Delivered);
        $cancelled = $this->lifecycle->assignRepresentative($this->order($customer), $representative);
        $this->lifecycle->cancel($cancelled);
        $this->order($customer);
        $second->integrationState()->update(['assigned_transmitted_at' => now()]);

        $pendingV1Payload = $pending->integrationOutboxEvents()->where('order_version', 1)->value('payload');

        app(CustomerUpdateService::class)->update($customer, [
            'name' => 'Updated Customer',
            'phone' => '+218999',
            'is_active' => false,
        ]);

        foreach ([$first, $second, $pending] as $eligible) {
            $event = $eligible->integrationOutboxEvents()->where('order_version', 2)->firstOrFail();
            $this->assertSame('Updated Customer', $event->payload['data']['changed_fields']['customer.name']['new']);
            $this->assertSame('+218999', $event->payload['data']['changed_fields']['customer.phone']['new']);
            $this->assertSame('Updated Customer', $event->payload['data']['current_snapshot']['customer']['name']);
            $this->assertSame('+218999', $event->payload['data']['current_snapshot']['customer']['phone']);
        }

        $this->assertNull($pending->integrationState()->value('assigned_transmitted_at'));
        $this->assertEquals($pendingV1Payload, $pending->integrationOutboxEvents()->where('order_version', 1)->value('payload'));
        $this->assertSame(1, $completed->integrationOutboxEvents()->count());
        $this->assertSame(2, $cancelled->integrationOutboxEvents()->count());

        app(CustomerUpdateService::class)->update($customer->fresh(), ['is_active' => true]);
        $this->assertSame(2, $first->integrationOutboxEvents()->count());
        $this->assertSame(2, $second->integrationOutboxEvents()->count());
        $this->assertSame(2, $pending->integrationOutboxEvents()->count());
    }

    public function test_customer_name_and_phone_no_op_creates_no_event_or_version_increment(): void
    {
        $customer = $this->customer();
        $order = $this->lifecycle->assignRepresentative($this->order($customer), $this->representative());

        app(CustomerUpdateService::class)->update($customer, [
            'name' => $customer->name,
            'phone' => $customer->phone,
        ]);

        $this->assertSame(1, $order->integrationState()->value('current_version'));
        $this->assertSame(1, $order->integrationOutboxEvents()->count());
    }

    public function test_pending_assignment_phone_change_creates_v2_with_old_and_new_values(): void
    {
        $customer = $this->customer();
        $oldPhone = $customer->phone;
        $order = $this->lifecycle->assignRepresentative($this->order($customer), $this->representative());

        app(CustomerUpdateService::class)->update($customer, ['phone' => '+218920000099']);

        $state = $order->integrationState()->firstOrFail();
        $event = $order->integrationOutboxEvents()->where('order_version', 2)->firstOrFail();
        $this->assertSame(2, $state->current_version);
        $this->assertNull($state->assigned_transmitted_at);
        $this->assertSame(IntegrationEventType::OrderUpdated, $event->event_type);
        $this->assertSame(IntegrationOutboxStatus::Pending, $event->status);
        $this->assertSame($oldPhone, $event->payload['data']['changed_fields']['customer.phone']['old']);
        $this->assertSame('+218920000099', $event->payload['data']['changed_fields']['customer.phone']['new']);
        $this->assertSame('+218920000099', $event->payload['data']['current_snapshot']['customer']['phone']);
    }

    public function test_pending_assignment_name_change_creates_v2_and_keeps_v1_immutable(): void
    {
        $customer = $this->customer();
        $oldName = $customer->name;
        $order = $this->lifecycle->assignRepresentative($this->order($customer), $this->representative());
        $v1Payload = $order->integrationOutboxEvents()->where('order_version', 1)->value('payload');

        app(CustomerUpdateService::class)->update($customer, ['name' => 'New Customer Name']);

        $v2Payload = $order->integrationOutboxEvents()->where('order_version', 2)->value('payload');
        $this->assertSame($oldName, $v1Payload['data']['customer']['name']);
        $this->assertEquals($v1Payload, $order->integrationOutboxEvents()->where('order_version', 1)->value('payload'));
        $this->assertSame($oldName, $v2Payload['data']['changed_fields']['customer.name']['old']);
        $this->assertSame('New Customer Name', $v2Payload['data']['changed_fields']['customer.name']['new']);
        $this->assertSame('New Customer Name', $v2Payload['data']['current_snapshot']['customer']['name']);
    }

    public function test_versions_are_monotonic_per_order_and_payload_is_immutable(): void
    {
        $updates = app(DeliveryOrderUpdateService::class);
        $firstRepresentative = $this->representative('First');
        $secondRepresentative = $this->representative('Second');
        $order = $this->lifecycle->assignRepresentative($this->order(), $firstRepresentative);
        $firstPayload = $order->integrationOutboxEvents()->where('order_version', 1)->value('payload');
        $order = $updates->update($order, ['value' => '21.00']);
        $order = $this->lifecycle->assignRepresentative($order, $secondRepresentative);
        $order = $updates->update($order, ['longitude' => '13.0000000']);
        $this->lifecycle->cancel($order);

        $this->assertSame([1, 2, 3, 4, 5], $order->integrationOutboxEvents()->orderBy('order_version')->pluck('order_version')->all());
        $this->assertEquals($firstPayload, $order->integrationOutboxEvents()->where('order_version', 1)->value('payload'));

        $other = $this->lifecycle->assignRepresentative($this->order(), $firstRepresentative);
        $this->assertSame(1, $other->integrationState()->value('current_version'));
        $this->assertNotSame(
            $order->integrationOutboxEvents()->first()->event_id,
            $other->integrationOutboxEvents()->first()->event_id,
        );
    }

    public function test_event_generation_failure_rolls_back_business_mutation_and_version(): void
    {
        $generator = $this->mock(IntegrationEventGenerationService::class);
        $generator->shouldReceive('assigned')->once()->andThrow(new RuntimeException('outbox failed'));
        $service = app(DeliveryOrderLifecycleService::class);
        $order = $this->order();

        try {
            $service->assignRepresentative($order, $this->representative());
            $this->fail('Expected event creation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('outbox failed', $exception->getMessage());
        }

        $this->assertSame(DeliveryOrderStatus::NewOrder, $order->fresh()->status);
        $this->assertNull($order->fresh()->representative_id);
        $this->assertDatabaseCount('order_integration_states', 0);
        $this->assertDatabaseCount('integration_outbox', 0);
    }

    /** @param array<string, mixed> $attributes */
    private function order(?Customer $customer = null, array $attributes = []): DeliveryOrder
    {
        return DeliveryOrder::create(array_merge([
            'customer_id' => ($customer ?? $this->customer())->id,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ], $attributes));
    }

    private function customer(): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('+218', true),
        ]);
    }

    private function representative(string $name = 'Representative', bool $active = true): Representative
    {
        return Representative::create([
            'name' => $name.' '.uniqid(),
            'phone' => '+218920000007',
            'is_active' => $active,
        ]);
    }
}
