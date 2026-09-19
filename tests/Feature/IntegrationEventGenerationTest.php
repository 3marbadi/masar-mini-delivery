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
use App\Services\CustomerHistoryService;
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
        // The rate travels as a finished figure, never as the raw history it
        // was made from. Both halves of that sentence are assertions here.
        $this->assertArrayHasKey('reception_rate', $payload['data']['customer']);
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

    public function test_assigned_carries_the_company_reception_rate_and_not_the_representative_one(): void
    {
        $customer = $this->customer();
        $assignee = $this->representative('Assignee');
        $other = $this->representative('Other');

        // The assignee's own record is deliberately worse than the company's,
        // so a payload built from the representative summary would read 50.0
        // and this assertion would catch it.
        $this->completed($customer, delivered: 1, notDelivered: 1, representative: $assignee);
        $this->completed($customer, delivered: 2, notDelivered: 0, representative: $other);

        $rate = $this->assignedCustomerSnapshot($this->order($customer), $assignee)['reception_rate'];

        // 3 delivered of 4 completed, company-wide.
        $this->assertWireRate(75, $rate);
        $this->assertWireRate(
            app(CustomerHistoryService::class)->getCompanyReceptionSummary($customer)['reception_rate'],
            $rate,
        );
        $this->assertNotEquals(
            app(CustomerHistoryService::class)
                ->getRepresentativeReceptionSummary($customer, $assignee)['reception_rate'],
            $rate,
        );
    }

    public function test_assigned_reception_rate_preserves_the_exact_decimal_the_company_computed(): void
    {
        $customer = $this->customer();
        $this->completed($customer, delivered: 33, notDelivered: 7);

        $this->assertWireRate(82.5, $this->assignedCustomerSnapshot($this->order($customer))['reception_rate']);

        $twoThirds = $this->customer();
        $this->completed($twoThirds, delivered: 2, notDelivered: 1);

        // 66.67 and not 66, 67 or "66.67": the decimal survives the wire whole,
        // and assertWireRate refuses a string carrying it.
        $this->assertWireRate(66.67, $this->assignedCustomerSnapshot($this->order($twoThirds))['reception_rate']);
    }

    public function test_assigned_reception_rate_keeps_zero_and_one_hundred_as_real_values(): void
    {
        $none = $this->customer();
        $this->completed($none, delivered: 0, notDelivered: 2);

        $all = $this->customer();
        $this->completed($all, delivered: 2, notDelivered: 0);

        $noneRate = $this->assignedCustomerSnapshot($this->order($none))['reception_rate'];

        // Zero is a fact about a customer who has history, so it must not be
        // reported the way "no history at all" is.
        $this->assertWireRate(0, $noneRate);
        $this->assertNotNull($noneRate);

        $this->assertWireRate(100, $this->assignedCustomerSnapshot($this->order($all))['reception_rate']);
    }

    public function test_assigned_reception_rate_is_a_present_null_when_there_is_no_completed_history(): void
    {
        $customer = $this->customer();
        // Neither of these can produce a rate: one is unfinished, and a
        // cancellation is not a failed reception.
        $this->order($customer, ['status' => DeliveryOrderStatus::NewOrder]);
        $this->order($customer, ['status' => DeliveryOrderStatus::Cancelled]);

        $snapshot = $this->assignedCustomerSnapshot($this->order($customer));

        // Present and null, not absent: the key's presence is what keeps
        // "unknown" distinguishable from 0 on the wire.
        $this->assertArrayHasKey('reception_rate', $snapshot);
        $this->assertNull($snapshot['reception_rate']);
    }

    public function test_updated_carries_the_reception_rate_inside_the_current_snapshot(): void
    {
        $customer = $this->customer();
        $this->completed($customer, delivered: 2, notDelivered: 1);

        $assigned = $this->lifecycle->assignRepresentative(
            $this->order($customer, ['value' => '120.00', 'latitude' => '32.8872000']),
            $this->representative(),
        );
        $updated = app(DeliveryOrderUpdateService::class)->update($assigned, ['value' => '35.00']);

        $snapshot = $updated->integrationOutboxEvents()
            ->where('order_version', 2)
            ->firstOrFail()
            ->payload['data']['current_snapshot'];

        $this->assertWireRate(66.67, $snapshot['customer']['reception_rate']);

        // Additive only — the rest of the snapshot is untouched.
        $this->assertSame((string) $customer->id, $snapshot['customer']['external_customer_id']);
        $this->assertSame($assigned->recipient_name, $snapshot['customer']['name']);
        $this->assertSame($assigned->recipient_phone, $snapshot['customer']['phone']);
        $this->assertSame('35.00', $snapshot['order']['amount']);
        $this->assertSame('32.8872000', $snapshot['location']['latitude']);
        $this->assertArrayNotHasKey('customer_history', $snapshot);
    }

    /**
     * A rate as it actually appears on the wire.
     *
     * JSON has one number type, and PHP spends it accordingly: a whole rate is
     * encoded `100` and decodes back as int, a fractional one is encoded
     * `66.67` and decodes as float. Both are the same kind of thing to any
     * reader of the payload, so the assertion is about the number and not
     * about which PHP type carried it — while still refusing a string, which
     * would be a real contract break, and refusing null, which means something
     * else entirely.
     */
    private function assertWireRate(int|float $expected, mixed $actual): void
    {
        $this->assertIsNotString($actual);
        $this->assertNotNull($actual);
        $this->assertSame((float) $expected, (float) $actual);
    }

    /** @return array<string, mixed> the customer portion of this order's `order.assigned` payload */
    private function assignedCustomerSnapshot(DeliveryOrder $order, ?Representative $representative = null): array
    {
        $this->lifecycle->assignRepresentative($order, $representative ?? $this->representative());

        return IntegrationOutbox::query()
            ->where('delivery_order_id', $order->getKey())
            ->where('event_type', IntegrationEventType::OrderAssigned)
            ->firstOrFail()
            ->payload['data']['customer'];
    }

    private function completed(
        Customer $customer,
        int $delivered,
        int $notDelivered,
        ?Representative $representative = null,
    ): void {
        foreach ([[DeliveryOrderResult::Delivered, $delivered], [DeliveryOrderResult::NotDelivered, $notDelivered]] as [$result, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $this->order($customer, [
                    'representative_id' => $representative?->id,
                    'status' => DeliveryOrderStatus::Completed,
                    'result' => $result,
                ]);
            }
        }
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
