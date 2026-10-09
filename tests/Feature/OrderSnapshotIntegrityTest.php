<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Exceptions\InvalidOrderDestinationException;
use App\Models\Customer;
use App\Models\DeliveryCity;
use App\Models\DeliveryOrder;
use App\Models\DeliveryRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The destination snapshot cannot be edited on its own (D3, closing a D2 gap).
 *
 * **The defect, as it was found.** D2 derived `city_name`, `region_name` and
 * `delivery_fee_lyd` from the catalog whenever `city_id` or `region_id` moved,
 * and returned early when neither did — while leaving all three mass assignable.
 * So an ordinary, supported Eloquent write moved none of the guarded columns and
 * took the early return:
 *
 * ```php
 * $order->update(['delivery_fee_lyd' => '0.01', 'city_name' => 'مدينة مزيفة']);
 * ```
 *
 * That stored a fee nobody approved, and a city name the order was never sent
 * to, on an order whose destination was never touched. Both are reproduced here
 * as the requests they were, and both are now refused.
 *
 * **Where the fix is, and why not in `$fillable`.** Narrowing the fillable list
 * would have broken fixtures that legitimately pass these columns at creation —
 * where they are overwritten from the catalog anyway — and would not have closed
 * the hole, because `forceFill()` never consulted `$fillable`. The rule is "the
 * snapshot is derived, never supplied", and the only place that holds for every
 * write is the save path. Mass assignment is therefore untouched, and the
 * invariant sits in the model's `saving` hook.
 *
 * The three cases that must keep working are checked here too, because a guard
 * that also blocked them would be worse than the gap: a legitimate city change
 * still reprices, a region-only change still keeps its agreed fee, and an
 * unrelated edit still saves.
 */
class OrderSnapshotIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_forged_fee_through_update_is_refused(): void
    {
        $order = $this->tripoliOrder();

        // The exact call that used to succeed.
        try {
            $order->update(['delivery_fee_lyd' => '0.01']);
            $this->fail('A standalone fee edit was accepted.');
        } catch (InvalidOrderDestinationException $e) {
            $this->assertStringContainsString('destination snapshot', $e->getMessage());
            $this->assertStringContainsString('delivery_fee_lyd', $e->getMessage());
        }

        $this->assertSame('15.00', $order->fresh()->delivery_fee_lyd);
    }

    public function test_forged_destination_names_through_update_are_refused(): void
    {
        $order = $this->tripoliOrder();

        try {
            $order->update(['city_name' => 'مدينة مزيفة', 'region_name' => 'منطقة مزيفة']);
            $this->fail('A standalone name edit was accepted.');
        } catch (InvalidOrderDestinationException $e) {
            $this->assertStringContainsString('city_name', $e->getMessage());
            $this->assertStringContainsString('region_name', $e->getMessage());
        }

        $order->refresh();

        $this->assertSame('طرابلس', $order->city_name);
        $this->assertSame('السراج', $order->region_name);
    }

    public function test_a_forged_snapshot_through_fill_and_save_is_refused(): void
    {
        $order = $this->tripoliOrder();

        $this->expectException(InvalidOrderDestinationException::class);

        $order->fill(['delivery_fee_lyd' => '0.02'])->save();
    }

    public function test_a_forged_snapshot_through_force_fill_is_refused_too(): void
    {
        $order = $this->tripoliOrder();

        // `forceFill` bypasses `$fillable`, which is exactly why narrowing that
        // list would not have been a fix. The hook sees the dirty column either
        // way.
        $this->expectException(InvalidOrderDestinationException::class);

        $order->forceFill(['delivery_fee_lyd' => '0.03'])->save();
    }

    public function test_an_unrelated_edit_still_saves_and_leaves_the_snapshot_alone(): void
    {
        $order = $this->tripoliOrder();

        $order->update(['value' => '400.00']);

        $order->refresh();

        $this->assertSame('400.00', $order->value);
        $this->assertSame('15.00', $order->delivery_fee_lyd);
        $this->assertSame('طرابلس', $order->city_name);
    }

    public function test_a_legitimate_city_change_still_reprices(): void
    {
        $order = $this->tripoliOrder();
        $misrata = $this->city('مصراتة', '20.00');

        $order->update(['city_id' => $misrata->id, 'region_id' => null]);

        $order->refresh();

        $this->assertSame('مصراتة', $order->city_name);
        $this->assertNull($order->region_name);
        $this->assertSame('20.00', $order->delivery_fee_lyd);
    }

    public function test_a_region_only_change_still_keeps_the_agreed_fee(): void
    {
        $order = $this->tripoliOrder();
        $farnaj = DeliveryRegion::create([
            'source_region_id' => 8, 'city_id' => $order->city_id, 'name' => 'الفرناج', 'region_code' => 's21',
        ]);

        // The catalog has moved on since this order was priced.
        DeliveryCity::query()->whereKey($order->city_id)->update(['delivery_price_lyd' => '45.00']);

        $order->update(['region_id' => $farnaj->id]);

        $order->refresh();

        $this->assertSame('الفرناج', $order->region_name);
        $this->assertSame('15.00', $order->delivery_fee_lyd);
    }

    public function test_a_create_discards_a_supplied_snapshot_rather_than_refusing_it(): void
    {
        // A new record has no stored value to corrupt and is stamped
        // unconditionally, so a supplied fee is overwritten from the catalog
        // rather than rejected. That keeps every fixture that passes these
        // columns at creation working, which is the half of the problem a
        // blanket refusal would have broken.
        $city = $this->city('طرابلس', '15.00');

        $order = $this->order([
            'city_id' => $city->id,
            'city_name' => 'مدينة مختلقة',
            'delivery_fee_lyd' => '0.00',
        ]);

        $this->assertSame('طرابلس', $order->city_name);
        $this->assertSame('15.00', $order->delivery_fee_lyd);
    }

    private function tripoliOrder(): DeliveryOrder
    {
        $city = $this->city('طرابلس', '15.00', regionRequired: true);
        $region = DeliveryRegion::create([
            'source_region_id' => 27, 'city_id' => $city->id, 'name' => 'السراج', 'region_code' => 's24',
        ]);

        return $this->order(['city_id' => $city->id, 'region_id' => $region->id]);
    }

    private function city(string $name, ?string $price, bool $regionRequired = false): DeliveryCity
    {
        return DeliveryCity::create([
            'source_city_id' => (DeliveryCity::query()->max('source_city_id') ?? 0) + 1,
            'name' => $name,
            'delivery_price_lyd' => $price,
            'is_region_required' => $regionRequired,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes = []): DeliveryOrder
    {
        $customer = Customer::create([
            'name' => 'Customer '.uniqid(), 'phone' => uniqid('09', true), 'is_active' => true,
        ]);

        return DeliveryOrder::create(array_merge([
            'customer_id' => $customer->id,
            'value' => '250.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ], $attributes));
    }
}
