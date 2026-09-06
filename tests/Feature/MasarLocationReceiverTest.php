<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use App\Models\MasarOrderNote;
use App\Models\OrderIntegrationState;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\DeliveryOrderUpdateService;
use App\Services\Integration\LocationChangeStamp;
use App\Services\Integration\MasarLocationEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The receiving half of Masar's location channel — `order.location.updated`
 * (CONTRACT §13.17, D13, v5.5).
 *
 * Four properties carry the weight.
 *
 * **No echo, and here it is the whole game.** `DeliveryOrderUpdateService` lists
 * `latitude` and `longitude` among the four fields it publishes, and Masar
 * accepts `location.latitude` inbound and turns it into an `OrderChange` and a
 * reevaluation. So a location applied through that service would go back to
 * Masar as a change Masar had just made, be applied, and be announced again —
 * for ever, because every lap is a genuinely new event with a valid identity and
 * an advancing version. No idempotency breaks that; only ownership does
 * (§13.17.4).
 *
 * **The coordinates survive as decimals.** They arrive as seven-place strings
 * and reach the column as seven-place strings. A float anywhere in between would
 * put representation error into a coordinate a courier drives to (§13.17.1).
 *
 * **Gaps apply, staleness does not.** The payload carries the whole current
 * location rather than a difference, so version 5 on a mark of 2 is complete in
 * itself — unlike the inbound direction, where a gap is a `409` (§13.17.3).
 *
 * **Last business write wins** (D13, §13.17.5). The location is shared-write and
 * neither system owns it, so the arbiter is when each change actually committed
 * at its origin — never when its message arrived. A delayed announcement of an
 * older change is converged away and stays that way however often it is retried,
 * because the stamp it carries was frozen when the change happened. There is no
 * `base_order_version` on this channel any more, and no
 * `LOCATION_BASE_VERSION_CONFLICT`: that rule could refuse a genuinely newer
 * location merely because our own unrelated sequence had moved.
 */
class MasarLocationReceiverTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTS = '/api/v1/integration/events';

    private const TOKENS = '/api/v1/integration/auth/token';

    private MasarIntegrationClient $client;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 12:00:00');

        $this->client = MasarIntegrationClient::create([
            'name' => 'Masar',
            'client_id' => 'masar',
            'client_secret_hash' => Hash::make('masar-secret'),
            'status' => 'active',
        ]);

        $this->token = $this->issueToken('masar', 'masar-secret');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------ the door

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $order = $this->assignedOrder();

        $this->postJson(self::EVENTS, $this->envelope($order))->assertUnauthorized();

        $this->assertNull(DeliveryOrder::query()->whereKey($order->getKey())->sole()->latitude);
    }

    public function test_an_unknown_order_is_a_404_with_its_own_code(): void
    {
        $this->sendPayload($this->envelope(externalOrderId: '999999'))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

        $this->assertSame('rejected', MasarIntegrationEvent::query()->sole()->result);
    }

    /** @return array<string, array{callable}> */
    public static function malformedLocations(): array
    {
        return [
            'float latitude' => [fn (array $p) => tap($p, function (&$p) { $p['data']['latitude'] = 32.8851; })],
            'too few places' => [fn (array $p) => tap($p, function (&$p) { $p['data']['latitude'] = '32.885'; })],
            'latitude out of range' => [fn (array $p) => tap($p, function (&$p) { $p['data']['latitude'] = '100.0000000'; })],
            'longitude out of range' => [fn (array $p) => tap($p, function (&$p) { $p['data']['longitude'] = '181.0000000'; })],
            'missing longitude' => [fn (array $p) => tap($p, function (&$p) { unset($p['data']['longitude']); })],
            'zero version' => [fn (array $p) => tap($p, function (&$p) { $p['data']['location_version'] = 0; })],
            'missing stamp' => [fn (array $p) => tap($p, function (&$p) { unset($p['data']['location_changed_at']); })],
            'unzoned stamp' => [fn (array $p) => tap($p, function (&$p) { $p['data']['location_changed_at'] = '2026-09-06 12:00:00'; })],
            'foreign source' => [fn (array $p) => tap($p, function (&$p) { $p['data']['location_change_source'] = 'delivery_company'; })],
            'missing source' => [fn (array $p) => tap($p, function (&$p) { unset($p['data']['location_change_source']); })],
            'unzoned instant' => [fn (array $p) => tap($p, function (&$p) { $p['data']['location_completed_at'] = '2026-09-06 12:00:00'; })],
        ];
    }

    #[DataProvider('malformedLocations')]
    public function test_a_malformed_location_is_refused(callable $mutate): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($mutate($this->envelope($order)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertNull(DeliveryOrder::query()->whereKey($order->getKey())->sole()->latitude);
    }

    // ------------------------------------------------------------ applying

    public function test_a_location_is_applied_with_its_version_and_timestamp(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, version: 1))
            ->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('applied_location_version', 1)
            ->assertJsonPath('latitude', '32.8851000')
            ->assertJsonPath('longitude', '13.2010000');

        $fresh = DeliveryOrder::query()->whereKey($order->getKey())->sole();

        $this->assertSame('32.8851000', $fresh->latitude);
        $this->assertSame('13.2010000', $fresh->longitude);
        $this->assertSame('2026-09-06 12:00:00', $fresh->location_completed_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, (int) $fresh->masar_location_version);
    }

    /**
     * The coordinates reach the column exactly as sent (§13.17.1).
     *
     * Asserted on a value whose seventh place is significant, so a float
     * round-trip would show.
     */
    public function test_the_coordinates_are_stored_exactly(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, latitude: '32.8851237', longitude: '-13.2010009'))
            ->assertOk();

        $fresh = DeliveryOrder::query()->whereKey($order->getKey())->sole();

        $this->assertSame('32.8851237', $fresh->latitude);
        $this->assertSame('-13.2010009', $fresh->longitude);
    }

    /** A later correction replaces the earlier one and advances the mark. */
    public function test_a_newer_version_is_applied(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, version: 1))->assertOk();
        $this->sendPayload($this->envelope($order, version: 2, latitude: '33.0000000'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $fresh = DeliveryOrder::query()->whereKey($order->getKey())->sole();

        $this->assertSame('33.0000000', $fresh->latitude);
        $this->assertSame(2, (int) $fresh->masar_location_version);
    }

    /**
     * A gap is applied rather than refused (§13.17.3).
     *
     * The payload is the whole current location, so waiting for versions 2, 3
     * and 4 would strand a correct coordinate for events that may never come.
     */
    public function test_a_gap_in_the_sequence_is_applied(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, version: 2))->assertOk();

        $this->sendPayload($this->envelope($order, version: 5, latitude: '31.0000000'))
            ->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('applied_location_version', 5);

        $this->assertSame(5, (int) DeliveryOrder::query()->whereKey($order->getKey())->sole()->masar_location_version);
    }

    public function test_an_older_version_is_ignored_as_stale(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, version: 3, latitude: '33.0000000'))->assertOk();

        $this->sendPayload($this->envelope($order, version: 2, latitude: '31.0000000'))
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale')
            ->assertJsonPath('applied_location_version', 3);

        $fresh = DeliveryOrder::query()->whereKey($order->getKey())->sole();

        $this->assertSame('33.0000000', $fresh->latitude);
        $this->assertSame(3, (int) $fresh->masar_location_version);
    }

    public function test_a_second_event_at_the_applied_version_is_a_conflict(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, version: 2))->assertOk();

        $this->sendPayload($this->envelope($order, version: 2, latitude: '31.0000000'))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $this->assertSame('32.8851000', DeliveryOrder::query()->whereKey($order->getKey())->sole()->latitude);
    }

    // -------------------------------------------------------- idempotency

    public function test_a_retry_of_the_same_event_replays_its_answer(): void
    {
        $order = $this->assignedOrder();
        $payload = $this->envelope($order, version: 1);

        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'processed');
        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'already_processed');

        $this->assertSame(1, (int) DeliveryOrder::query()->whereKey($order->getKey())->sole()->masar_location_version);
    }

    public function test_one_event_id_with_a_different_payload_is_a_conflict(): void
    {
        $order = $this->assignedOrder();
        $payload = $this->envelope($order, version: 1);

        $this->sendPayload($payload)->assertOk();

        $payload['data']['latitude'] = '31.0000000';

        $this->sendPayload($payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');
    }

    public function test_a_stale_event_replays_as_stale_and_not_as_applied(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, version: 4))->assertOk();

        $stale = $this->envelope($order, version: 2, latitude: '31.0000000');

        $this->sendPayload($stale)->assertOk()->assertJsonPath('status', 'ignored_stale');
        $this->sendPayload($stale)
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale')
            ->assertJsonPath('applied_location_version', 4);
    }

    // ------------------------------------------- last business write wins (D13)

    /**
     * §19 case A — a delayed Masar change loses to a newer local one.
     *
     * The canonical example of D13, and the one the owner's decision spells out:
     * Masar commits A at 12:00, this system commits B at 12:05, and Masar's
     * announcement of A finally arrives at 12:10. B stands. The arbiter is when
     * each change happened, not when its message showed up.
     */
    public function test_a_delayed_masar_change_loses_to_a_newer_local_one(): void
    {
        $order = $this->assignedOrder();

        // 12:05 — this system's own edit, which stamps itself.
        Carbon::setTestNow('2026-09-06 12:05:00');
        app(DeliveryOrderUpdateService::class)->update($order->fresh(), ['latitude' => '31.0000000']);

        // 12:10 — Masar's announcement of a change it made at 12:00.
        Carbon::setTestNow('2026-09-06 12:10:00');
        $this->sendPayload($this->envelope($order, version: 1, latitude: '32.8851000', changedAt: '2026-09-06T12:00:00Z'))
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale');

        $fresh = DeliveryOrder::query()->whereKey($order->getKey())->sole();

        $this->assertSame('31.0000000', $fresh->latitude, 'the newer local change must stand');
        $this->assertSame(LocationChangeStamp::SOURCE_DELIVERY_COMPANY, $fresh->location_change_source);
        $this->assertSame(0, (int) $fresh->masar_location_version);
    }

    /**
     * §21 — and it stays lost, however many times it is retried.
     *
     * The direct proof that the rule is *last business write* and not *last
     * network arrival*: repetition is exactly the thing that would let arrival
     * order win, and it changes nothing here because the stamp was frozen when
     * the change happened.
     */
    public function test_a_losing_change_stays_lost_no_matter_how_often_it_is_retried(): void
    {
        $order = $this->assignedOrder();

        Carbon::setTestNow('2026-09-06 12:05:00');
        app(DeliveryOrderUpdateService::class)->update($order->fresh(), ['latitude' => '31.0000000']);

        // The same losing announcement, re-sent as a genuine retry (same
        // event_id) and as a fresh delivery attempt (new event_id), five times.
        $retry = $this->envelope($order, version: 1, latitude: '32.8851000', changedAt: '2026-09-06T12:00:00Z');

        for ($i = 0; $i < 5; $i++) {
            // Inside the token's hour: the point of this test is the stamp, not
            // an expired credential.
            Carbon::setTestNow('2026-09-06 12:2'.$i.':00');

            $this->sendPayload($retry)->assertOk()->assertJsonPath('status', 'ignored_stale');

            $this->sendPayload($this->envelope($order, version: 1, latitude: '32.8851000', changedAt: '2026-09-06T12:00:00Z'))
                ->assertOk()
                ->assertJsonPath('status', 'ignored_stale');
        }

        $this->assertSame('31.0000000', DeliveryOrder::query()->whereKey($order->getKey())->sole()->latitude);
    }

    /**
     * §19 case E — a genuinely later Masar change wins over a local one.
     *
     * The mirror of case A, and the half that proves the rule is symmetrical
     * rather than a local-preference dressed up.
     */
    public function test_a_newer_masar_change_wins_over_an_earlier_local_one(): void
    {
        $order = $this->assignedOrder();

        Carbon::setTestNow('2026-09-06 12:00:00');
        app(DeliveryOrderUpdateService::class)->update($order->fresh(), ['latitude' => '31.0000000']);

        Carbon::setTestNow('2026-09-06 12:10:00');
        $this->sendPayload($this->envelope($order, version: 1, latitude: '32.8851000', changedAt: '2026-09-06T12:05:00Z'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $fresh = DeliveryOrder::query()->whereKey($order->getKey())->sole();

        $this->assertSame('32.8851000', $fresh->latitude);
        $this->assertSame(LocationChangeStamp::SOURCE_MASAR, $fresh->location_change_source);
        $this->assertSame('2026-09-06 12:05:00', $fresh->location_changed_at->format('Y-m-d H:i:s'));
    }

    /**
     * §19 case D — and a later local change then wins back, and announces.
     *
     * Ownership does not stick to whoever wrote last: it is decided per change,
     * every time.
     */
    public function test_a_later_local_change_wins_back_and_announces_normally(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, version: 1, latitude: '32.8851000'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $before = IntegrationOutbox::query()->count();

        Carbon::setTestNow('2026-09-06 12:30:00');
        app(DeliveryOrderUpdateService::class)->update($order->fresh(), ['latitude' => '30.0000000']);

        $fresh = DeliveryOrder::query()->whereKey($order->getKey())->sole();

        $this->assertSame('30.0000000', $fresh->latitude);
        $this->assertSame(LocationChangeStamp::SOURCE_DELIVERY_COMPANY, $fresh->location_change_source);
        $this->assertSame('2026-09-06 12:30:00', $fresh->location_changed_at->format('Y-m-d H:i:s'));

        // A genuine later local change is Masar's to hear about (§13.17.4).
        $this->assertSame($before + 1, IntegrationOutbox::query()->count());
    }

    /**
     * §19 case C — a newer announcement arriving before an older one.
     *
     * Out-of-order delivery, which is what a retrying sender actually produces.
     * Both are Masar's, so Masar's own sequence orders them (§13.17.3) — the
     * stamp is not consulted and does not need to be.
     */
    public function test_an_older_announcement_arriving_after_a_newer_one_is_ignored(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, version: 3, latitude: '33.0000000', changedAt: '2026-09-06T12:30:00Z'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->sendPayload($this->envelope($order, version: 2, latitude: '31.0000000', changedAt: '2026-09-06T12:10:00Z'))
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale');

        $this->assertSame('33.0000000', DeliveryOrder::query()->whereKey($order->getKey())->sole()->latitude);
    }

    /**
     * Two Masar saves in one second are ordered by Masar's sequence, not by a
     * coin toss (§13.17.3).
     *
     * The stamp has second resolution, so without this rule the two would tie
     * and the winner would be whichever random `event_id` sorted higher.
     */
    public function test_two_masar_changes_in_one_second_are_ordered_by_their_sequence(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, version: 1, latitude: '31.0000000', changedAt: '2026-09-06T12:00:00Z'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->sendPayload($this->envelope($order, version: 2, latitude: '33.0000000', changedAt: '2026-09-06T12:00:00Z'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame('33.0000000', DeliveryOrder::query()->whereKey($order->getKey())->sole()->latitude);
    }

    /**
     * §19 case G — two changes at one instant resolve deterministically.
     *
     * Second resolution is what the wire carries, so a tie is reachable. The
     * rule is `masar` beats `delivery_company` on an equal instant, and both
     * systems apply it identically — an arbitrary choice, fixed and written down
     * so it cannot be made differently on the two sides (§13.17.5).
     */
    public function test_a_tie_on_the_instant_is_broken_deterministically(): void
    {
        $order = $this->assignedOrder();

        Carbon::setTestNow('2026-09-06 12:00:00');
        app(DeliveryOrderUpdateService::class)->update($order->fresh(), ['latitude' => '31.0000000']);

        // The same second, from Masar. `masar` > `delivery_company`, so it wins.
        $this->sendPayload($this->envelope($order, version: 1, latitude: '32.8851000', changedAt: '2026-09-06T12:00:00Z'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame('32.8851000', DeliveryOrder::query()->whereKey($order->getKey())->sole()->latitude);
    }

    /** And the tie-break is the contract's, asserted directly on the comparator. */
    public function test_the_tie_break_order_is_the_contracted_one(): void
    {
        $at = Carbon::parse('2026-09-06T12:00:00Z');

        $masar = new LocationChangeStamp($at, LocationChangeStamp::SOURCE_MASAR, 'a');
        $company = new LocationChangeStamp($at, LocationChangeStamp::SOURCE_DELIVERY_COMPANY, 'z');

        // 2 — source decides before identity, so `z` does not save the company.
        $this->assertTrue(LocationChangeStamp::wins($masar, $company));
        $this->assertFalse(LocationChangeStamp::wins($company, $masar));

        // 3 — identity decides when instant and source are equal.
        $first = new LocationChangeStamp($at, LocationChangeStamp::SOURCE_MASAR, 'a');
        $second = new LocationChangeStamp($at, LocationChangeStamp::SOURCE_MASAR, 'b');
        $this->assertTrue(LocationChangeStamp::wins($second, $first));
        $this->assertFalse(LocationChangeStamp::wins($first, $second));

        // 1 — and the instant decides before either of them.
        $later = new LocationChangeStamp(Carbon::parse('2026-09-06T12:00:01Z'), LocationChangeStamp::SOURCE_DELIVERY_COMPANY, 'a');
        $this->assertTrue(LocationChangeStamp::wins($later, $masar));
    }

    /** An order that has never held a location takes the first change of either origin. */
    public function test_a_first_change_wins_against_no_stamp(): void
    {
        $order = $this->assignedOrder();

        $this->assertNull(DeliveryOrder::query()->whereKey($order->getKey())->sole()->location_changed_at);

        $this->sendPayload($this->envelope($order, version: 1, changedAt: '2020-01-01T00:00:00Z'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');
    }

    /**
     * A losing announcement is converged away, never refused (§13.17.3).
     *
     * `409` is reserved for a fault in the sender's own bookkeeping. A location
     * that simply came second is ordinary convergence and gets a `200`, so the
     * sender settles it instead of retrying for ever.
     */
    public function test_a_losing_change_is_answered_200_and_not_a_conflict(): void
    {
        $order = $this->assignedOrder();

        Carbon::setTestNow('2026-09-06 12:05:00');
        app(DeliveryOrderUpdateService::class)->update($order->fresh(), ['latitude' => '31.0000000']);

        $this->sendPayload($this->envelope($order, version: 1, changedAt: '2026-09-06T12:00:00Z'))
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale');

        $this->assertSame(
            'ignored_stale',
            MasarIntegrationEvent::query()->where('event_type', 'order.location.updated')->sole()->result,
        );
    }

    // ------------------------------------------------------------- no echo

    /**
     * Applying a location enqueues nothing (§13.17.4).
     *
     * The barrier. Without it this channel loops for ever.
     */
    public function test_applying_a_location_enqueues_nothing(): void
    {
        $order = $this->assignedOrder();

        $before = IntegrationOutbox::query()->count();

        $this->sendPayload($this->envelope($order))->assertOk();

        $this->assertSame($before, IntegrationOutbox::query()->count());
    }

    /** And it does not advance our own outbound version (§13.17.4). */
    public function test_applying_a_location_does_not_advance_our_own_version(): void
    {
        $order = $this->assignedOrder();

        $before = (int) OrderIntegrationState::query()
            ->where('delivery_order_id', $order->getKey())->value('current_version');

        $this->sendPayload($this->envelope($order))->assertOk();

        $after = (int) OrderIntegrationState::query()
            ->where('delivery_order_id', $order->getKey())->value('current_version');

        $this->assertSame($before, $after);
    }

    /**
     * A genuine local location change still announces normally (§13.17.4).
     *
     * The barrier is ownership, not a mute: what this system changes from its
     * own source is still Masar's to hear about.
     */
    public function test_a_later_genuine_local_change_still_announces(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order))->assertOk();

        $before = IntegrationOutbox::query()->count();

        app(DeliveryOrderUpdateService::class)->update($order->fresh(), ['latitude' => '31.5000000']);

        $this->assertSame($before + 1, IntegrationOutbox::query()->count());
    }

    // --------------------------------------------------- channel independence

    /** The location channel moves no other channel's high-water mark (§13.12). */
    public function test_a_location_moves_no_other_version(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, version: 3))->assertOk();

        $fresh = DeliveryOrder::query()->whereKey($order->getKey())->sole();

        $this->assertSame(3, (int) $fresh->masar_location_version);
        $this->assertSame(0, (int) $fresh->masar_status_version);
        $this->assertSame(0, (int) $fresh->masar_data_version);
        $this->assertSame(0, MasarOrderNote::query()->count());
    }

    /** `location_validation_status` is never invented here (§13.17.1). */
    public function test_the_validation_status_is_left_alone(): void
    {
        $order = $this->assignedOrder();

        $before = DeliveryOrder::query()->whereKey($order->getKey())->sole()->location_validation_status;

        $this->sendPayload($this->envelope($order))->assertOk();

        $this->assertSame(
            $before,
            DeliveryOrder::query()->whereKey($order->getKey())->sole()->location_validation_status,
        );
    }

    /** The ledger records this channel's number and no other's (§13.17.3). */
    public function test_the_ledger_records_this_channels_number_only(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, version: 3))->assertOk();

        $event = MasarIntegrationEvent::query()->sole();

        $this->assertSame('order.location.updated', $event->event_type);
        $this->assertSame(3, (int) $event->location_version);
        $this->assertSame('processed', $event->result);
        $this->assertNull($event->status_version);
        $this->assertNull($event->data_version);
        $this->assertNull($event->masar_note_id);
        // D13 — this channel stopped carrying a base version (§13.17.5).
        $this->assertNull($event->base_order_version);
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param  string|null  $changedAt  the business instant of this change (§13.17.5); defaults to
     *                                  "now", which is what a live announcement carries
     */
    private function envelope(
        ?DeliveryOrder $order = null,
        ?string $externalOrderId = null,
        int $version = 1,
        string $latitude = '32.8851000',
        string $longitude = '13.2010000',
        ?string $changedAt = null,
    ): array {
        $stamp = $changedAt ?? Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'contract_version' => MasarLocationEnvelope::CONTRACT_VERSION,
            'event_id' => (string) Str::uuid(),
            'event_type' => MasarLocationEnvelope::EVENT_TYPE,
            'occurred_at' => $stamp,
            'data' => [
                'order_id' => $externalOrderId ?? (string) $order?->getKey(),
                'location_version' => $version,
                'location_changed_at' => $stamp,
                'location_change_source' => LocationChangeStamp::SOURCE_MASAR,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'location_completed_at' => $stamp,
            ],
        ];
    }

    private function sendPayload(array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson(self::EVENTS, $payload);
    }

    private function issueToken(string $clientId, string $secret): string
    {
        $response = $this->postJson(self::TOKENS, ['client_id' => $clientId, 'client_secret' => $secret]);

        $response->assertOk()->assertJsonPath('token_type', 'Bearer');

        return $response->json('access_token');
    }

    private function assignedOrder(?Customer $customer = null): DeliveryOrder
    {
        $customer ??= Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('+218', true),
        ]);

        $representative = Representative::create([
            'name' => 'Representative '.uniqid(),
            'phone' => '+218920000007',
            'is_active' => true,
        ]);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);

        return app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($order, $representative);
    }
}
