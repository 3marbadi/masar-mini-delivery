<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\FulfilmentKind;
use App\Exceptions\InvalidDeliveryOrderTransitionException;
use App\Exceptions\InvalidOrderDestinationException;
use App\Models\Customer;
use App\Models\DeliveryCity;
use App\Models\DeliveryOrder;
use App\Models\DeliveryRegion;
use App\Models\IntegrationOutbox;
use App\Models\OrderIntegrationState;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\DeliveryOrderUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The destination rules at the persistence boundary, with no form involved
 * (PLAN D2 §5.2.3, §4.3, §6).
 *
 * Everything here goes through `DeliveryOrder::create()`, `->save()` or the two
 * services — which is to say through the paths a second interface, a console
 * command or a crafted request would take. That is deliberate and it is the
 * whole value of the file: a test that drove the Filament form would prove the
 * form configures its selects correctly and would prove nothing about what the
 * database will accept. The companion file does the form; this one does the
 * guarantee.
 *
 * Four groups: what a destination may be, who decides the price, what a saved
 * order keeps, and what may not change once Masar has been told.
 */
class OrderDestinationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // What a destination may be
    // ---------------------------------------------------------------

    public function test_a_region_from_another_city_is_refused_by_every_save_path(): void
    {
        $tripoli = $this->city('طرابلس', '15.00', regionRequired: true);
        $benghazi = $this->city('بنغازي', '25.00', regionRequired: true);
        $sarraj = $this->region($tripoli, 'السراج', 's24');

        // On create.
        try {
            $this->order(['city_id' => $benghazi->id, 'region_id' => $sarraj->id]);
            $this->fail('A region of another city was accepted on create.');
        } catch (InvalidOrderDestinationException $e) {
            $this->assertStringContainsString('belongs to another city', $e->getMessage());
        }

        $this->assertSame(0, DeliveryOrder::query()->count());

        // And on edit of a coherent order, which is the subtler half: the
        // pairing breaks just as easily by moving the city as by moving the
        // region, so both have to be re-checked whenever either moves.
        $order = $this->order(['city_id' => $tripoli->id, 'region_id' => $sarraj->id]);

        $this->expectException(InvalidOrderDestinationException::class);

        $order->forceFill(['city_id' => $benghazi->id])->save();
    }

    public function test_a_city_that_requires_a_region_cannot_be_stored_without_one(): void
    {
        $tripoli = $this->city('طرابلس', '15.00', regionRequired: true);

        $this->expectException(InvalidOrderDestinationException::class);
        $this->expectExceptionMessage('requires a region');

        $this->order(['city_id' => $tripoli->id]);
    }

    public function test_a_city_with_optional_regions_is_valid_with_and_without_one(): void
    {
        // «ضواحي صبراتة» in the real catalog: seven regions, none demanded.
        $city = $this->city('ضواحي صبراتة', '30.00', regionRequired: false);
        $region = $this->region($city, 'تليل', null);

        $without = $this->order(['city_id' => $city->id]);

        $this->assertNull($without->region_id);
        $this->assertNull($without->region_name);
        $this->assertSame('30.00', $without->delivery_fee_lyd);

        $with = $this->order(['city_id' => $city->id, 'region_id' => $region->id]);

        $this->assertSame($region->id, $with->region_id);
        $this->assertSame('تليل', $with->region_name);
        // Having a region changes nothing about the price: the catalog prices
        // cities (PLAN §4.3).
        $this->assertSame('30.00', $with->delivery_fee_lyd);
    }

    public function test_a_region_cannot_be_stored_without_its_city(): void
    {
        $city = $this->city('طرابلس', '15.00');
        $region = $this->region($city, 'السراج', 's24');

        $this->expectException(InvalidOrderDestinationException::class);
        $this->expectExceptionMessage('without the city it belongs to');

        $this->order(['region_id' => $region->id]);
    }

    public function test_a_withdrawn_city_or_region_cannot_be_chosen_afresh(): void
    {
        $city = $this->city('مدينة متوقفة', '20.00');
        $city->forceFill(['is_active' => false])->save();

        try {
            $this->order(['city_id' => $city->id]);
            $this->fail('An inactive city was accepted.');
        } catch (InvalidOrderDestinationException $e) {
            $this->assertStringContainsString('not active', $e->getMessage());
        }

        $live = $this->city('طرابلس', '15.00');
        $region = $this->region($live, 'منطقة متوقفة', null);
        $region->forceFill(['is_active' => false])->save();

        $this->expectException(InvalidOrderDestinationException::class);
        $this->expectExceptionMessage('not active');

        $this->order(['city_id' => $live->id, 'region_id' => $region->id]);
    }

    public function test_office_pickup_cannot_be_chosen_as_a_delivery_destination(): void
    {
        $pickup = $this->city('إستلام مكتب', '0.00');
        $pickup->forceFill(['fulfilment_kind' => FulfilmentKind::OfficePickup])->save();

        // Active, real, priced — and still not a place a delivery goes. The
        // refusal is on the kind, so it survives the row being perfectly valid
        // in every other respect (PLAN §9).
        $this->assertTrue($pickup->fresh()->is_active);

        $this->expectException(InvalidOrderDestinationException::class);
        $this->expectExceptionMessage('office_pickup');

        $this->order(['city_id' => $pickup->id]);
    }

    public function test_withdrawing_a_city_does_not_freeze_the_orders_that_already_use_it(): void
    {
        $city = $this->city('طرابلس', '15.00');
        $order = $this->order(['city_id' => $city->id, 'value' => '100.00']);

        $city->forceFill(['is_active' => false])->save();

        // `is_active` is checked against the city being *chosen*, not the one
        // stored, so an unrelated edit to an order that already carries it still
        // saves. Freezing these orders would make a withdrawal retroactive
        // (PLAN §5.2.7).
        $order->forceFill(['value' => '120.00'])->save();

        $this->assertSame('120.00', $order->fresh()->value);
        $this->assertSame($city->id, $order->fresh()->city_id);
        $this->assertSame('15.00', $order->fresh()->delivery_fee_lyd);

        // And it can still be completed and cancelled.
        app(DeliveryOrderLifecycleService::class)->cancel($order->fresh());

        $this->assertSame(DeliveryOrderStatus::Cancelled, $order->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Who decides the price
    // ---------------------------------------------------------------

    public function test_the_server_sets_the_fee_and_the_names_and_discards_what_the_client_sent(): void
    {
        $tripoli = $this->city('طرابلس', '15.00', regionRequired: true);
        $sarraj = $this->region($tripoli, 'السراج', 's24');

        // Everything a crafted post could carry: a flattering fee and names that
        // are not the catalog's. All three are overwritten from the catalog, so a
        // disabled field re-enabled in a browser buys nothing (PLAN §5.1).
        $order = $this->order([
            'city_id' => $tripoli->id,
            'region_id' => $sarraj->id,
            'city_name' => 'مدينة مختلقة',
            'region_name' => 'منطقة مختلقة',
            'delivery_fee_lyd' => '0.00',
        ]);

        $this->assertSame('طرابلس', $order->city_name);
        $this->assertSame('السراج', $order->region_name);
        $this->assertSame('15.00', $order->delivery_fee_lyd);
    }

    public function test_a_fee_posted_without_a_city_is_discarded_rather_than_stored(): void
    {
        // The hole a dirtiness check alone would leave: `city_id` never moves, so
        // nothing looks like it needs recomputing, and a client-supplied fee
        // would survive onto an order with nowhere to go. A new record is always
        // stamped, which closes it.
        $order = $this->order(['delivery_fee_lyd' => '999.00', 'city_name' => 'مختلقة']);

        $this->assertNull($order->delivery_fee_lyd);
        $this->assertNull($order->city_name);
        $this->assertNull($order->city_id);
    }

    public function test_an_unpriced_city_stores_null_and_not_zero(): void
    {
        $city = $this->city('تساوة', null);

        $order = $this->order(['city_id' => $city->id]);

        $this->assertNull($order->delivery_fee_lyd);
        $this->assertSame('تساوة', $order->city_name);

        // Distinguishable in SQL, which is what keeps it out of any report that
        // sums fees as though zero meant free.
        $this->assertSame(1, DeliveryOrder::query()->whereNull('delivery_fee_lyd')->count());
        $this->assertSame(0, DeliveryOrder::query()->where('delivery_fee_lyd', 0)->count());
    }

    public function test_clearing_the_destination_clears_the_fee_with_it(): void
    {
        $city = $this->city('طرابلس', '15.00');
        $order = $this->order(['city_id' => $city->id]);

        $this->assertSame('15.00', $order->delivery_fee_lyd);

        // An order with no destination must not keep a fee for a place it is no
        // longer going to.
        $order->forceFill(['city_id' => null])->save();

        $this->assertNull($order->fresh()->delivery_fee_lyd);
        $this->assertNull($order->fresh()->city_name);
        $this->assertNull($order->fresh()->region_name);
    }

    // ---------------------------------------------------------------
    // What a saved order keeps
    // ---------------------------------------------------------------

    public function test_a_catalog_price_change_does_not_reprice_a_saved_order(): void
    {
        $city = $this->city('طرابلس', '15.00');
        $order = $this->order(['city_id' => $city->id]);

        $city->forceFill(['delivery_price_lyd' => '40.00', 'name' => 'طرابلس الكبرى'])->save();

        // Not even when the order is saved again for an unrelated reason:
        // repricing is triggered by the city on the order moving, never by the
        // catalog moving underneath it (PLAN §4.3).
        $order->forceFill(['value' => '300.00'])->save();

        $this->assertSame('15.00', $order->fresh()->delivery_fee_lyd);
        $this->assertSame('طرابلس', $order->fresh()->city_name);
    }

    public function test_re_saving_an_unchanged_order_preserves_the_snapshot_and_writes_nothing(): void
    {
        $city = $this->city('طرابلس', '15.00', regionRequired: true);
        $region = $this->region($city, 'السراج', 's24');
        $order = $this->order(['city_id' => $city->id, 'region_id' => $region->id, 'value' => '250.00']);

        $city->forceFill(['delivery_price_lyd' => '99.00'])->save();

        $updatedAt = $order->fresh()->updated_at;

        // The same values the form was loaded with, submitted back unchanged.
        $result = app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
            'value' => '250.00',
            'location_link' => null,
            'city_id' => $city->id,
            'region_id' => $region->id,
        ]);

        $this->assertSame('15.00', $result->delivery_fee_lyd);
        $this->assertSame('طرابلس', $result->city_name);
        $this->assertSame('السراج', $result->region_name);

        // Not touched at all — which is the strongest form of "preserved", and
        // also why reopening a form cannot mutate anything (PLAN §5.2.7).
        $this->assertEquals($updatedAt, $result->fresh()->updated_at);
        $this->assertSame(0, IntegrationOutbox::query()->count());
    }

    public function test_changing_only_the_region_moves_the_region_name_and_not_the_fee(): void
    {
        $city = $this->city('طرابلس', '15.00', regionRequired: true);
        $sarraj = $this->region($city, 'السراج', 's24');
        $farnaj = $this->region($city, 'الفرناج', 's21');

        $order = $this->order(['city_id' => $city->id, 'region_id' => $sarraj->id]);

        // The catalog has moved on since this order was priced.
        $city->forceFill(['delivery_price_lyd' => '45.00'])->save();

        $result = app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
            'value' => $order->value,
            'city_id' => $city->id,
            'region_id' => $farnaj->id,
        ]);

        $this->assertSame($farnaj->id, $result->region_id);
        $this->assertSame('الفرناج', $result->region_name);

        // The fee did not follow. Re-reading the city's price "while we are
        // here" would import the catalog's current figure into an order agreed at
        // an older one, and a region change is no reason to reprice anything.
        $this->assertSame('15.00', $result->delivery_fee_lyd);
    }

    public function test_changing_the_city_reprices_from_the_catalog_on_save(): void
    {
        $tripoli = $this->city('طرابلس', '15.00');
        $misrata = $this->city('مصراتة', '20.00');

        $order = $this->order(['city_id' => $tripoli->id]);

        $result = app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
            'value' => $order->value,
            'city_id' => $misrata->id,
            'region_id' => null,
        ]);

        $this->assertSame($misrata->id, $result->city_id);
        $this->assertSame('مصراتة', $result->city_name);
        $this->assertSame('20.00', $result->delivery_fee_lyd);
    }

    public function test_a_region_only_edit_mints_no_event_and_does_not_move_the_order_version(): void
    {
        $city = $this->city('طرابلس', '15.00', regionRequired: true);
        $sarraj = $this->region($city, 'السراج', 's24');
        $farnaj = $this->region($city, 'الفرناج', 's21');
        $order = $this->order(['city_id' => $city->id, 'region_id' => $sarraj->id]);

        app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
            'value' => $order->value,
            'city_id' => $city->id,
            'region_id' => $farnaj->id,
        ]);

        // Nothing on the wire, and nothing pretending to be: the v1.0 contract
        // has no destination paths, so there is no truthful `order.updated` to
        // send and none is minted (PLAN §6). No outbox row, no integration state,
        // no version.
        $this->assertSame(0, IntegrationOutbox::query()->count());
        $this->assertSame(0, OrderIntegrationState::query()->count());
        $this->assertSame('الفرناج', $order->fresh()->region_name);
    }

    // ---------------------------------------------------------------
    // Once Masar has been told
    // ---------------------------------------------------------------

    public function test_an_announced_order_cannot_change_its_destination(): void
    {
        $tripoli = $this->city('طرابلس', '15.00');
        $misrata = $this->city('مصراتة', '20.00');
        $order = $this->order(['city_id' => $tripoli->id]);

        app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($order, $this->representative());

        $this->assertTrue($order->fresh()->hasBeenAnnouncedToMasar());

        try {
            app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
                'value' => $order->value,
                'city_id' => $misrata->id,
            ]);
            $this->fail('The destination of an announced order was changed.');
        } catch (InvalidOrderDestinationException $e) {
            $this->assertStringContainsString('already been announced', $e->getMessage());
            $this->assertStringContainsString('D3', $e->getMessage());
        }

        // Unchanged, and no event was minted describing a change that did not
        // happen.
        $this->assertSame($tripoli->id, $order->fresh()->city_id);
        $this->assertSame('15.00', $order->fresh()->delivery_fee_lyd);
        $this->assertSame(1, IntegrationOutbox::query()->count());
        $this->assertSame(1, (int) OrderIntegrationState::query()->sole()->current_version);
    }

    public function test_an_announced_order_can_still_have_its_other_fields_edited(): void
    {
        $tripoli = $this->city('طرابلس', '15.00');
        $order = $this->order(['city_id' => $tripoli->id, 'value' => '250.00']);

        app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($order, $this->representative());

        // The restriction is on the destination and on nothing else. The value
        // and the location link keep publishing exactly as they did before D2,
        // through the paths the v1.0 contract already has (PLAN §6).
        $result = app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
            'value' => '275.00',
            'location_link' => 'https://maps.app.goo.gl/abc',
            'city_id' => $tripoli->id,
            'region_id' => null,
        ]);

        $this->assertSame('275.00', $result->value);
        $this->assertSame('https://maps.app.goo.gl/abc', $result->location_link);

        $updated = IntegrationOutbox::query()->where('event_type', 'order.updated')->sole();
        $paths = array_keys($updated->payload['data']['changed_fields']);

        $this->assertSame(['order.amount', 'location.location_link'], $paths);

        // The contract's shape is untouched: the order snapshot still carries
        // those two keys and no others, so no destination or `delivery_cost`
        // leaked into a v1.0 payload. Adding them is D3's change to make.
        $this->assertEqualsCanonicalizing(
            ['external_order_id', 'amount'],
            array_keys($updated->payload['data']['current_snapshot']['order']),
        );
    }

    // ---------------------------------------------------------------
    // The temporary policy for unpriced destinations
    // ---------------------------------------------------------------

    public function test_a_new_order_for_an_unpriced_city_cannot_be_assigned_to_a_courier(): void
    {
        $city = $this->city('ضواحي الزاوية', null, regionRequired: true);
        $region = $this->region($city, 'ابوصرة', null);
        $order = $this->order(['city_id' => $city->id, 'region_id' => $region->id]);

        // It saved, it is visible, and it says plainly that it has no price.
        $this->assertNull($order->delivery_fee_lyd);
        $this->assertSame(DeliveryOrderStatus::NewOrder, $order->status);

        try {
            app(DeliveryOrderLifecycleService::class)
                ->assignRepresentative($order, $this->representative());
            $this->fail('An unpriced order was assigned to a courier.');
        } catch (InvalidDeliveryOrderTransitionException $e) {
            $this->assertStringContainsString('no decided delivery price', $e->getMessage());
        }

        $this->assertSame(DeliveryOrderStatus::NewOrder, $order->fresh()->status);
        $this->assertNull($order->fresh()->representative_id);

        // And nothing was announced, so Masar never heard of a commitment that
        // was refused.
        $this->assertSame(0, IntegrationOutbox::query()->count());
    }

    public function test_a_historic_order_with_no_destination_is_still_assignable(): void
    {
        // The whole reason the guard tests `city_id` and not the fee alone. Every
        // order placed before this catalog existed has a null fee because it has
        // no destination — not because its price is undecided — and refusing them
        // all would take a working system off the road (PLAN §5).
        $legacy = $this->order();

        $this->assertNull($legacy->city_id);
        $this->assertNull($legacy->delivery_fee_lyd);

        $assigned = app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($legacy, $this->representative());

        $this->assertSame(DeliveryOrderStatus::Assigned, $assigned->status);
        $this->assertSame(1, IntegrationOutbox::query()->count());
    }

    public function test_an_order_for_a_priced_city_is_assignable_as_normal(): void
    {
        $city = $this->city('طرابلس', '15.00');
        $order = $this->order(['city_id' => $city->id]);

        $assigned = app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($order, $this->representative());

        $this->assertSame(DeliveryOrderStatus::Assigned, $assigned->status);
        $this->assertSame('15.00', $assigned->delivery_fee_lyd);
    }

    public function test_an_order_for_a_city_priced_at_zero_is_assignable(): void
    {
        // `0.00` is a decided price. Only null is undecided, and the guard reads
        // the difference rather than a truthiness test that would fold them
        // together.
        $city = $this->city('مدينة مجانية', '0.00');
        $order = $this->order(['city_id' => $city->id]);

        $assigned = app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($order, $this->representative());

        $this->assertSame(DeliveryOrderStatus::Assigned, $assigned->status);
        $this->assertSame('0.00', $assigned->delivery_fee_lyd);
    }

    public function test_an_unpriced_order_cannot_be_reassigned_either(): void
    {
        $priced = $this->city('طرابلس', '15.00');
        $unpriced = $this->city('هراوة', null);
        $order = $this->order(['city_id' => $priced->id]);

        $lifecycle = app(DeliveryOrderLifecycleService::class);
        $lifecycle->assignRepresentative($order, $this->representative('First'));

        // The destination is frozen once announced, so reaching an unpriced
        // assigned order through the form is impossible. Forced here to prove the
        // guard is on assignment itself: moving such an order between couriers
        // does not make it priced.
        $order->fresh()->forceFill([
            'city_id' => $unpriced->id,
            'city_name' => 'هراوة',
            'delivery_fee_lyd' => null,
        ])->saveQuietly();

        $this->expectException(InvalidDeliveryOrderTransitionException::class);
        $this->expectExceptionMessage('no decided delivery price');

        $lifecycle->assignRepresentative($order->fresh(), $this->representative('Second'));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes = []): DeliveryOrder
    {
        $customer = Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('09', true),
            'is_active' => true,
        ]);

        return DeliveryOrder::create(array_merge([
            'customer_id' => $customer->id,
            'value' => '250.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ], $attributes));
    }

    private function representative(string $name = 'Representative'): Representative
    {
        return Representative::create([
            'name' => $name.' '.uniqid(),
            'phone' => '0920000007',
            'is_active' => true,
        ]);
    }
}
