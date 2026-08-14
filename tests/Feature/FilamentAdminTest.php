<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Representatives\Pages\CreateRepresentative;
use App\Filament\Resources\Representatives\Pages\EditRepresentative;
use App\Filament\Resources\Representatives\Pages\ListRepresentatives;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Representative;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_requires_authentication_and_user_can_log_in(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');

        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('secure-password'),
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'secure-password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_representative_list_create_and_edit_work_without_delete_actions(): void
    {
        $this->actingAs(User::factory()->create());
        $representative = Representative::create(['name' => 'Old Name']);

        Livewire::test(ListRepresentatives::class)
            ->assertCanSeeTableRecords([$representative])
            ->assertActionDoesNotExist(TestAction::make('delete')->table($representative))
            ->assertActionDoesNotExist(TestAction::make('delete')->bulk());

        Livewire::test(CreateRepresentative::class)
            ->fillForm([
                'name' => 'Representative A',
                'phone' => '0910000001',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('representatives', ['name' => 'Representative A']);

        Livewire::test(EditRepresentative::class, ['record' => $representative->getRouteKey()])
            ->fillForm(['name' => 'New Name', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('representatives', [
            'id' => $representative->id,
            'name' => 'New Name',
            'is_active' => false,
        ]);
    }

    public function test_customer_list_create_edit_and_phone_uniqueness_work_without_delete_actions(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::create(['name' => 'Customer A', 'phone' => '0920000001']);
        Customer::create(['name' => 'Customer B', 'phone' => '0920000002']);

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$customer])
            ->assertActionDoesNotExist(TestAction::make('delete')->table($customer))
            ->assertActionDoesNotExist(TestAction::make('delete')->bulk());

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'name' => 'Customer C',
                'phone' => '0920000003',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'name' => 'Duplicate Customer',
                'phone' => '0920000002',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['phone' => 'unique']);

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->fillForm([
                'name' => 'Customer A Updated',
                'phone' => $customer->phone,
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('customers', ['name' => 'Customer A Updated']);
    }

    public function test_deactivation_preserves_records_and_historical_relationships(): void
    {
        $this->actingAs(User::factory()->create());
        $representative = Representative::create(['name' => 'Representative A']);
        $customer = Customer::create(['name' => 'Customer A', 'phone' => '0930000001']);
        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'representative_id' => $representative->id,
            'value' => '25.00',
            'status' => DeliveryOrderStatus::Completed,
            'result' => DeliveryOrderResult::Delivered,
        ]);

        Livewire::test(ListRepresentatives::class)
            ->callAction(TestAction::make('deactivate')->table($representative));
        Livewire::test(ListCustomers::class)
            ->callAction(TestAction::make('deactivate')->table($customer));

        $this->assertDatabaseHas('representatives', ['id' => $representative->id, 'is_active' => false]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'is_active' => false]);
        $this->assertTrue($order->fresh()->customer->is($customer));
        $this->assertTrue($order->fresh()->representative->is($representative));
    }
}
