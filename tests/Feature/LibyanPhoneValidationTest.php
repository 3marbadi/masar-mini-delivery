<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Representatives\Pages\CreateRepresentative;
use App\Filament\Resources\Representatives\Pages\EditRepresentative;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Models\Representative;
use App\Models\User;
use App\Rules\LibyanMobileNumber;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\Integration\MasarDataEnvelope;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Libyan mobile rule, where it applies and — just as importantly — where it
 * does not.
 *
 * Two claims carry the most weight here. The first is that an operator can still
 * edit a legacy record whose number predates the rule, because the alternative
 * would be a system where every customer created before today is uneditable.
 * The second is that Masar's inbound corrections are **not** judged by this
 * regex: Masar validates a courier's edit by width alone, so refusing a format
 * here would reject a value Masar had already accepted, committed and versioned,
 * and the two systems would diverge for good on an order nobody was told about.
 */
class LibyanPhoneValidationTest extends TestCase
{
    use RefreshDatabase;

    private const ACCEPTED = ['0912345678', '0923456789', '0934567890', '0945678901'];

    private const REJECTED = [
        '0901234567',       // prefix outside 091–094
        '0951234567',       // prefix outside 091–094
        '091234567',        // nine digits
        '09123456789',      // eleven digits
        '+218912345678',    // international form, refused as new local input
        '091-234-5678',     // symbols
        '091 234 5678',     // spaces
        '09123456ab',       // letters
        '٠٩١٢٣٤٥٦٧٨',       // Arabic-Indic digits
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    // ------------------------------------------------------------- the rule

    public function test_the_rule_accepts_the_four_approved_prefixes(): void
    {
        foreach (self::ACCEPTED as $number) {
            $this->assertTrue(LibyanMobileNumber::matches($number), "expected [{$number}] to be accepted");
        }
    }

    public function test_the_rule_rejects_wrong_prefixes_lengths_letters_and_symbols(): void
    {
        foreach (self::REJECTED as $number) {
            $this->assertFalse(LibyanMobileNumber::matches($number), "expected [{$number}] to be rejected");
        }
    }

    public function test_an_empty_optional_value_is_not_a_malformed_number(): void
    {
        // The rule judges the shape of a number; whether one is required is the
        // form's decision. Conflating them would quietly make the
        // representative's optional phone mandatory.
        $failed = false;
        (new LibyanMobileNumber())->validate('phone', null, function () use (&$failed): void { $failed = true; });
        (new LibyanMobileNumber())->validate('phone', '', function () use (&$failed): void { $failed = true; });

        $this->assertFalse($failed);
    }

    // ------------------------------------------------------- customer entry

    public function test_a_customer_is_created_with_an_accepted_number_and_keeps_its_leading_zero(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'عميل', 'phone' => '0912345678', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = Customer::query()->where('name', 'عميل')->firstOrFail();

        // Stored as text, so the leading zero survives — a numeric column would
        // have turned this into 912345678.
        $this->assertSame('0912345678', $customer->phone);
        $this->assertIsString($customer->getRawOriginal('phone'));
    }

    public function test_creating_a_customer_with_a_rejected_number_fails_validation(): void
    {
        foreach (['0901234567', '0951234567', '091234567', '+218912345678', '09123456ab'] as $number) {
            Livewire::test(CreateCustomer::class)
                ->fillForm(['name' => 'عميل '.Str::random(5), 'phone' => $number, 'is_active' => true])
                ->call('create')
                ->assertHasFormErrors(['phone']);
        }

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_the_customer_unique_constraint_still_applies(): void
    {
        Customer::create(['name' => 'الأول', 'phone' => '0912345678']);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'الثاني', 'phone' => '0912345678', 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['phone']);

        $this->assertSame(1, Customer::query()->count());
    }

    // --------------------------------------------------- the legacy exemption

    public function test_a_legacy_customers_name_can_be_edited_without_touching_its_nonconforming_phone(): void
    {
        // A row as it exists today: international format, written long before
        // this rule.
        $customer = Customer::create(['name' => 'عميل قديم', 'phone' => '+218912345678']);

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['name' => 'اسم جديد', 'phone' => '+218912345678'])
            ->call('save')
            ->assertHasNoFormErrors();

        $customer->refresh();
        $this->assertSame('اسم جديد', $customer->name);
        $this->assertSame('+218912345678', $customer->phone);
    }

    public function test_changing_a_legacy_phone_to_another_invalid_value_is_refused(): void
    {
        $customer = Customer::create(['name' => 'عميل قديم', 'phone' => '+218912345678']);

        // The exemption is about *not changing* a value, never about writing
        // one. Any different value is judged on its merits.
        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['phone' => '+218923456789'])
            ->call('save')
            ->assertHasFormErrors(['phone']);

