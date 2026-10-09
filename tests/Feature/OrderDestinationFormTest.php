<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\FulfilmentKind;
use App\Filament\Resources\DeliveryOrders\Pages\CreateDeliveryOrder;
use App\Filament\Resources\DeliveryOrders\Pages\EditDeliveryOrder;
use App\Filament\Resources\DeliveryOrders\Pages\ViewDeliveryOrder;
use App\Filament\Resources\DeliveryOrders\Schemas\DeliveryOrderForm;
use App\Models\Customer;
use App\Models\DeliveryCity;
use App\Models\DeliveryOrder;
use App\Models\DeliveryRegion;
use App\Models\IntegrationOutbox;
use App\Models\Representative;
use App\Models\User;
use App\Services\DeliveryOrderLifecycleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The order form's destination fields, driven as an operator drives them
 * (PLAN D2 §5.1, §5.2).
 *
 * The companion `OrderDestinationIntegrityTest` proves what the database will
 * accept; this file proves what the screen offers and what one submission
 * actually stores. Both are needed and neither substitutes for the other: a form
 * can be configured to offer only valid options and still save the wrong thing,
 * and a correct save path can sit behind a select that lists another city's
 * regions.
 *
 * The acceptance case the plan is judged on — طرابلس / السراج / `15.00 د.ل` —
 * is the first test here, end to end through the real page.
 */
class OrderDestinationFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs(User::factory()->create());
    }

    // ---------------------------------------------------------------
    // Creating an order with a destination
    // ---------------------------------------------------------------

    public function test_tripoli_and_al_sarraj_are_stored_with_the_city_price(): void
    {
        $tripoli = $this->city('طرابلس', '15.00', regionRequired: true);
        $sarraj = $this->region($tripoli, 'السراج', 's24');

        Livewire::test(CreateDeliveryOrder::class)
            ->fillForm([
                'customer_id' => $this->customer()->id,
                'value' => '250.00',
                'city_id' => $tripoli->id,
                'region_id' => $sarraj->id,
            ])
            // The fee is shown, not typed. It is a Placeholder, so there is no
            // field to fill and nothing is dehydrated from it.
            ->assertFormFieldDoesNotExist('delivery_fee_lyd')
            ->assertSee('15.00 د.ل')
            ->call('create')
            ->assertHasNoFormErrors();

        $order = DeliveryOrder::query()->sole();

        $this->assertSame($tripoli->id, $order->city_id);
        $this->assertSame($sarraj->id, $order->region_id);
        $this->assertSame('طرابلس', $order->city_name);
        $this->assertSame('السراج', $order->region_name);
        $this->assertSame('15.00', $order->delivery_fee_lyd);

        // And the view page reports it back from the order's own snapshot.
        Livewire::test(ViewDeliveryOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('طرابلس')
            ->assertSee('السراج');
    }

    public function test_a_different_region_of_the_same_city_carries_the_same_price(): void
    {
        $tripoli = $this->city('طرابلس', '15.00', regionRequired: true);
        $this->region($tripoli, 'السراج', 's24');
        $farnaj = $this->region($tripoli, 'الفرناج', 's21');

        $this->createThroughForm(['city_id' => $tripoli->id, 'region_id' => $farnaj->id]);

        $order = DeliveryOrder::query()->sole();

        // The catalog prices cities, so every region of طرابلس is `15.00`. A
        // per-region price would be a separate design (PLAN §4.3).
        $this->assertSame('الفرناج', $order->region_name);
        $this->assertSame('15.00', $order->delivery_fee_lyd);
    }

    public function test_only_the_selected_citys_regions_are_offered(): void
    {
        $tripoli = $this->city('طرابلس', '15.00', regionRequired: true);
        $benghazi = $this->city('بنغازي', '25.00', regionRequired: true);
        $sarraj = $this->region($tripoli, 'السراج', 's24');
        // Same routing code as السراج, in another city: a code is shared by 60
        // regions in the real catalog and must not pull one into another city's
        // list.
        $kish = $this->region($benghazi, 'الكيش', 's24');

        $component = Livewire::test(CreateDeliveryOrder::class)
            ->fillForm(['customer_id' => $this->customer()->id, 'value' => '10.00', 'city_id' => $tripoli->id]);

        $options = $this->regionOptions($component);

        $this->assertArrayHasKey($sarraj->id, $options);
        $this->assertArrayNotHasKey($kish->id, $options);
    }

    public function test_changing_the_city_clears_the_previously_chosen_region(): void
    {
        $tripoli = $this->city('طرابلس', '15.00', regionRequired: true);
        $misrata = $this->city('مصراتة', '20.00', regionRequired: true);
        $sarraj = $this->region($tripoli, 'السراج', 's24');
        $this->region($misrata, 'الزروق', 's5');

        $component = Livewire::test(CreateDeliveryOrder::class)
            ->fillForm([
                'customer_id' => $this->customer()->id,
                'value' => '10.00',
                'city_id' => $tripoli->id,
                'region_id' => $sarraj->id,
            ])
            ->assertFormSet(['region_id' => $sarraj->id])
            // Always cleared, without exception (PLAN §5.2.2). Keeping it would
            // leave a region of طرابلس sitting under مصراتة, which the save path
            // would then refuse — correctly, but leaving the operator to work out
            // why.
            ->fillForm(['city_id' => $misrata->id])
            ->assertFormSet(['region_id' => null]);

        // And the fee preview followed the new city.
        $component->assertSee('20.00 د.ل');
    }

    public function test_a_city_that_requires_a_region_refuses_to_save_without_one(): void
    {
        $tripoli = $this->city('طرابلس', '15.00', regionRequired: true);
        $this->region($tripoli, 'السراج', 's24');

        Livewire::test(CreateDeliveryOrder::class)
            ->fillForm([
                'customer_id' => $this->customer()->id,
                'value' => '10.00',
                'city_id' => $tripoli->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['region_id' => 'required']);

        $this->assertSame(0, DeliveryOrder::query()->count());
    }

    public function test_a_city_with_optional_regions_saves_with_or_without_one(): void
    {
        $city = $this->city('ضواحي صبراتة', '30.00', regionRequired: false);
        $region = $this->region($city, 'تليل', null);

        // Omitted — valid, and the field is still offered rather than hidden,
        // because the city does have regions to choose from (PLAN §5.2.4).
        $component = Livewire::test(CreateDeliveryOrder::class)
            ->fillForm(['customer_id' => $this->customer()->id, 'value' => '10.00', 'city_id' => $city->id])
            ->assertFormFieldExists('region_id')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(DeliveryOrder::query()->sole()->region_id);
        unset($component);

        // Chosen — also valid.
        $this->createThroughForm(['city_id' => $city->id, 'region_id' => $region->id]);

        $this->assertSame(
            'تليل',
            DeliveryOrder::query()->latest('id')->first()->region_name,
        );
    }

    public function test_a_city_with_no_regions_hides_the_region_field(): void
    {
        $city = $this->city('الخمس', '20.00', regionRequired: false);

        Livewire::test(CreateDeliveryOrder::class)
            ->fillForm(['customer_id' => $this->customer()->id, 'value' => '10.00', 'city_id' => $city->id])
            // Hidden rather than shown empty: 84 of the catalog's 95 cities have
            // no regions at all, and an empty select beside each of them would be
            // a question with no answers.
            ->assertFormFieldDoesNotExist('region_id')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(DeliveryOrder::query()->sole()->region_id);
    }

    public function test_a_foreign_city_region_is_rejected_when_the_ui_is_bypassed(): void
    {
        $tripoli = $this->city('طرابلس', '15.00', regionRequired: true);
        $benghazi = $this->city('بنغازي', '25.00', regionRequired: true);
        $sarraj = $this->region($tripoli, 'السراج', 's24');
        $this->region($benghazi, 'الكيش', 's48');

        // `fillForm` writes the state directly, which is exactly what a crafted
        // Livewire payload does — the select's own option list is bypassed. The
        // submission is still refused, with a message under the field.
        Livewire::test(CreateDeliveryOrder::class)
            ->fillForm([
                'customer_id' => $this->customer()->id,
                'value' => '10.00',
                'city_id' => $benghazi->id,
                'region_id' => $sarraj->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['region_id']);

        $this->assertSame(0, DeliveryOrder::query()->count());
    }

    // ---------------------------------------------------------------
    // What the list may and may not contain
    // ---------------------------------------------------------------

    public function test_office_pickup_is_not_offered_as_a_delivery_destination(): void
    {
        $tripoli = $this->city('طرابلس', '15.00');
        $pickup = $this->city('إستلام مكتب', '0.00');
        $pickup->forceFill(['fulfilment_kind' => FulfilmentKind::OfficePickup])->save();

        $options = $this->cityOptions(Livewire::test(CreateDeliveryOrder::class));

        $this->assertArrayHasKey($tripoli->id, $options);
        // Active and priced, and still absent: it is a fulfilment mode, not a
        // place, and its flow is a later stage (PLAN §9).
        $this->assertArrayNotHasKey($pickup->id, $options);
    }

    public function test_withdrawn_cities_and_regions_are_not_offered_for_a_new_destination(): void
    {
        $live = $this->city('طرابلس', '15.00', regionRequired: true);
        $withdrawnCity = $this->city('مدينة متوقفة', '20.00');
        $withdrawnCity->forceFill(['is_active' => false])->save();

        $liveRegion = $this->region($live, 'السراج', 's24');
        $withdrawnRegion = $this->region($live, 'منطقة متوقفة', null);
        $withdrawnRegion->forceFill(['is_active' => false])->save();

        $component = Livewire::test(CreateDeliveryOrder::class)
            ->fillForm(['customer_id' => $this->customer()->id, 'value' => '10.00', 'city_id' => $live->id]);

        $this->assertArrayNotHasKey($withdrawnCity->id, $this->cityOptions($component));

        $regions = $this->regionOptions($component);

        $this->assertArrayHasKey($liveRegion->id, $regions);
        $this->assertArrayNotHasKey($withdrawnRegion->id, $regions);
    }

    public function test_an_order_whose_city_was_later_withdrawn_is_still_editable(): void
    {
        $city = $this->city('طرابلس', '15.00', regionRequired: true);
        $region = $this->region($city, 'السراج', 's24');
        $order = $this->order(['city_id' => $city->id, 'region_id' => $region->id, 'value' => '250.00']);

        $city->forceFill(['is_active' => false])->save();
        $region->forceFill(['is_active' => false])->save();

        // The withdrawn pair is re-admitted to the lists for this one record. A
        // select whose options exclude the value it displays renders empty, and
        // saving from that state would strip the destination from the order —
        // making a withdrawal retroactive (PLAN §5.2.7).
        $component = Livewire::test(EditDeliveryOrder::class, ['record' => $order->getRouteKey()])
            ->assertFormSet(['city_id' => $city->id, 'region_id' => $region->id]);

        $this->assertArrayHasKey($city->id, $this->cityOptions($component));
        $this->assertArrayHasKey($region->id, $this->regionOptions($component));

        $component->fillForm(['value' => '300.00'])->call('save')->assertHasNoFormErrors();

        $order->refresh();

        $this->assertSame('300.00', $order->value);
        $this->assertSame($city->id, $order->city_id);
        $this->assertSame($region->id, $order->region_id);
        $this->assertSame('15.00', $order->delivery_fee_lyd);
    }

    // ---------------------------------------------------------------
    // Price display
    // ---------------------------------------------------------------

    public function test_an_unpriced_city_says_so_in_words_and_stores_null(): void
    {
        $city = $this->city('تساوة', null);

        Livewire::test(CreateDeliveryOrder::class)
            ->fillForm(['customer_id' => $this->customer()->id, 'value' => '10.00', 'city_id' => $city->id])
            // Never `0.00 د.ل`: an operator shown a zero would reasonably read it
            // as free delivery, which is the one thing these four cities are not
            // (PLAN §5.2.5).
            ->assertSee(DeliveryOrderForm::UNDETERMINED_PRICE)
            ->assertDontSee('0.00 د.ل')
            ->call('create')
            ->assertHasNoFormErrors();

        $order = DeliveryOrder::query()->sole();

        $this->assertNull($order->delivery_fee_lyd);
        $this->assertSame('تساوة', $order->city_name);

        Livewire::test(ViewDeliveryOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee(DeliveryOrderForm::UNDETERMINED_PRICE);
    }

    public function test_a_city_priced_at_zero_shows_the_amount_and_not_the_undetermined_label(): void
    {
        $city = $this->city('مدينة مجانية', '0.00');

        Livewire::test(CreateDeliveryOrder::class)
            ->fillForm(['customer_id' => $this->customer()->id, 'value' => '10.00', 'city_id' => $city->id])
            ->assertSee('0.00 د.ل')
            ->assertDontSee(DeliveryOrderForm::UNDETERMINED_PRICE);
    }

    // ---------------------------------------------------------------
    // Editing
    // ---------------------------------------------------------------

    public function test_opening_and_reopening_the_form_changes_nothing(): void
    {
        $city = $this->city('طرابلس', '15.00');
        $order = $this->order(['city_id' => $city->id]);

        $city->forceFill(['delivery_price_lyd' => '99.00'])->save();

        $before = $order->fresh()->updated_at;

        Livewire::test(EditDeliveryOrder::class, ['record' => $order->getRouteKey()])->assertSuccessful();
        Livewire::test(ViewDeliveryOrder::class, ['record' => $order->getRouteKey()])->assertSuccessful();

        // Not even a touched timestamp. Reading a record is not an edit of it,
        // and the newer catalog price did not creep in through a render
        // (PLAN §5.2.7).
        $this->assertSame('15.00', $order->fresh()->delivery_fee_lyd);
        $this->assertEquals($before, $order->fresh()->updated_at);
        $this->assertSame(0, IntegrationOutbox::query()->count());
    }

    public function test_changing_the_city_applies_the_new_fee_only_once_the_save_is_submitted(): void
    {
        $tripoli = $this->city('طرابلس', '15.00');
        $misrata = $this->city('مصراتة', '20.00');
        $order = $this->order(['city_id' => $tripoli->id]);

        $component = Livewire::test(EditDeliveryOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['city_id' => $misrata->id])
            // The preview, and the notice naming both amounts, so the operator
            // sees the consequence before confirming it.
            ->assertSee('20.00 د.ل')
            ->assertSee('سيُحدَّث سعر التوصيل من 15.00 د.ل إلى 20.00 د.ل');

        // Nothing has been written yet. The form holds the new city; the order
        // still holds the old one and the old fee.
        $this->assertSame($tripoli->id, $order->fresh()->city_id);
        $this->assertSame('15.00', $order->fresh()->delivery_fee_lyd);

        $component->call('save')->assertHasNoFormErrors();

        $order->refresh();

        $this->assertSame($misrata->id, $order->city_id);
        $this->assertSame('مصراتة', $order->city_name);
        $this->assertSame('20.00', $order->delivery_fee_lyd);
    }

    public function test_an_announced_order_shows_its_destination_disabled_and_keeps_it(): void
    {
        $tripoli = $this->city('طرابلس', '15.00');
        $misrata = $this->city('مصراتة', '20.00');
        $order = $this->order(['city_id' => $tripoli->id, 'value' => '250.00']);

        app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($order, $this->representative());

        $component = Livewire::test(EditDeliveryOrder::class, ['record' => $order->getRouteKey()])
            ->assertFormFieldDisabled('city_id')
            ->assertSee('تم إبلاغ مَسار بهذا الطلب');

        // A disabled field is not dehydrated, so the city never reaches the save
        // path — and if it did, the service would refuse it. The restriction is
        // the one D3 lifts (PLAN §6).
        $component->fillForm(['value' => '275.00', 'city_id' => $misrata->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $order->refresh();

        $this->assertSame('275.00', $order->value);
        $this->assertSame($tripoli->id, $order->city_id);
        $this->assertSame('15.00', $order->delivery_fee_lyd);
    }

    public function test_a_historic_order_with_no_destination_edits_without_being_forced_to_acquire_one(): void
    {
        // Exactly the shape of every order that predates the catalog. The city
        // select is required on *create* only, so no backfill is demanded of a
        // record that legitimately has none (PLAN §5).
        $order = $this->order(['value' => '80.00']);

        Livewire::test(EditDeliveryOrder::class, ['record' => $order->getRouteKey()])
            ->assertFormSet(['city_id' => null])
            ->fillForm(['value' => '95.00', 'location_link' => 'https://maps.app.goo.gl/legacy'])
            ->call('save')
            ->assertHasNoFormErrors();

        $order->refresh();

        $this->assertSame('95.00', $order->value);
        $this->assertSame('https://maps.app.goo.gl/legacy', $order->location_link);
        $this->assertNull($order->city_id);
        $this->assertNull($order->delivery_fee_lyd);
    }

    public function test_the_customer_value_and_location_fields_behave_exactly_as_before(): void
    {
        $city = $this->city('طرابلس', '15.00');
        $customer = $this->customer();

        Livewire::test(CreateDeliveryOrder::class)
            // Coordinates remain absent: Masar owns turning a link into a
            // position (§3.7, D13), and D2 added an administrative destination
            // without touching the geographic one (PLAN §5.2.6).
            ->assertFormFieldDoesNotExist('latitude')
            ->assertFormFieldDoesNotExist('longitude')
            ->fillForm([
                'customer_id' => $customer->id,
                'value' => '312.50',
                'city_id' => $city->id,
                'location_link' => 'https://maps.app.goo.gl/xyz',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = DeliveryOrder::query()->sole();

        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('312.50', $order->value);
        $this->assertSame('https://maps.app.goo.gl/xyz', $order->location_link);
        // Choosing a city did not invent coordinates, and did not touch the link.
        $this->assertNull($order->latitude);
        $this->assertNull($order->longitude);
    }

    public function test_the_save_action_asks_for_confirmation_only_when_the_city_changed(): void
    {
        $tripoli = $this->city('طرابلس', '15.00');
        $misrata = $this->city('مصراتة', '20.00');
        $order = $this->order(['city_id' => $tripoli->id]);

        $component = Livewire::test(EditDeliveryOrder::class, ['record' => $order->getRouteKey()]);

        // An ordinary edit saves in one click, exactly as before D2.
        $component->fillForm(['value' => '260.00']);
        $this->assertFalse($this->saveActionRequiresConfirmation($component));

        // Changing the city asks first, because saving will reprice the order.
        $component->fillForm(['city_id' => $misrata->id]);
        $this->assertTrue($this->saveActionRequiresConfirmation($component));
    }

    private function saveActionRequiresConfirmation(mixed $component): bool
    {
        $page = $component->instance();
        $method = new \ReflectionMethod($page, 'getSaveFormAction');
        $method->setAccessible(true);

        return $method->invoke($page)->isConfirmationRequired();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * The options a Select is currently offering, keyed by id.
     *
     * Read off the live component rather than recomputed from the models: the
     * question these tests ask is what the *form* offers, and rebuilding the
     * query here would test the test.
     *
     * @return array<int|string, string>
     */
    private function optionsOf(mixed $component, string $field): array
    {
        return $component->instance()
            ->form
            ->getComponent(fn ($c): bool => method_exists($c, 'getName') && $c->getName() === $field)
            ->getOptions();
    }

    /** @return array<int|string, string> */
    private function cityOptions(mixed $component): array
    {
        return $this->optionsOf($component, 'city_id');
    }

    /** @return array<int|string, string> */
    private function regionOptions(mixed $component): array
    {
        return $this->optionsOf($component, 'region_id');
    }

    /**
     * @param  array<string, mixed>  $destination
     */
    private function createThroughForm(array $destination): void
    {
        Livewire::test(CreateDeliveryOrder::class)
            ->fillForm(array_merge([
                'customer_id' => $this->customer()->id,
                'value' => '250.00',
            ], $destination))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    private function city(string $name, ?string $price, bool $regionRequired = false): DeliveryCity
    {
        return DeliveryCity::create([
            'source_city_id' => DeliveryCity::query()->max('source_city_id') + 1,
            'name' => $name,
            'delivery_price_lyd' => $price,
            'is_region_required' => $regionRequired,
        ]);
    }

    private function region(DeliveryCity $city, string $name, ?string $code): DeliveryRegion
    {
        return DeliveryRegion::create([
            'source_region_id' => DeliveryRegion::query()->max('source_region_id') + 1,
            'city_id' => $city->id,
            'name' => $name,
            'region_code' => $code,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('09', true),
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes = []): DeliveryOrder
    {
        return DeliveryOrder::create(array_merge([
            'customer_id' => $this->customer()->id,
            'value' => '250.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ], $attributes));
    }

    private function representative(): Representative
    {
        return Representative::create([
            'name' => 'Representative '.uniqid(),
            'phone' => '0920000007',
            'is_active' => true,
        ]);
    }
}
