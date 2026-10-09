<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Exceptions\InvalidOrderDestinationException;
use App\Models\Customer;
use App\Models\DeliveryCity;
use App\Models\DeliveryOrder;
use App\Models\DeliveryRegion;
use App\Models\IntegrationOutbox;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\DeliveryOrderUpdateService;
use App\Services\Integration\DestinationPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What Mini Delivery sends Masar about an order's destination (CONTRACT §3.7,
 * v5.19 — D3).
 *
 * Two halves, and the first is the one that makes the release safe.
 *
 * **With the rollout flag off**, every payload is byte-for-byte what it was
 * before D3 and the destination of an announced order still cannot be changed.
 * That is not timidity: Masar answers an event it cannot validate with `422`,
 * and §3.21.7 makes every 4xx terminal and never retried — so sending the new
 * fields to a receiver that predates them would lose those orders for good. The
 * receiver deploys first.
 *
 * **With it on**, the assignment carries the destination and the approved fee,
 * and a later destination edit produces one `order.updated` whose
 * `changed_fields` name exactly what moved. The identifiers are the catalog's
 * source ids and never this database's primary keys; the names are the order's
 * own snapshot and never the catalog's current spelling.
 *
 * Throughout: a destination is administrative. It never stamps
 * `location_changed_at`, never touches the coordinates, and never produces an
 * event when nothing moved.
 */
class DestinationSyncProducerTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // The flag off — exactly D2's behaviour
    // ---------------------------------------------------------------

    public function test_with_sync_off_the_assignment_payload_is_the_pre_d3_one(): void
    {
        config(['services.masar.destination_sync' => false]);

        [$order] = $this->tripoli();
        $this->assign($order);

        $snapshot = $this->event('order.assigned')->payload['data']['order'];

        // The two keys it always had, and no third.
        $this->assertEqualsCanonicalizing(['external_order_id', 'amount'], array_keys($snapshot));
        $this->assertArrayNotHasKey('delivery_cost', $snapshot);
        $this->assertArrayNotHasKey('destination', $snapshot);
    }

    public function test_with_sync_off_an_announced_order_still_refuses_a_destination_change(): void
    {
        config(['services.masar.destination_sync' => false]);

        [$order, , $misrata] = $this->tripoli();
        $this->assign($order);

        // D2's restriction, kept while the flag is off: an edit applied here and
        // suppressed on the wire is the silent divergence it existed to prevent.
        $this->expectException(InvalidOrderDestinationException::class);
        $this->expectExceptionMessage('destination synchronisation is off');

        app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
            'value' => $order->value, 'city_id' => $misrata->id, 'region_id' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // The flag on — assignment
    // ---------------------------------------------------------------

    public function test_the_assignment_carries_the_destination_and_the_approved_fee(): void
    {
        config(['services.masar.destination_sync' => true]);

        [$order] = $this->tripoli();
        $this->assign($order);

        $data = $this->event('order.assigned')->payload['data'];

        $this->assertSame('15.00', $data['order']['delivery_cost']);
        $this->assertSame([
            'city_id' => '2',
            'city_name' => 'طرابلس',
            'region_id' => '27',
            'region_name' => 'السراج',
        ], $data['order']['destination']);

        // The identifiers are the catalog's source ids, and the order's internal
        // foreign keys are different numbers — which is the whole point of
        // sending the first and not the second.
        $this->assertNotSame((string) $order->fresh()->city_id, $data['order']['destination']['city_id']);

        // And everything that travelled before still travels.
        $this->assertSame('250.00', $data['order']['amount']);
        $this->assertSame('Ali', $data['customer']['name']);
        $this->assertArrayHasKey('external_courier_id', $data['courier']);
        $this->assertSame('https://maps.app.goo.gl/x', $data['location']['location_link']);
    }

    public function test_an_order_with_no_destination_announces_nulls_rather_than_omitting_the_keys(): void
    {
        config(['services.masar.destination_sync' => true]);

        // Every order placed before the catalog existed. `destination: null` is a
        // statement; an absent key would mean "no information", and §3.7 keeps
        // the two apart.
        $order = $this->order();
        $this->assign($order);

        $snapshot = $this->event('order.assigned')->payload['data']['order'];

        $this->assertArrayHasKey('destination', $snapshot);
        $this->assertNull($snapshot['destination']);
        $this->assertNull($snapshot['delivery_cost']);
    }

    public function test_an_unpriced_city_announces_a_null_fee_and_not_a_zero(): void
    {
        config(['services.masar.destination_sync' => true]);

        $city = $this->city('تساوة', null);
        $order = $this->order(['city_id' => $city->id]);

        // Unassignable under D2's temporary policy, so the fee travels in a
        // snapshot rather than in an assignment — which is exactly where a null
        // must not become a zero.
        $this->assertNull($order->delivery_fee_lyd);

        $payload = DestinationPayload::forSnapshot($order);

        $this->assertNull($payload['delivery_cost']);
        $this->assertSame('تساوة', $payload['destination']['city_name']);
        $this->assertNull($payload['destination']['region_id']);
    }

    public function test_creating_an_order_announces_nothing(): void
    {
        config(['services.masar.destination_sync' => true]);

        [$order] = $this->tripoli();

        // Unchanged from before D3: an order becomes Masar's business when it is
        // handed to a courier, not when it is written down.
        $this->assertSame(0, IntegrationOutbox::query()->count());
        $this->assertNotNull($order->city_id);
    }

    // ---------------------------------------------------------------
    // The flag on — updates
    // ---------------------------------------------------------------

    public function test_a_city_change_produces_one_update_naming_the_city_region_and_fee(): void
    {
        config(['services.masar.destination_sync' => true]);

        [$order, , $misrata] = $this->tripoli();
        $this->assign($order);

        app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
            'value' => $order->value, 'city_id' => $misrata->id, 'region_id' => null,
        ]);

        $event = $this->event('order.updated');
        $changes = $event->payload['data']['changed_fields'];

        $this->assertEqualsCanonicalizing([
            'order.destination.city_id',
            'order.destination.city_name',
            'order.destination.region_id',
            'order.destination.region_name',
            'order.delivery_cost',
        ], array_keys($changes));

        $this->assertEquals(['old' => '2', 'new' => '6'], $changes['order.destination.city_id']);
        $this->assertEquals(['old' => 'طرابلس', 'new' => 'مصراتة'], $changes['order.destination.city_name']);
        $this->assertEquals(['old' => '27', 'new' => null], $changes['order.destination.region_id']);
        $this->assertEquals(['old' => 'السراج', 'new' => null], $changes['order.destination.region_name']);
        $this->assertEquals(['old' => '15.00', 'new' => '20.00'], $changes['order.delivery_cost']);

        // One event, and the snapshot agrees with the change it describes.
        $this->assertSame(1, IntegrationOutbox::query()->where('event_type', 'order.updated')->count());
        $this->assertSame('20.00', $event->payload['data']['current_snapshot']['order']['delivery_cost']);
        $this->assertSame('6', $event->payload['data']['current_snapshot']['order']['destination']['city_id']);
        $this->assertSame(2, (int) $event->order_version);
    }

    public function test_a_region_only_change_declares_the_region_and_not_the_fee(): void
    {
        config(['services.masar.destination_sync' => true]);

        [$order, $tripoli] = $this->tripoli();
        $this->assign($order);

        $farnaj = DeliveryRegion::create([
            'source_region_id' => 8, 'city_id' => $tripoli->id, 'name' => 'الفرناج', 'region_code' => 's21',
        ]);

        // The catalog has moved on since this order was priced; the fee must not
        // follow, and must not be declared as changed either.
        DeliveryCity::query()->whereKey($tripoli->id)->update(['delivery_price_lyd' => '45.00']);

        app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
            'value' => $order->value, 'city_id' => $tripoli->id, 'region_id' => $farnaj->id,
        ]);

        $changes = $this->event('order.updated')->payload['data']['changed_fields'];

        $this->assertEqualsCanonicalizing([
            'order.destination.region_id', 'order.destination.region_name',
        ], array_keys($changes));
        $this->assertArrayNotHasKey('order.delivery_cost', $changes);
        $this->assertEquals(['old' => '27', 'new' => '8'], $changes['order.destination.region_id']);
        $this->assertSame('15.00', $order->fresh()->delivery_fee_lyd);
    }

    public function test_a_destination_change_is_not_a_location_change(): void
    {
        config(['services.masar.destination_sync' => true]);

        [$order, , $misrata] = $this->tripoli();
        $this->assign($order);

        $before = $order->fresh();

        app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
            'value' => $order->value, 'city_id' => $misrata->id, 'region_id' => null,
        ]);

        $after = $order->fresh();

        // The city is administrative and the link is a position (PLAN §1).
        // Choosing «مصراتة» moves no pin, so no stamp, no coordinates, no
        // location paths on the wire.
        $this->assertSame($before->location_changed_at, $after->location_changed_at);
        $this->assertNull($after->location_change_source);
        $this->assertSame($before->latitude, $after->latitude);
        $this->assertSame($before->longitude, $after->longitude);
        $this->assertSame($before->location_link, $after->location_link);

        $changes = array_keys($this->event('order.updated')->payload['data']['changed_fields']);

        $this->assertSame([], array_intersect(
            ['location.location_link', 'location.latitude', 'location.longitude'],
            $changes,
        ));
    }

    public function test_a_combined_destination_value_and_location_edit_produces_one_event(): void
    {
        config(['services.masar.destination_sync' => true]);

        [$order, , $misrata] = $this->tripoli();
        $this->assign($order);

        app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
            'value' => '400.00',
            'location_link' => 'https://maps.app.goo.gl/moved',
            'city_id' => $misrata->id,
            'region_id' => null,
        ]);

        $events = IntegrationOutbox::query()->where('event_type', 'order.updated')->get();

        $this->assertCount(1, $events);

        $changes = $events->sole()->payload['data']['changed_fields'];

        // One consistent event: the amount, the link and the whole destination.
        $this->assertArrayHasKey('order.amount', $changes);
        $this->assertArrayHasKey('location.location_link', $changes);
        $this->assertArrayHasKey('order.destination.city_id', $changes);
        $this->assertArrayHasKey('order.delivery_cost', $changes);

        // And a real location change still stamps, because one did happen here.
        $this->assertNotNull($order->fresh()->location_changed_at);
    }

    public function test_a_no_op_save_produces_no_event(): void
    {
        config(['services.masar.destination_sync' => true]);

        [$order, $tripoli] = $this->tripoli();
        $region = DeliveryRegion::query()->where('source_region_id', 27)->sole();
        $this->assign($order);

        $before = IntegrationOutbox::query()->count();
        $updatedAt = $order->fresh()->updated_at;

        app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
            'value' => '250.00',
            'location_link' => 'https://maps.app.goo.gl/x',
            'city_id' => $tripoli->id,
            'region_id' => $region->id,
        ]);

        $this->assertSame($before, IntegrationOutbox::query()->count());
        $this->assertEquals($updatedAt, $order->fresh()->updated_at);
    }

    public function test_a_destination_edit_before_assignment_still_announces_nothing(): void
    {
        config(['services.masar.destination_sync' => true]);

        [$order, , $misrata] = $this->tripoli();

        app(DeliveryOrderUpdateService::class)->update($order->fresh(), [
            'value' => $order->value, 'city_id' => $misrata->id, 'region_id' => null,
        ]);

        // Saved locally, nothing on the wire: Masar has never heard of this
        // order. The first assignment's snapshot will carry the new destination.
        $this->assertSame(0, IntegrationOutbox::query()->count());
        $this->assertSame('مصراتة', $order->fresh()->city_name);

        $this->assign($order->fresh());

        $this->assertSame(
            '6',
            $this->event('order.assigned')->payload['data']['order']['destination']['city_id'],
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function event(string $type): IntegrationOutbox
    {
        return IntegrationOutbox::query()->where('event_type', $type)->latest('id')->firstOrFail();
    }

    /** @return array{0: DeliveryOrder, 1: DeliveryCity, 2: DeliveryCity} */
    private function tripoli(): array
    {
        $tripoli = DeliveryCity::create([
            'source_city_id' => 2, 'name' => 'طرابلس', 'delivery_price_lyd' => '15.00', 'is_region_required' => true,
        ]);
        $misrata = DeliveryCity::create([
            'source_city_id' => 6, 'name' => 'مصراتة', 'delivery_price_lyd' => '20.00', 'is_region_required' => false,
        ]);
        $sarraj = DeliveryRegion::create([
            'source_region_id' => 27, 'city_id' => $tripoli->id, 'name' => 'السراج', 'region_code' => 's24',
        ]);

        return [
            $this->order(['city_id' => $tripoli->id, 'region_id' => $sarraj->id]),
            $tripoli,
            $misrata,
        ];
    }

    private function city(string $name, ?string $price): DeliveryCity
    {
        return DeliveryCity::create([
            'source_city_id' => (DeliveryCity::query()->max('source_city_id') ?? 0) + 1,
            'name' => $name, 'delivery_price_lyd' => $price, 'is_region_required' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes = []): DeliveryOrder
    {
        $customer = Customer::create([
            'name' => 'Ali', 'phone' => uniqid('09', true), 'is_active' => true,
        ]);

        return DeliveryOrder::create(array_merge([
            'customer_id' => $customer->id,
            'value' => '250.00',
            'location_link' => 'https://maps.app.goo.gl/x',
            'status' => DeliveryOrderStatus::NewOrder,
        ], $attributes));
    }

    private function assign(DeliveryOrder $order): void
    {
        $representative = Representative::create([
            'name' => 'Rep '.uniqid(), 'phone' => '0920000007', 'is_active' => true,
        ]);

        app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $representative);
    }
}
