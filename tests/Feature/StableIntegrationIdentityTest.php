<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\IntegrationEventType;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\Representative;
use App\Services\CustomerUpdateService;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\DeliveryOrderUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The identity Masar keeps, and the identity it must never be given.
 *
 * Every `external_*` field on the wire names a row that lives in a database
 * which can be rebuilt, restored elsewhere or replaced. The auto-increment key
 * cannot survive any of those: the next courier is handed the integer the last
 * one had, and Masar — which keys its mapping on
 * `(integration_client_id, external_courier_id)` — resolves the new person to
 * the old representative without raising anything, because that is its success
 * branch. These tests hold the column that replaced it to the three properties
 * the defect needed: it exists on every row, it never moves once written, and a
 * rebuilt database cannot mint it again.
 */
class StableIntegrationIdentityTest extends TestCase
{
    use RefreshDatabase;

    /** RFC 9562 §5.7 — 36 characters, version nibble 7, variant 10xx. */
    private const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-06 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- representative

    public function test_representative_receives_a_uuid_v7_on_creation(): void
    {
        $representative = $this->representative();

        $this->assertMatchesRegularExpression(self::UUID_V7, $representative->integration_uid);
    }

    public function test_two_representatives_receive_different_identities(): void
    {
        $this->assertNotSame(
            $this->representative('First')->integration_uid,
            $this->representative('Second')->integration_uid,
        );
    }

    public function test_representative_identity_survives_name_phone_and_activation_changes(): void
    {
        $representative = $this->representative();
        $original = $representative->integration_uid;

        // Each of the three is a reason someone would edit a courier, and none
        // of them is a reason the courier became a different person.
        $representative->update(['name' => 'Renamed Courier']);
        $this->assertSame($original, $representative->fresh()->integration_uid);

        $representative->update(['phone' => '+218910009999']);
        $this->assertSame($original, $representative->fresh()->integration_uid);

        $representative->update(['is_active' => false]);
        $this->assertSame($original, $representative->fresh()->integration_uid);
    }

    // ---------------------------------------------------------------------- customer

    public function test_customer_receives_a_uuid_v7_on_creation(): void
    {
        $customer = $this->customer();

        $this->assertMatchesRegularExpression(self::UUID_V7, $customer->integration_uid);
        $this->assertNotSame($customer->integration_uid, $this->customer()->integration_uid);
    }

    public function test_customer_identity_survives_name_and_phone_changes(): void
    {
        $customer = $this->customer();
        $original = $customer->integration_uid;

        app(CustomerUpdateService::class)->update($customer, [
            'name' => 'Renamed Customer',
            'phone' => '+218920001111',
        ]);

        $this->assertSame($original, $customer->fresh()->integration_uid);
    }

    // ----------------------------------------------------------------- delivery order

    public function test_delivery_order_receives_a_uuid_v7_on_creation(): void
    {
        $order = $this->order();

        $this->assertMatchesRegularExpression(self::UUID_V7, $order->integration_uid);
        $this->assertNotSame($order->integration_uid, $this->order()->integration_uid);
    }

    public function test_delivery_order_identity_survives_business_field_changes(): void
    {
        $order = $this->order();
        $original = $order->integration_uid;

        app(DeliveryOrderUpdateService::class)->update($order, [
            'value' => '999.00',
            'location_link' => 'maps.example/moved',
        ]);

        $this->assertSame($original, $order->fresh()->integration_uid);
    }

    // ------------------------------------------------------------------ the four events

    public function test_order_assigned_names_all_three_entities_by_their_stable_identity(): void
    {
        $customer = $this->customer();
        $representative = $this->representative();
        $order = $this->order($customer);

        app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $representative);

        $data = $this->event($order, IntegrationEventType::OrderAssigned)->payload['data'];

