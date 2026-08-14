<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Resources\DeliveryOrders\Pages\CreateDeliveryOrder;
use App\Filament\Resources\DeliveryOrders\Pages\EditDeliveryOrder;
use App\Filament\Resources\DeliveryOrders\Pages\ListDeliveryOrders;
use App\Filament\Resources\DeliveryOrders\Pages\ViewDeliveryOrder;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Representative;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentDeliveryOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_guest_cannot_access_orders_and_admin_can_open_the_list(): void
    {
        $this->get(DeliveryOrderResource::getUrl())->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create());

        Livewire::test(ListDeliveryOrders::class)->assertSuccessful();
    }

    public function test_admin_creates_new_order_with_only_base_data(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = $this->createCustomer('Active Customer');

        Livewire::test(CreateDeliveryOrder::class)
            ->assertFormFieldDoesNotExist('representative_id')
            ->assertFormFieldDoesNotExist('status')
            ->assertFormFieldDoesNotExist('result')
            ->assertFormFieldDoesNotExist('completed_at')
            ->assertFormFieldDoesNotExist('cancelled_at')
            ->fillForm([
                'customer_id' => $customer->id,
                'value' => '45.75',
                'location_link' => 'maps.example/place/123',
                'latitude' => '32.8872000',
                'longitude' => '13.1913000',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = DeliveryOrder::query()->sole();

        $this->assertSame(DeliveryOrderStatus::NewOrder, $order->status);
        $this->assertNull($order->representative_id);
        $this->assertNull($order->result);
        $this->assertSame('45.75', $order->value);
    }

    public function test_inactive_customer_cannot_be_used_to_create_an_order(): void
    {
        $this->actingAs(User::factory()->create());
        $inactiveCustomer = $this->createCustomer('Inactive Customer', false);

        Livewire::test(CreateDeliveryOrder::class)
            ->fillForm([
                'customer_id' => $inactiveCustomer->id,
                'value' => '10.00',
            ])
            ->call('create')
            ->assertHasFormErrors(['customer_id']);

        $this->assertDatabaseCount('delivery_orders', 0);
    }

    public function test_active_order_edit_changes_only_order_data_and_not_customer_or_lifecycle(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = $this->createCustomer('Original Customer');
        $otherCustomer = $this->createCustomer('Other Customer');
        $order = $this->createOrder($customer);

        Livewire::test(EditDeliveryOrder::class, ['record' => $order->getRouteKey()])
            ->assertFormFieldDoesNotExist('customer_id')
            ->assertFormFieldDoesNotExist('representative_id')
            ->assertFormFieldDoesNotExist('status')
            ->assertFormFieldDoesNotExist('result')
            ->assertFormFieldDoesNotExist('completed_at')
            ->assertFormFieldDoesNotExist('cancelled_at')
            ->fillForm([
                'value' => '90.50',
                'location_link' => 'new-location',
                'latitude' => '31.0000000',
                'longitude' => '14.0000000',
                'customer_id' => $otherCustomer->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $order->refresh();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('90.50', $order->value);
        $this->assertSame('new-location', $order->location_link);
        $this->assertSame(DeliveryOrderStatus::NewOrder, $order->status);
    }

    public function test_assign_and_reassign_actions_use_active_representatives(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->createOrder();
        $first = $this->createRepresentative('First');
        $second = $this->createRepresentative('Second');
        $inactive = $this->createRepresentative('Inactive', false);

        Livewire::test(ListDeliveryOrders::class)
            ->callAction(
                TestAction::make('assignRepresentative')->table($order),
                data: ['representative_id' => $inactive->id],
            )
            ->assertHasActionErrors(['representative_id']);

        $this->assertSame(DeliveryOrderStatus::NewOrder, $order->fresh()->status);

        Livewire::test(ListDeliveryOrders::class)
            ->callAction(
                TestAction::make('assignRepresentative')->table($order),
                data: ['representative_id' => $first->id],
            );

        $order->refresh();
        $this->assertSame(DeliveryOrderStatus::Assigned, $order->status);
        $this->assertSame($first->id, $order->representative_id);

        Livewire::test(ListDeliveryOrders::class)
            ->callAction(
                TestAction::make('reassignRepresentative')->table($order),
                data: ['representative_id' => $second->id],
            );

        $order->refresh();
        $this->assertSame($second->id, $order->representative_id);
        $this->assertSame(DeliveryOrderStatus::Assigned, $order->status);
    }

    public function test_complete_action_supports_both_delivery_results(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (DeliveryOrderResult::cases() as $result) {
            $representative = $this->createRepresentative('Representative '.$result->value);
            $order = $this->createOrder(attributes: [
                'representative_id' => $representative->id,
                'status' => DeliveryOrderStatus::Assigned,
            ]);

            Livewire::test(ListDeliveryOrders::class)
                ->callAction(
                    TestAction::make('completeOrder')->table($order),
                    data: ['result' => $result->value],
                );

            $order->refresh();
            $this->assertSame(DeliveryOrderStatus::Completed, $order->status);
            $this->assertSame($result, $order->result);
            $this->assertNotNull($order->completed_at);
            $this->assertNull($order->cancelled_at);
        }
    }

    public function test_cancel_actions_preserve_assignment_and_never_set_result(): void
    {
        $this->actingAs(User::factory()->create());
        $representative = $this->createRepresentative('Representative');
        $newOrder = $this->createOrder();
        $assignedOrder = $this->createOrder(attributes: [
            'representative_id' => $representative->id,
            'status' => DeliveryOrderStatus::Assigned,
        ]);

        foreach ([$newOrder, $assignedOrder] as $order) {
            Livewire::test(ListDeliveryOrders::class)
                ->callAction(TestAction::make('cancelOrder')->table($order));

            $order->refresh();
            $this->assertSame(DeliveryOrderStatus::Cancelled, $order->status);
            $this->assertNull($order->result);
            $this->assertNotNull($order->cancelled_at);
        }

        $this->assertSame($representative->id, $assignedOrder->representative_id);
    }

    public function test_terminal_orders_are_read_only_and_have_no_lifecycle_or_delete_actions(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ([DeliveryOrderStatus::Completed, DeliveryOrderStatus::Cancelled] as $status) {
            $order = $this->createOrder(attributes: [
                'status' => $status,
                'result' => $status === DeliveryOrderStatus::Completed
                    ? DeliveryOrderResult::Delivered
                    : null,
            ]);

            $this->get(DeliveryOrderResource::getUrl('edit', ['record' => $order]))->assertForbidden();

            Livewire::test(ListDeliveryOrders::class)
                ->assertActionHidden(TestAction::make('assignRepresentative')->table($order))
                ->assertActionHidden(TestAction::make('reassignRepresentative')->table($order))
                ->assertActionHidden(TestAction::make('completeOrder')->table($order))
                ->assertActionHidden(TestAction::make('cancelOrder')->table($order))
                ->assertActionDoesNotExist(TestAction::make('delete')->table($order))
                ->assertActionDoesNotExist(TestAction::make('delete')->bulk());
        }
    }

    public function test_inactive_historical_customer_and_representative_remain_visible(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = $this->createCustomer('Historical Customer', false);
        $representative = $this->createRepresentative('Historical Representative', false);
        $order = $this->createOrder($customer, [
            'representative_id' => $representative->id,
            'status' => DeliveryOrderStatus::Completed,
            'result' => DeliveryOrderResult::Delivered,
        ]);

        Livewire::test(ListDeliveryOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->assertSee('Historical Customer')
            ->assertSee('Historical Representative');

        Livewire::test(ViewDeliveryOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Historical Customer')
            ->assertSee('Historical Representative');
    }

    private function createCustomer(string $name = 'Customer', bool $active = true): Customer
    {
        return Customer::create([
            'name' => $name,
            'phone' => uniqid('phone-', true),
            'is_active' => $active,
        ]);
    }

    private function createRepresentative(string $name, bool $active = true): Representative
    {
        return Representative::create([
            'name' => $name,
            'is_active' => $active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOrder(?Customer $customer = null, array $attributes = []): DeliveryOrder
    {
        return DeliveryOrder::create(array_merge([
            'customer_id' => ($customer ?? $this->createCustomer())->id,
            'value' => '25.00',
            'status' => DeliveryOrderStatus::NewOrder,
            'result' => null,
        ], $attributes));
    }
}
