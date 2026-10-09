<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Models\Customer;
use App\Models\DeliveryCity;
use App\Models\DeliveryOrder;
use App\Models\DeliveryRegion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The destination columns on an order, and the two promises they make.
 *
 * **Old orders still work.** Every order in the table predates the catalog, and
 * the columns were added nullable with no backfill and no default. So an order
 * reads, saves, is assigned and is cancelled with all five of them null, and
 * nothing in the existing lifecycle notices they exist. That is the whole of the
 * compatibility claim, and it is checked here rather than assumed, because the
 * cheapest way to break a live table is to add a column something downstream
 * quietly requires.
 *
 * **A stored order describes itself.** The fee, the city name and the region
 * name are copied onto the order when its destination is set, and the catalog
 * cannot reach them afterwards. Rename a city, reprice it, withdraw it — a
 * delivered order still says what it said, which is what PLAN §4.3 means by a
 * central price change applying only to future orders.
 */
class DeliveryOrderDestinationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_with_no_destination_at_all_remains_entirely_valid(): void
    {
        // Exactly the shape of every row already in the table.
        $order = $this->createOrder();

        $this->assertNull($order->city_id);
        $this->assertNull($order->region_id);
        $this->assertNull($order->city_name);
        $this->assertNull($order->region_name);
        $this->assertNull($order->delivery_fee_lyd);

        $this->assertNull($order->city);
        $this->assertNull($order->region);

        // And it still moves through its lifecycle untouched by any of this.
        $order->forceFill([
            'status' => DeliveryOrderStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        $this->assertSame(DeliveryOrderStatus::Cancelled, $order->refresh()->status);
        $this->assertNull($order->delivery_fee_lyd);
    }

    public function test_a_destination_links_to_the_catalog_and_snapshots_what_it_said(): void
    {
        [$city, $region] = $this->tripoli();

        $order = $this->createOrder([
            'city_id' => $city->id,
            'region_id' => $region->id,
            'city_name' => $city->name,
            'region_name' => $region->name,
            'delivery_fee_lyd' => $city->delivery_price_lyd,
        ]);

        $this->assertTrue($order->city->is($city));
        $this->assertTrue($order->region->is($region));
        $this->assertSame('طرابلس', $order->city_name);
        $this->assertSame('السراج', $order->region_name);
        $this->assertSame('15.00', $order->delivery_fee_lyd);

        // The catalog's price and the order's fee are two columns that happen to
        // agree today.
        $this->assertSame($city->delivery_price_lyd, $order->delivery_fee_lyd);
    }

    public function test_repricing_or_renaming_a_city_does_not_touch_a_stored_order(): void
    {
        [$city, $region] = $this->tripoli();

        $order = $this->createOrder([
            'city_id' => $city->id,
            'region_id' => $region->id,
            'city_name' => $city->name,
            'region_name' => $region->name,
            'delivery_fee_lyd' => $city->delivery_price_lyd,
        ]);

        // The catalog moves on: a new price, a corrected spelling, a withdrawn
        // destination.
        $city->forceFill([
            'delivery_price_lyd' => '40.00',
            'name' => 'طرابلس الكبرى',
            'is_active' => false,
        ])->save();

        $region->forceFill(['name' => 'السراج الجديد'])->save();

        $order->refresh();

        // The order is unmoved. This is the point of storing the names beside
        // the references: an invoice settled at 15.00 for طرابلس/السراج must not
        // re-describe itself because someone fixed a spelling.
        $this->assertSame('15.00', $order->delivery_fee_lyd);
        $this->assertSame('طرابلس', $order->city_name);
        $this->assertSame('السراج', $order->region_name);

        // While the reference still resolves, and resolves to the current row —
        // which is how the catalog's own corrections stay visible without
        // rewriting history.
        $this->assertSame('طرابلس الكبرى', $order->city->name);
        $this->assertSame('40.00', $order->city->delivery_price_lyd);
        $this->assertFalse($order->city->is_active);
    }

    public function test_an_order_may_carry_a_destination_with_no_fee(): void
    {
        // One of the four unpriced cities. The order records where it is going
        // and records that no price was decided — which is not a fee of zero,
        // and is the state a business rule has to act on before assignment
        // (PLAN §9).
        $city = DeliveryCity::create([
            'source_city_id' => 97,
            'name' => 'تساوة',
            'delivery_price_lyd' => null,
            'is_region_required' => false,
        ]);

        $order = $this->createOrder([
            'city_id' => $city->id,
            'city_name' => $city->name,
            'delivery_fee_lyd' => $city->delivery_price_lyd,
        ]);

        $this->assertNull($order->delivery_fee_lyd);
        $this->assertSame('تساوة', $order->city_name);
        $this->assertFalse($order->city->hasDecidedPrice());

        // Distinguishable from a genuine zero in SQL, not merely in PHP.
        $this->assertSame(0, DeliveryOrder::query()->where('delivery_fee_lyd', 0)->count());
        $this->assertSame(1, DeliveryOrder::query()->whereNull('delivery_fee_lyd')->count());
    }

    public function test_a_zero_fee_is_stored_and_read_back_as_a_decided_amount(): void
    {
        $city = DeliveryCity::create([
            'source_city_id' => 1,
            'name' => 'إستلام مكتب',
            'delivery_price_lyd' => '0.00',
            'is_region_required' => false,
        ]);

        $order = $this->createOrder([
            'city_id' => $city->id,
            'city_name' => $city->name,
            'delivery_fee_lyd' => '0.00',
        ]);

        $this->assertSame('0.00', $order->refresh()->delivery_fee_lyd);
        $this->assertNotNull($order->delivery_fee_lyd);
        $this->assertSame(1, DeliveryOrder::query()->where('delivery_fee_lyd', 0)->count());
        $this->assertSame(0, DeliveryOrder::query()->whereNull('delivery_fee_lyd')->count());
    }

    public function test_a_catalog_row_an_order_points_at_cannot_be_deleted(): void
    {
        [$city, $region] = $this->tripoli();

        $this->createOrder([
            'city_id' => $city->id,
            'region_id' => $region->id,
            'city_name' => $city->name,
            'region_name' => $region->name,
            'delivery_fee_lyd' => $city->delivery_price_lyd,
        ]);

        // `restrictOnDelete`, and it should never fire in normal use: the
        // importer does not delete, and withdrawal is `is_active`. It is here so
        // that a hand-written DELETE fails loudly instead of orphaning an
        // order's destination.
        $this->expectException(QueryException::class);

        $region->delete();
    }

    public function test_the_region_relation_does_not_prove_the_region_is_in_the_city(): void
    {
        [$tripoli, $sarraj] = $this->tripoli();

        $benghazi = DeliveryCity::create([
            'source_city_id' => 4,
            'name' => 'بنغازي',
            'delivery_price_lyd' => '25.00',
            'is_region_required' => true,
        ]);

        // The database accepts this: both foreign keys resolve, and neither can
        // see the other. It is recorded here deliberately — the mismatch is
        // refused in the save path in D2 (PLAN §5.2.3), and a test asserting the
        // schema already prevented it would be asserting something false and
        // would let that check go unwritten.
        $order = $this->createOrder([
            'city_id' => $benghazi->id,
            'region_id' => $sarraj->id,
        ]);

        $this->assertTrue($order->city->is($benghazi));
        $this->assertTrue($order->region->is($sarraj));
        $this->assertFalse($order->region->belongsToCity($benghazi->id));
        $this->assertTrue($order->region->belongsToCity($tripoli->id));
    }

    /**
     * @return array{0: DeliveryCity, 1: DeliveryRegion}
     */
    private function tripoli(): array
    {
        $city = DeliveryCity::create([
            'source_city_id' => 2,
            'name' => 'طرابلس',
            'delivery_price_lyd' => '15.00',
            'is_region_required' => true,
            'darb_branch' => 'زناتة، طرابلس',
        ]);

        $region = DeliveryRegion::create([
            'source_region_id' => 27,
            'city_id' => $city->id,
            'name' => 'السراج',
            'region_code' => 's24',
            'darb_branch' => 'السراج، طرابلس',
        ]);

        return [$city, $region];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOrder(array $attributes = []): DeliveryOrder
    {
        $customer = Customer::create([
            'name' => 'Customer',
            'phone' => '0912345678',
            'is_active' => true,
        ]);

        return DeliveryOrder::create(array_merge([
            'customer_id' => $customer->id,
            'value' => '250.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ], $attributes));
    }
}
