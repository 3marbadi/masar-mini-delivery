<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\IntegrationEventType;
use App\Enums\IntegrationOutboxStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\OrderIntegrationState;
use App\Models\Representative;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_state_defaults_and_datetime_cast_are_persisted(): void
    {
        $order = $this->createOrder();
        $state = OrderIntegrationState::create(['delivery_order_id' => $order->id]);
        $state->refresh();

        $this->assertSame(0, $state->current_version);
        $this->assertNull($state->assigned_transmitted_at);

        $transmittedAt = Carbon::parse('2026-08-14 12:30:00');
        $state->update([
            'current_version' => 5,
            'assigned_transmitted_at' => $transmittedAt,
        ]);
        $state->refresh();

        $this->assertSame(5, $state->current_version);
        $this->assertInstanceOf(Carbon::class, $state->assigned_transmitted_at);
        $this->assertTrue($state->assigned_transmitted_at->equalTo($transmittedAt));
    }

    public function test_only_one_integration_state_can_exist_per_order(): void
    {
        $order = $this->createOrder();
        OrderIntegrationState::create(['delivery_order_id' => $order->id]);

        $this->expectException(QueryException::class);

        OrderIntegrationState::create(['delivery_order_id' => $order->id]);
    }

    public function test_outbox_defaults_enum_casts_and_attempt_metadata(): void
    {
        $outbox = $this->createOutbox($this->createOrder());
        $outbox->refresh();

        $this->assertSame(IntegrationEventType::OrderAssigned, $outbox->event_type);
        $this->assertSame(IntegrationOutboxStatus::Pending, $outbox->status);
        $this->assertSame(1, $outbox->order_version);
        $this->assertSame(0, $outbox->attempts);
        $this->assertNull($outbox->last_attempt_at);
        $this->assertNull($outbox->sent_at);
        $this->assertNull($outbox->last_error);
        $this->assertSame(['order' => ['external_order_id' => '100']], $outbox->payload);
    }

    public function test_event_id_must_be_globally_unique(): void
    {
        $eventId = (string) Str::uuid();
        $this->createOutbox($this->createOrder(), eventId: $eventId);

        $this->expectException(QueryException::class);

        $this->createOutbox($this->createOrder(), eventId: $eventId);
    }

    public function test_order_version_must_be_unique_for_each_order(): void
    {
        $order = $this->createOrder();
        $this->createOutbox($order, version: 1);

        $this->expectException(QueryException::class);

        $this->createOutbox($order, version: 1);
    }

    public function test_order_version_must_be_at_least_one(): void
    {
        $this->expectException(QueryException::class);

        $this->createOutbox($this->createOrder(), version: 0);
    }

    public function test_different_orders_can_use_the_same_version(): void
    {
        $first = $this->createOutbox($this->createOrder(), version: 1);
        $second = $this->createOutbox($this->createOrder(), version: 1);

        $this->assertNotSame($first->delivery_order_id, $second->delivery_order_id);
        $this->assertSame(1, $first->order_version);
        $this->assertSame(1, $second->order_version);
    }

    public function test_stored_payload_does_not_change_when_order_changes(): void
    {
        $order = $this->createOrder(['value' => '25.00']);
        $payload = [
            'order' => [
                'external_order_id' => (string) $order->id,
                'value' => $order->value,
            ],
        ];
        $outbox = $this->createOutbox($order, payload: $payload);

        $order->update(['value' => '99.00']);

        $this->assertEquals($payload, $outbox->fresh()->payload);
        $this->assertSame('99.00', $order->fresh()->value);
    }

    public function test_only_approved_event_types_and_statuses_are_defined(): void
    {
        $this->assertSame([
            'order.assigned',
            'order.updated',
            'order.reassigned',
            'order.cancelled',
        ], array_column(IntegrationEventType::cases(), 'value'));

        $this->assertSame([
            'pending',
            'sent',
            'failed',
        ], array_column(IntegrationOutboxStatus::cases(), 'value'));
    }

    public function test_relationships_work_without_automatic_state_or_event_creation(): void
    {
        $order = $this->createOrder();

        $this->assertNull($order->integrationState);
        $this->assertCount(0, $order->integrationOutboxEvents);

        $state = OrderIntegrationState::create(['delivery_order_id' => $order->id]);
        $outbox = $this->createOutbox($order);
        $order->refresh();

        $this->assertTrue($order->integrationState->is($state));
        $this->assertTrue($order->integrationOutboxEvents->contains($outbox));
        $this->assertTrue($state->deliveryOrder->is($order));
        $this->assertTrue($outbox->deliveryOrder->is($order));
    }

    public function test_existing_customer_and_representative_history_relationships_are_unchanged(): void
    {
        $customer = $this->createCustomer();
        $representative = Representative::create(['name' => 'Representative']);
        $order = $this->createOrder([
            'customer_id' => $customer->id,
            'representative_id' => $representative->id,
            'status' => DeliveryOrderStatus::Assigned,
        ]);

        $this->assertTrue($order->customer->is($customer));
        $this->assertTrue($order->representative->is($representative));
        $this->assertTrue($customer->deliveryOrders->contains($order));
        $this->assertTrue($representative->deliveryOrders->contains($order));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOrder(array $attributes = []): DeliveryOrder
    {
        return DeliveryOrder::create(array_merge([
            'customer_id' => $this->createCustomer()->id,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ], $attributes));
    }

    private function createCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('phone-', true),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function createOutbox(
        DeliveryOrder $order,
        ?string $eventId = null,
        int $version = 1,
        ?array $payload = null,
    ): IntegrationOutbox {
        return IntegrationOutbox::create([
            'event_id' => $eventId ?? (string) Str::uuid(),
            'delivery_order_id' => $order->id,
            'event_type' => IntegrationEventType::OrderAssigned,
            'order_version' => $version,
            'payload' => $payload ?? ['order' => ['external_order_id' => '100']],
        ]);
    }
}
