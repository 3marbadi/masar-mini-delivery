<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Exceptions\MissingOrderRecipientException;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\OrderIntegrationState;
use App\Models\Representative;
use App\Services\CustomerUpdateService;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\DeliveryOrderUpdateService;
use App\Services\IntegrationEventGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The recipient is the order's, and only the order's (CONTRACT §13.14 — D7).
 *
 * One rule, asserted from three directions: **no read of a recipient may reach
 * the shared customer profile.** Not as a preference, not as a default, not as a
 * fallback for a null.
 *
 * The reason a fallback is not a safety net here is worth stating, because it is
 * the whole of D7. `customers` is one row behind every order a person ever
 * placed. A read that coalesced into it would make one order's outbound event
 * carry a name that belongs to a different order's history — and then send that
 * name to Masar, where it is applied over the correction Masar's own courier
 * made on this order. The coupling does not merely display the wrong value; it
 * reverses the correction that removed it. So a null snapshot is an error to
 * raise, not a case to smooth over.
 *
 * `external_customer_id` is the deliberate exception, and it is not an
 * exception at all once stated properly: it is *identity*, not recipient data.
 * Masar resolves its own customer row by it, and it has never been something a
 * courier corrects.
 */
class RecipientSnapshotNoFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-05 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------- the snapshot wins

    /**
     * §6 — a moving customer profile does not move this order's recipient.
     *
     * The customer is changed twice, before and after the order's own snapshot
     * is set, so a fallback of any kind — read-time coalesce, a refresh at
     * generation, an eager load that overwrote — would show up as "Shared New"
     * in the payload.
     */
    public function test_a_changed_customer_profile_never_reaches_the_event_payload(): void
    {
        $customer = Customer::create(['name' => 'Shared Original', 'phone' => '+218920000001']);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'recipient_name' => 'Order Old',
            'recipient_phone' => '+218910000001',
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);

        // The profile moves, twice, and by the ordinary local path — which does
        // fan an `order.updated` out to this order, correctly, because the
        // profile is Mini Delivery's own data.
        $customer->forceFill(['name' => 'Shared New', 'phone' => '+218920000002'])->save();

        app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($order, $this->representative());

        $customer->forceFill(['name' => 'Shared Newer', 'phone' => '+218920000003'])->save();

        app(DeliveryOrderUpdateService::class)->update($order->fresh(), ['value' => '55.00']);

        // Every event this order has produced carries its own recipient.
        $events = IntegrationOutbox::query()->where('delivery_order_id', $order->getKey())
            ->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(2, $events->count());

        foreach ($events as $event) {
            $customerBlock = $event->payload['data']['customer']
                ?? $event->payload['data']['current_snapshot']['customer'];

            $this->assertSame('Order Old', $customerBlock['name'], "event {$event->event_type->value}");
            $this->assertSame('+218910000001', $customerBlock['phone'], "event {$event->event_type->value}");

            $this->assertNotSame('Shared New', $customerBlock['name']);
            $this->assertNotSame('+218920000002', $customerBlock['phone']);

            // Identity is the shared customer's, and stays so.
            $this->assertSame((string) $customer->id, $customerBlock['external_customer_id']);
        }
    }

    /**
     * And the shared profile's own change is still announced as a change.
     *
     * The point of D7 is not that the profile stopped mattering — it still
     * drives `customer.name` / `customer.phone` in `changed_fields`, because that
     * is Mini Delivery telling Masar about its own data. What changed is where
     * the *snapshot* comes from. Losing this distinction would be the opposite
     * error to the fallback and just as wrong.
     */
    public function test_a_customer_profile_change_is_still_announced_in_changed_fields(): void
    {
        $customer = Customer::create(['name' => 'Shared Original', 'phone' => '+218920000010']);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'recipient_name' => 'Order Old',
            'recipient_phone' => '+218910000010',
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);

        app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($order, $this->representative());

        app(CustomerUpdateService::class)->update($customer, ['name' => 'Shared New']);

        $latest = IntegrationOutbox::query()->where('delivery_order_id', $order->getKey())
            ->latest('id')->firstOrFail();

        $this->assertSame('order.updated', $latest->event_type->value);
        // Canonicalized: the payload is stored in a MySQL JSON column, which
        // normalises object key order.
        $this->assertEqualsCanonicalizing(
            ['old' => 'Shared Original', 'new' => 'Shared New'],
            $latest->payload['data']['changed_fields']['customer.name'],
        );

        // And the snapshot agrees with the change it announces. The operator
        // restated who these orders are for, so the order's own recipient moved
        // with it — written once, into the order's column, by the local edit
        // path (`CustomerUpdateService::restateRecipients`). What must never
        // happen is the snapshot being *derived* from the profile at read time;
        // this is the opposite, and the difference is why the envelope can be
        // both isolated and self-consistent.
        $this->assertSame('Shared New', $latest->payload['data']['current_snapshot']['customer']['name']);
        $this->assertSame('Shared New', $order->fresh()->recipient_name);
    }

    /**
     * A restatement reaches the customer's open orders and stops at the closed
     * ones.
     *
     * A completed order's recipient is who received it. A later correction to
     * the person's profile does not reach back into what already happened, and
     * that order is announced to nobody, so nothing can diverge.
     */
    public function test_restating_a_profile_leaves_a_completed_order_as_it_was(): void
    {
        $customer = Customer::create(['name' => 'Shared Original', 'phone' => '+218920000060']);
        $representative = $this->representative();

        $open = app(DeliveryOrderLifecycleService::class)->assignRepresentative(
            DeliveryOrder::create([
                'customer_id' => $customer->id, 'value' => '20.00', 'status' => DeliveryOrderStatus::NewOrder,
            ]),
            $representative,
        );

        $closed = app(DeliveryOrderLifecycleService::class)->assignRepresentative(
            DeliveryOrder::create([
                'customer_id' => $customer->id, 'value' => '20.00', 'status' => DeliveryOrderStatus::NewOrder,
            ]),
            $representative,
        );

        app(DeliveryOrderLifecycleService::class)
            ->complete($closed, DeliveryOrderResult::Delivered);

        app(CustomerUpdateService::class)->update($customer, ['name' => 'Shared New']);

        $this->assertSame('Shared New', $open->fresh()->recipient_name);
        $this->assertSame('Shared Original', $closed->fresh()->recipient_name);
    }

    // ------------------------------------------------ a null does not coalesce

    /**
     * §7 — a null snapshot is reported, not substituted.
     *
     * The row is written straight to the database so the creation-time seeding
     * is bypassed, which is the only way such a row can exist at all: it stands
     * for one inserted by a path that does not know about §13.14. The customer
     * behind it has perfectly good values, which is exactly the temptation.
     *
     * **Documented behaviour:** event generation raises
     * `MissingOrderRecipientException`, a `DomainException`, from inside the
     * caller's transaction — so the local change that triggered the event rolls
     * back with it and no outbox row is written. It does not return null, it
     * does not omit the fields, and above all it does not return the customer's.
     */
    public function test_a_null_snapshot_raises_rather_than_falling_back_to_the_customer(): void
    {
        $customer = Customer::create(['name' => 'Shared New', 'phone' => '+218920000020']);

        $orderId = DB::table('delivery_orders')->insertGetId([
            'customer_id' => $customer->id,
            'recipient_name' => null,
            'recipient_phone' => null,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder->value,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $order = DeliveryOrder::query()->findOrFail($orderId);

        $this->assertNull($order->recipient_name);
        $this->assertNull($order->recipient_phone);

        try {
            app(IntegrationEventGenerationService::class)->assigned($order);
            $this->fail('A null recipient snapshot was silently answered with the shared customer profile.');
        } catch (MissingOrderRecipientException $exception) {
            $this->assertStringContainsString((string) $orderId, $exception->getMessage());
            // The message says the substitution was deliberate rather than
            // missing, so whoever reads it in a log is not left guessing.
            $this->assertStringContainsString('shared customer profile', $exception->getMessage());
            $this->assertStringNotContainsString('Shared New', $exception->getMessage());
        }

        // Nothing was written on the way to failing.
        $this->assertSame(0, IntegrationOutbox::query()->count());
    }

    /**
     * The same, through the path an operator would actually take.
     *
     * `assignRepresentative` wraps its work in a transaction, so the failure
     * takes the assignment with it rather than leaving an assigned order whose
     * announcement never existed.
     */
    public function test_a_null_snapshot_takes_the_local_change_down_with_it(): void
    {
        $customer = Customer::create(['name' => 'Shared New', 'phone' => '+218920000030']);

        $orderId = DB::table('delivery_orders')->insertGetId([
            'customer_id' => $customer->id,
            'recipient_name' => null,
            'recipient_phone' => null,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder->value,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->expectException(MissingOrderRecipientException::class);

        try {
            app(DeliveryOrderLifecycleService::class)
                ->assignRepresentative(DeliveryOrder::query()->findOrFail($orderId), $this->representative());
        } finally {
            $row = DB::table('delivery_orders')->where('id', $orderId)->first();

            $this->assertSame(DeliveryOrderStatus::NewOrder->value, $row->status);
            $this->assertNull($row->representative_id);
            $this->assertSame(0, IntegrationOutbox::query()->count());
        }
    }

    // ------------------------------------------------------- seeding at creation

    /**
     * Why the null above is exceptional: creation seeds the snapshot.
     *
     * The counterpart of the migration's backfill. Copying once at insert is not
     * the fallback D7 forbids — from that moment the order owns the value, and
     * the profile moving afterwards does not reach it, which the assertion below
     * checks rather than assumes.
     */
    public function test_a_new_order_is_seeded_from_its_customer_and_then_owns_the_value(): void
    {
        $customer = Customer::create(['name' => 'Shared Original', 'phone' => '+218920000040']);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);

        $this->assertSame('Shared Original', $order->recipient_name);
        $this->assertSame('+218920000040', $order->recipient_phone);
        // Never invented: this system's `customers` has no second number.
        $this->assertNull($order->recipient_alternate_phone);

        $customer->forceFill(['name' => 'Shared New'])->save();

        $this->assertSame('Shared Original', $order->fresh()->recipient_name);
    }

    /** And an explicit recipient is not overwritten by the seeding. */
    public function test_an_explicitly_given_recipient_survives_creation(): void
    {
        $customer = Customer::create(['name' => 'Shared Original', 'phone' => '+218920000050']);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'recipient_name' => 'Someone Else',
            'recipient_phone' => '+218910000050',
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);

        $this->assertSame('Someone Else', $order->recipient_name);
        $this->assertSame('+218910000050', $order->recipient_phone);
    }

    /**
     * The fan-out invariant, in full: every order whose snapshot moved and that
     * Masar knows about is told, individually, with its own values.
     *
     * The scenario is three orders of one customer — two open and announced, one
     * completed — and only the *phone* is restated. That asymmetry is the point
     * of the design: after the update A and B share a phone, so a per-order
     * mix-up would be invisible there, but they keep the two distinct
     * `recipient_name`s they started with. Each event therefore has to carry a
     * different name, and neither may carry the customer's own third name —
     * which is what makes this one test cover per-order sourcing, absence of a
     * profile fallback, and the fan-out at once.
     *
     * What is asserted, in the terms §5 sets:
     *
     *   - A and B have their `recipient_phone` restated;
     *   - A and B each get their own `order.updated`, at their own version;
     *   - each event's `current_snapshot.customer` is that order's snapshot,
     *     never the other's and never the profile's;
     *   - the completed order keeps its historical recipient and is told nothing.
     */
    public function test_a_profile_restatement_tells_every_announced_order_with_its_own_values(): void
    {
        $customer = Customer::create(['name' => 'Shared Name', 'phone' => '+218920000070']);
        $representative = $this->representative();
        $lifecycle = app(DeliveryOrderLifecycleService::class);

        $a = $lifecycle->assignRepresentative($this->orderFor($customer, 'Recipient A', '+218910000071'), $representative);
        $b = $lifecycle->assignRepresentative($this->orderFor($customer, 'Recipient B', '+218910000072'), $representative);
        $closed = $lifecycle->assignRepresentative($this->orderFor($customer, 'Recipient C', '+218910000073'), $representative);

        $lifecycle->complete($closed, DeliveryOrderResult::Delivered);

        $versionsBefore = [
            $a->getKey() => $this->version($a),
            $b->getKey() => $this->version($b),
        ];

        // The genuine production path, and only the number moves.
        app(CustomerUpdateService::class)->update($customer, ['phone' => '+218920000079']);

        // The profile itself.
        $this->assertSame('+218920000079', $customer->fresh()->phone);

        // The two open orders were restated — the number only. Their names are
        // untouched and still distinct, which is what the assertions below lean
        // on.
        $this->assertSame('+218920000079', $a->fresh()->recipient_phone);
        $this->assertSame('+218920000079', $b->fresh()->recipient_phone);
        $this->assertSame('Recipient A', $a->fresh()->recipient_name);
        $this->assertSame('Recipient B', $b->fresh()->recipient_name);

        // The completed order is history and was left alone, in both columns.
        $this->assertSame('Recipient C', $closed->fresh()->recipient_name);
        $this->assertSame('+218910000073', $closed->fresh()->recipient_phone);

        // One event each, for the two announced orders and for nobody else.
        $announcements = IntegrationOutbox::query()
            ->where('event_type', 'order.updated')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $announcements, 'The fan-out did not produce exactly one event per announced order.');
        $this->assertEqualsCanonicalizing(
            [$a->getKey(), $b->getKey()],
            $announcements->pluck('delivery_order_id')->map(fn ($id) => (int) $id)->all(),
        );
        $this->assertSame(0, IntegrationOutbox::query()
            ->where('delivery_order_id', $closed->getKey())
            ->where('event_type', 'order.updated')
            ->count(), 'A completed order was announced.');

        $expectedRecipient = [
            $a->getKey() => 'Recipient A',
            $b->getKey() => 'Recipient B',
        ];

        foreach ($announcements as $event) {
            $orderId = (int) $event->delivery_order_id;
            $snapshot = $event->payload['data']['current_snapshot']['customer'];

            // Its own version, advanced by one — not a shared or global sequence.
            $this->assertSame($versionsBefore[$orderId] + 1, (int) $event->order_version);
            $this->assertSame((string) $orderId, $event->payload['data']['external_order_id']);

            // Its own recipient. The other order's name would be the fan-out
            // reading one snapshot for both; the customer's name would be the
            // fallback D7 forbids. Neither is possible here.
            $this->assertSame($expectedRecipient[$orderId], $snapshot['name']);
            $this->assertNotSame('Shared Name', $snapshot['name']);

            // And the change it announces agrees with the snapshot it carries.
            $this->assertSame('+218920000079', $snapshot['phone']);
            $this->assertSame('+218920000079', $event->payload['data']['changed_fields']['customer.phone']['new']);

            // Identity is still the shared customer's.
            $this->assertSame((string) $customer->id, $snapshot['external_customer_id']);
        }
    }

    /**
     * The one order a restatement changes without announcing — and why that is
     * not a silent fan-out.
     *
     * An order still `new` has produced no integration event at all: Masar has
     * no record of it, so there is no far-side state to diverge from and nothing
     * owed. Its snapshot is not yet frozen; it freezes at the first
     * announcement. This asserts the consequence that matters — the first event
     * the order ever emits carries the restated value, so Masar's first sight of
     * it is already correct.
     */
    public function test_an_unannounced_order_is_restated_and_announces_the_restated_value_when_it_first_speaks(): void
    {
        $customer = Customer::create(['name' => 'Shared Name', 'phone' => '+218920000080']);

        $new = $this->orderFor($customer, 'Recipient N', '+218910000081');

        $this->assertSame(0, IntegrationOutbox::query()->count(), 'A new order must have announced nothing.');

        app(CustomerUpdateService::class)->update($customer, ['phone' => '+218920000089']);

        $this->assertSame('+218920000089', $new->fresh()->recipient_phone);
        $this->assertSame(0, IntegrationOutbox::query()->count(), 'An unannounced order owes no event.');

        // Its first word carries the restated value.
        app(DeliveryOrderLifecycleService::class)->assignRepresentative($new, $this->representative());

        $first = IntegrationOutbox::query()->where('delivery_order_id', $new->getKey())->sole();

        $this->assertSame('order.assigned', $first->event_type->value);
        $this->assertSame('+218920000089', $first->payload['data']['customer']['phone']);
        $this->assertSame('Recipient N', $first->payload['data']['customer']['name']);
    }

    // ------------------------------------------------------------- machinery

    private function orderFor(Customer $customer, string $recipientName, string $recipientPhone): DeliveryOrder
    {
        return DeliveryOrder::create([
            'customer_id' => $customer->id,
            'recipient_name' => $recipientName,
            'recipient_phone' => $recipientPhone,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);
    }

    private function version(DeliveryOrder $order): int
    {
        return (int) (OrderIntegrationState::query()
            ->where('delivery_order_id', $order->getKey())
            ->value('current_version') ?? 0);
    }

    private function representative(): Representative
    {
        return Representative::create([
            'name' => 'Representative '.uniqid(),
            'phone' => '+218920000007',
            'is_active' => true,
        ]);
    }
}