        $this->assertSame('+218912345678', $customer->fresh()->phone);
    }

    public function test_a_legacy_phone_can_be_corrected_to_a_conforming_value(): void
    {
        $customer = Customer::create(['name' => 'عميل قديم', 'phone' => '+218912345678']);

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['phone' => '0912345678'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('0912345678', $customer->fresh()->phone);
    }

    public function test_a_new_record_gets_no_historical_exemption(): void
    {
        // A legacy row holding exactly this value exists, and that must not
        // license a second record to be created with it.
        Customer::create(['name' => 'قديم', 'phone' => '+218912345678']);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'جديد', 'phone' => '+218999999999', 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['phone']);

        $this->assertNull(Customer::query()->where('name', 'جديد')->first());
    }

    // ------------------------------------------------- representative entry

    public function test_a_representative_may_be_created_with_no_phone_at_all(): void
    {
        Livewire::test(CreateRepresentative::class)
            ->fillForm(['name' => 'مندوب', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Representative::query()->where('name', 'مندوب')->firstOrFail()->phone);
    }

    public function test_a_representatives_phone_is_validated_when_one_is_given(): void
    {
        Livewire::test(CreateRepresentative::class)
            ->fillForm(['name' => 'مندوب', 'phone' => '0951234567', 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['phone']);

        Livewire::test(CreateRepresentative::class)
            ->fillForm(['name' => 'مندوب', 'phone' => '0931234567', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_a_legacy_representative_keeps_an_unchanged_nonconforming_phone(): void
    {
        $representative = Representative::create([
            'name' => 'مندوب قديم', 'phone' => '+218920000007', 'is_active' => true,
        ]);

        Livewire::test(EditRepresentative::class, ['record' => $representative->getRouteKey()])
            ->fillForm(['name' => 'مندوب محدَّث', 'phone' => '+218920000007'])
            ->call('save')
            ->assertHasNoFormErrors();

        $representative->refresh();
        $this->assertSame('مندوب محدَّث', $representative->name);
        $this->assertSame('+218920000007', $representative->phone);
    }

    // ------------------------------------------- the inbound channel is spared

    public function test_masars_recipient_phone_correction_is_not_judged_by_the_new_regex(): void
    {
        Carbon::setTestNow('2026-10-08 07:12:04');

        MasarIntegrationClient::create([
            'name' => 'Masar',
            'client_id' => 'masar',
            'client_secret_hash' => Hash::make('masar-secret'),
            'status' => 'active',
        ]);

        $token = $this->postJson('/api/v1/integration/auth/token', [
            'client_id' => 'masar', 'client_secret' => 'masar-secret',
        ])->json('access_token');

        $order = $this->assignedOrder();

        // A courier corrects the recipient's number to an international form.
        // Masar validates it by width alone, accepted it, and versioned it. If
        // this endpoint applied the local regex the correction would be refused
        // for ever and the courier would never learn of it — the silent
        // divergence the amount-ceiling fix closed by widening this side to the
        // sender's domain.
        $payload = [
            'contract_version' => MasarDataEnvelope::CONTRACT_VERSION,
            'event_id' => (string) Str::uuid(),
            'event_type' => MasarDataEnvelope::EVENT_TYPE,
            'occurred_at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'data' => [
                'order_id' => (string) $order->integration_uid,
                'data_version' => 1,
                'base_order_version' => 1,
                'changed_fields' => ['order.recipient_phone' => '+218912345678'],
            ],
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/integration/events', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame('+218912345678', $order->fresh()->recipient_phone);

        Carbon::setTestNow();
    }

    public function test_there_is_no_local_human_entry_point_for_the_recipient_columns(): void
    {
        // Stated as a test so the claim in the report is checkable: the order
        // form offers no recipient field, so there is no local path for a
        // person to type one and nothing for the rule to guard. The columns are
        // seeded from the customer at creation and corrected only by Masar.
        Livewire::test(\App\Filament\Resources\DeliveryOrders\Pages\CreateDeliveryOrder::class)
            ->assertFormFieldDoesNotExist('recipient_phone')
            ->assertFormFieldDoesNotExist('recipient_alternate_phone');
    }

    private function assignedOrder(): DeliveryOrder
    {
        $customer = Customer::create(['name' => 'عميل', 'phone' => '0912345678']);
        $representative = Representative::create([
            'name' => 'مندوب', 'phone' => '0921234567', 'is_active' => true,
        ]);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder->value,
        ]);

        return app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $representative);
    }
}
