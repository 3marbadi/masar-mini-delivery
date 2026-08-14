<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\DeliveryOrdersRelationManager;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Representative;
use App\Models\User;
use App\Services\CustomerHistoryService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private CustomerHistoryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CustomerHistoryService::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_company_summary_counts_only_completed_delivery_results(): void
    {
        $customer = $this->createCustomer();
        $this->createOrder($customer, DeliveryOrderStatus::Completed, DeliveryOrderResult::Delivered);
        $this->createOrder($customer, DeliveryOrderStatus::Completed, DeliveryOrderResult::Delivered);
        $this->createOrder($customer, DeliveryOrderStatus::Completed, DeliveryOrderResult::NotDelivered);

        $expected = [
            'completed_count' => 3,
            'delivered_count' => 2,
            'not_delivered_count' => 1,
            'reception_rate' => 66.67,
        ];

        $this->assertSame($expected, $this->service->getCompanyReceptionSummary($customer));

        $this->createOrder($customer, DeliveryOrderStatus::Cancelled);
        $this->createOrder($customer, DeliveryOrderStatus::NewOrder);
        $this->createOrder($customer, DeliveryOrderStatus::Assigned);

        $this->assertSame($expected, $this->service->getCompanyReceptionSummary($customer));
    }

    public function test_no_completed_history_returns_null_rate_instead_of_zero(): void
    {
        $customer = $this->createCustomer();
        $this->createOrder($customer, DeliveryOrderStatus::NewOrder);
        $this->createOrder($customer, DeliveryOrderStatus::Cancelled);

        $this->assertSame([
            'completed_count' => 0,
            'delivered_count' => 0,
            'not_delivered_count' => 0,
            'reception_rate' => null,
        ], $this->service->getCompanyReceptionSummary($customer));
    }

    public function test_representative_summary_is_scoped_and_preserves_zero_and_no_history_meanings(): void
    {
        $customer = $this->createCustomer();
        $representativeA = $this->createRepresentative('A');
        $representativeB = $this->createRepresentative('B');
        $representativeC = $this->createRepresentative('C');

        $this->createOrder($customer, DeliveryOrderStatus::Completed, DeliveryOrderResult::Delivered, $representativeA);
        $this->createOrder($customer, DeliveryOrderStatus::Completed, DeliveryOrderResult::Delivered, $representativeA);
        $this->createOrder($customer, DeliveryOrderStatus::Completed, DeliveryOrderResult::NotDelivered, $representativeB);

        $this->assertSame([
            'completed_count' => 2,
            'delivered_count' => 2,
            'not_delivered_count' => 0,
            'reception_rate' => 100.0,
        ], $this->service->getRepresentativeReceptionSummary($customer, $representativeA));

        $this->assertSame([
            'completed_count' => 1,
            'delivered_count' => 0,
            'not_delivered_count' => 1,
            'reception_rate' => 0.0,
        ], $this->service->getRepresentativeReceptionSummary($customer, $representativeB));

        $this->assertNull(
            $this->service->getRepresentativeReceptionSummary($customer, $representativeC)['reception_rate'],
        );
    }

    public function test_inactive_customer_and_representative_keep_their_historical_summary(): void
    {
        $customer = $this->createCustomer();
        $representative = $this->createRepresentative('Historical');
        $this->createOrder(
            $customer,
            DeliveryOrderStatus::Completed,
            DeliveryOrderResult::Delivered,
            $representative,
        );

        $customer->update(['is_active' => false]);
        $representative->update(['is_active' => false]);

        $this->assertSame(100.0, $this->service->getCompanyReceptionSummary($customer)['reception_rate']);
        $this->assertSame(
            100.0,
            $this->service->getRepresentativeReceptionSummary($customer, $representative)['reception_rate'],
        );
    }

    public function test_full_history_returns_all_statuses_newest_first_with_representatives_loaded(): void
    {
        $customer = $this->createCustomer();
        $representative = $this->createRepresentative('Representative');
        $statuses = [
            DeliveryOrderStatus::NewOrder,
            DeliveryOrderStatus::Assigned,
            DeliveryOrderStatus::Completed,
            DeliveryOrderStatus::Cancelled,
        ];

        foreach ($statuses as $index => $status) {
            $order = $this->createOrder(
                $customer,
                $status,
                $status === DeliveryOrderStatus::Completed ? DeliveryOrderResult::Delivered : null,
                $status === DeliveryOrderStatus::NewOrder ? null : $representative,
            );
            $order->forceFill(['created_at' => now()->addMinutes($index)])->saveQuietly();
        }

        $history = $this->service->getOrderHistory($customer);

        $this->assertSame(array_reverse($statuses), $history->pluck('status')->all());
        $this->assertTrue($history->every->relationLoaded('representative'));
    }

    public function test_customer_view_shows_summary_and_distinguishes_no_history(): void
    {
        $this->actingAs(User::factory()->create());
        $customerWithHistory = $this->createCustomer('With History');
        $customerWithoutHistory = $this->createCustomer('Without History');
        $this->createOrder(
            $customerWithHistory,
            DeliveryOrderStatus::Completed,
            DeliveryOrderResult::Delivered,
        );

        Livewire::test(ViewCustomer::class, ['record' => $customerWithHistory->getRouteKey()])
            ->assertSee('ملخص سجل الاستلام')
            ->assertSee('100.00%');

        Livewire::test(ViewCustomer::class, ['record' => $customerWithoutHistory->getRouteKey()])
            ->assertSee('لا يوجد سجل سابق')
            ->assertDontSee('0.00%');
    }

    public function test_customer_order_history_relation_is_read_only(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer, DeliveryOrderStatus::NewOrder);

        Livewire::test(DeliveryOrdersRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])
            ->assertCanSeeTableRecords([$order])
            ->assertActionDoesNotExist(TestAction::make('create'))
            ->assertActionDoesNotExist(TestAction::make('edit')->table($order))
            ->assertActionDoesNotExist(TestAction::make('delete')->table($order))
            ->assertActionDoesNotExist(TestAction::make('delete')->bulk())
            ->assertActionDoesNotExist(TestAction::make('assignRepresentative')->table($order))
            ->assertActionDoesNotExist(TestAction::make('completeOrder')->table($order))
            ->assertActionDoesNotExist(TestAction::make('cancelOrder')->table($order))
            ->assertActionExists(TestAction::make('view')->table($order));
    }

    private function createCustomer(string $name = 'Customer'): Customer
    {
        return Customer::create([
            'name' => $name,
            'phone' => uniqid('phone-', true),
        ]);
    }

    private function createRepresentative(string $name): Representative
    {
        return Representative::create(['name' => $name]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOrder(
        Customer $customer,
        DeliveryOrderStatus $status,
        ?DeliveryOrderResult $result = null,
        ?Representative $representative = null,
        array $attributes = [],
    ): DeliveryOrder {
        return DeliveryOrder::create(array_merge([
            'customer_id' => $customer->id,
            'representative_id' => $representative?->id,
            'value' => '20.00',
            'status' => $status,
            'result' => $result,
        ], $attributes));
    }
}