        $this->assertSame($order->integration_uid, $data['order']['external_order_id']);
        $this->assertSame($customer->integration_uid, $data['customer']['external_customer_id']);
        $this->assertSame($representative->integration_uid, $data['courier']['external_courier_id']);
    }

    public function test_updated_reassigned_and_cancelled_carry_the_same_identities(): void
    {
        $lifecycle = app(DeliveryOrderLifecycleService::class);

        $customer = $this->customer();
        $first = $this->representative('First');
        $second = $this->representative('Second');
        $order = $this->order($customer);

        $lifecycle->assignRepresentative($order, $first);
        app(DeliveryOrderUpdateService::class)->update($order->fresh(), ['value' => '77.00']);
        $lifecycle->assignRepresentative($order->fresh(), $second);
        $lifecycle->cancel($order->fresh());

        // order.updated — the identity rides the envelope, and the snapshot it
        // carries agrees with it.
        $updated = $this->event($order, IntegrationEventType::OrderUpdated)->payload['data'];
        $this->assertSame($order->integration_uid, $updated['external_order_id']);
        $this->assertSame($customer->integration_uid, $updated['current_snapshot']['customer']['external_customer_id']);
        $this->assertSame($first->integration_uid, $updated['current_snapshot']['courier']['external_courier_id']);

        // order.reassigned — and the courier being replaced is named by the
        // identity Masar already holds for them, not by the key it never saw.
        $reassigned = $this->event($order, IntegrationEventType::OrderReassigned)->payload['data'];
        $this->assertSame($order->integration_uid, $reassigned['external_order_id']);
        $this->assertSame($first->integration_uid, $reassigned['previous_external_courier_id']);
        $this->assertSame($second->integration_uid, $reassigned['courier']['external_courier_id']);

        // order.cancelled — one field, and it is the same one.
        $cancelled = $this->event($order, IntegrationEventType::OrderCancelled)->payload['data'];
        $this->assertSame($order->integration_uid, $cancelled['external_order_id']);
    }

    // -------------------------------------------------------------------- no leakage

    public function test_no_external_identity_is_a_local_primary_key(): void
    {
        $customer = $this->customer();
        $representative = $this->representative();
        $order = $this->order($customer);

        app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $representative);

        $data = $this->event($order, IntegrationEventType::OrderAssigned)->payload['data'];

        // The regression this whole change exists to prevent, stated as an
        // assertion: not one of the three may be the integer the row happens to
        // sit at. Asserting the uid alone would still pass on the day someone
        // reintroduces `getKey()` for a row whose uid is also its id.
        $this->assertNotSame((string) $order->id, $data['order']['external_order_id']);
        $this->assertNotSame((string) $customer->id, $data['customer']['external_customer_id']);
        $this->assertNotSame((string) $representative->id, $data['courier']['external_courier_id']);

        foreach ([
            $data['order']['external_order_id'],
            $data['customer']['external_customer_id'],
            $data['courier']['external_courier_id'],
        ] as $external) {
            $this->assertMatchesRegularExpression(self::UUID_V7, $external);
        }
    }

    public function test_integration_uid_is_not_mass_assignable(): void
    {
        $forged = '00000000-0000-7000-8000-000000000000';

        $representative = Representative::create([
            'name' => 'Mass Assignment Attempt',
            'phone' => null,
            'is_active' => true,
            'integration_uid' => $forged,
        ]);

        $this->assertNotSame($forged, $representative->integration_uid);
        $this->assertMatchesRegularExpression(self::UUID_V7, $representative->integration_uid);
    }

    // ------------------------------------------------------- the defect, reproduced

    /**
     * The proof that rebuilding the source database cannot reuse an identity.
     *
     * What a rebuild does is empty the table and put the counter back, so the
     * next row lands on the integer the old one had. That is reproduced here by
     * deleting the row and then inserting the replacement on the same local key
     * — the end state a reset counter produces, reached without the `ALTER
     * TABLE` that would implicitly commit and tear down the test transaction
     * around it.
     *
     * Before this change that integer *was* the identity, so Masar would have
     * resolved the second row to the first row's representative. The reuse is
     * asserted rather than assumed, so the test fails loudly instead of passing
     * vacuously if the two ever stop landing on the same key.
     */
    public function test_rebuilding_the_source_database_cannot_reuse_a_representative_identity(): void
    {
        $before = $this->representative('Before Rebuild');
        $beforeId = $before->id;
        $beforeUid = $before->integration_uid;

        $before->delete();

        $after = $this->representative('After Rebuild', $beforeId);

        $this->assertSame($beforeId, $after->id, 'the local key must be reused for this test to mean anything');
        $this->assertNotSame($beforeUid, $after->integration_uid);
    }

    public function test_rebuilding_the_source_database_cannot_reuse_a_customer_identity(): void
    {
        $before = $this->customer();
        $beforeId = $before->id;
        $beforeUid = $before->integration_uid;

        $before->delete();

        $after = $this->customer($beforeId);

        $this->assertSame($beforeId, $after->id, 'the local key must be reused for this test to mean anything');
        $this->assertNotSame($beforeUid, $after->integration_uid);
    }

    public function test_rebuilding_the_source_database_cannot_reuse_a_delivery_order_identity(): void
    {
        $customer = $this->customer();
        $before = $this->order($customer);
        $beforeId = $before->id;
        $beforeUid = $before->integration_uid;

        $before->delete();

        $after = $this->order($customer, $beforeId);

        $this->assertSame($beforeId, $after->id, 'the local key must be reused for this test to mean anything');
        $this->assertNotSame($beforeUid, $after->integration_uid);
    }

    // ------------------------------------------------------------------------ helpers

    private function event(DeliveryOrder $order, IntegrationEventType $type): IntegrationOutbox
    {
        return IntegrationOutbox::query()
            ->where('delivery_order_id', $order->getKey())
            ->where('event_type', $type)
            ->orderByDesc('order_version')
            ->firstOrFail();
    }

    /** $id forces the local key a rebuilt database would hand out again. */
    private function representative(string $name = 'Representative', ?int $id = null): Representative
    {
        return $this->persist(new Representative([
            'name' => $name.' '.uniqid(),
            'phone' => '+218920000007',
            'is_active' => true,
        ]), $id);
    }

    private function customer(?int $id = null): Customer
    {
        return $this->persist(new Customer([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('+218', true),
        ]), $id);
    }

    private function order(?Customer $customer = null, ?int $id = null): DeliveryOrder
    {
        return $this->persist(new DeliveryOrder([
            'customer_id' => ($customer ?? $this->customer())->id,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]), $id);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    private function persist($model, ?int $id)
    {
        if ($id !== null) {
            $model->id = $id;
        }

        $model->save();

        return $model;
    }
}
