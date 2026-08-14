<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Resources\Representatives\RepresentativeResource;
use App\Filament\Widgets\OperationalStats;
use App\Filament\Widgets\OrdersNeedingAttention;
use App\Filament\Widgets\RecentOrders;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Representative;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_dashboard_requires_authentication_and_admin_can_open_it(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create());

        Livewire::test(Dashboard::class)
            ->assertSuccessful()
            ->assertSee('لوحة التحكم')
            ->assertSee('نظرة سريعة على عمليات التوصيل');
    }

    public function test_quick_actions_point_to_the_correct_create_pages(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Dashboard::class)
            ->assertActionExists('createOrder')
            ->assertActionHasUrl('createOrder', DeliveryOrderResource::getUrl('create'))
            ->assertActionExists('createCustomer')
            ->assertActionHasUrl('createCustomer', CustomerResource::getUrl('create'))
            ->assertActionExists('createRepresentative')
            ->assertActionHasUrl('createRepresentative', RepresentativeResource::getUrl('create'));
    }

    public function test_operational_statistics_show_correct_order_and_active_people_counts(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = $this->createCustomer(true);
        $this->createCustomer(true);
        $this->createCustomer(false);
        Representative::create(['name' => 'Active A', 'is_active' => true]);
        Representative::create(['name' => 'Active B', 'is_active' => true]);
        Representative::create(['name' => 'Inactive', 'is_active' => false]);

        $this->createOrder($customer, DeliveryOrderStatus::NewOrder);
        $this->createOrder($customer, DeliveryOrderStatus::NewOrder);
        $this->createOrder($customer, DeliveryOrderStatus::Assigned);
        $this->createOrder($customer, DeliveryOrderStatus::Completed, DeliveryOrderResult::Delivered);
        $this->createOrder($customer, DeliveryOrderStatus::Completed, DeliveryOrderResult::Delivered);
        $this->createOrder($customer, DeliveryOrderStatus::Completed, DeliveryOrderResult::NotDelivered);
        $this->createOrder($customer, DeliveryOrderStatus::Cancelled);

        Livewire::test(OperationalStats::class)
            ->assertSee('إجمالي الطلبات')
            ->assertSee('طلبات جديدة')
            ->assertSee('طلبات مُسندة')
            ->assertSee('طلبات مكتملة')
            ->assertSee('تم التسليم')
            ->assertSee('لم يتم التسليم')
            ->assertSee('طلبات ملغاة')
            ->assertSee('المندوبون النشطون')
            ->assertSee('العملاء النشطون')
            ->assertSeeInOrder(['7', '2', '1', '3', '2', '1', '1', '2', '2']);
    }

    public function test_needs_attention_counts_only_new_orders(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = $this->createCustomer();
        $this->createOrder($customer, DeliveryOrderStatus::NewOrder);
        $this->createOrder($customer, DeliveryOrderStatus::NewOrder);
        $this->createOrder($customer, DeliveryOrderStatus::Assigned);
        $this->createOrder($customer, DeliveryOrderStatus::Cancelled);

        Livewire::test(OrdersNeedingAttention::class)
            ->assertSee('طلبات تحتاج متابعتك')
            ->assertSee('طلبات جديدة غير مسندة')
            ->assertSee('2');
    }

    public function test_recent_orders_widget_shows_only_latest_five_orders(): void
    {
        $this->actingAs(User::factory()->create());
        $orders = collect();

        foreach (range(1, 6) as $index) {
            $customer = Customer::create([
                'name' => 'Recent Customer '.$index,
                'phone' => '100'.$index,
            ]);
            $order = $this->createOrder($customer, DeliveryOrderStatus::NewOrder);
            $order->forceFill(['created_at' => now()->addMinutes($index)])->saveQuietly();
            $orders->push($order);
        }

        Livewire::test(RecentOrders::class)
            ->assertCountTableRecords(5)
            ->assertCanSeeTableRecords($orders->slice(1)->reverse()->values(), inOrder: true)
            ->assertCanNotSeeTableRecords([$orders->first()]);
    }

    private function createCustomer(bool $active = true): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('phone-', true),
            'is_active' => $active,
        ]);
    }

    private function createOrder(
        Customer $customer,
        DeliveryOrderStatus $status,
        ?DeliveryOrderResult $result = null,
    ): DeliveryOrder {
        return DeliveryOrder::create([
            'customer_id' => $customer->id,
            'value' => '25.00',
            'status' => $status,
            'result' => $result,
        ]);
    }
}
