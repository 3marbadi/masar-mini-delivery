<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use App\Models\MasarOrderNote;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\Integration\MasarNoteEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The receiving half of Masar's note channel — `order.note.created`
 * (CONTRACT §13.16, v5.5).
 *
 * Three properties carry the weight, and each is asserted rather than described.
 *
 * **Notes accumulate; nothing supersedes anything.** §13.16.2 gives this channel
 * no sequence and no high-water mark, so a note whose retry arrives after a
 * later note's is stored rather than discarded. That is why there is no
 * `ignored_stale` here and why a test below asserts its absence: on an
 * append-only log, "stale" would mean losing a row a courier wrote.
 *
 * **The identity is `note_id`, and it settles repeats.** A second announcement
 * of one immutable note is `already_processed` when it matches and a refusal
 * when it does not — this system will not choose between two texts claiming one
 * identity (§13.16.3).
 *
 * **Receiving produces no announcement back.** The local edit path raises an
 * `order.updated` into the outbox, correctly, because what it changes is ours.
 * A note applied through anything that enqueues would go back to its author.
 */
class MasarNoteReceiverTest extends TestCase
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

        $this->postJson(self::EVENTS, $this->envelope($order))
            ->assertUnauthorized();

        $this->assertSame(0, MasarOrderNote::query()->count());
    }

    public function test_an_unknown_order_is_a_404_with_its_own_code(): void
    {
        $this->sendPayload($this->envelope(externalOrderId: '999999'))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

        $this->assertSame(0, MasarOrderNote::query()->count());
        $this->assertSame('rejected', MasarIntegrationEvent::query()->sole()->result);
    }

    public function test_a_non_numeric_order_id_is_answered_as_a_missing_order(): void
    {
        $this->sendPayload($this->envelope(externalOrderId: 'not-an-id'))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
    }

    /**
     * A note event carrying another channel's fields is refused (§13.16.1).
     *
     * Each `data` block is fixed separately, so the union of them is not a valid
     * envelope for any of them.
     */
    public function test_a_note_event_carrying_a_version_is_still_read_by_its_own_rules(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order);
        unset($payload['data']['note_id']);

        $this->sendPayload($payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    /** @return array<string, array{callable}> */
    public static function malformedNotes(): array
    {
        return [
            'blank content' => [fn (array $p) => tap($p, function (&$p) { $p['data']['content'] = '   '; })],
            'missing content' => [fn (array $p) => tap($p, function (&$p) { unset($p['data']['content']); })],
            'zero note id' => [fn (array $p) => tap($p, function (&$p) { $p['data']['note_id'] = 0; })],
            'missing author' => [fn (array $p) => tap($p, function (&$p) { unset($p['data']['representative_id']); })],
            'unzoned instant' => [fn (array $p) => tap($p, function (&$p) { $p['data']['created_at'] = '2026-09-06 12:00:00'; })],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedNotes')]
    public function test_a_malformed_note_is_refused(callable $mutate): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($mutate($this->envelope($order)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, MasarOrderNote::query()->count());
    }

    // ------------------------------------------------------------ storing

    public function test_a_note_is_stored_with_everything_the_event_carried(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order, noteId: 481, content: 'المستلِم غير متاح', representativeId: 7);

        $this->sendPayload($payload)
            ->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('note_id', 481);

        $note = MasarOrderNote::query()->sole();

        $this->assertSame((int) $order->getKey(), (int) $note->delivery_order_id);
        $this->assertSame(481, (int) $note->masar_note_id);
        $this->assertSame('المستلِم غير متاح', $note->content);
        $this->assertSame(7, (int) $note->masar_representative_id);
        $this->assertSame('2026-09-06 12:00:00', $note->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame($payload['event_id'], $note->masar_event_id);
    }

    /**
     * The stored instant is the courier's, not ours (§13.16.1).
     *
     * `occurred_at` comes from the payload; `created_at` is when we stored it.
     * Conflating them would lose the only record of when the note was written.
     */
    public function test_the_stored_instant_is_the_one_the_event_carried(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->envelope($order);
        $payload['occurred_at'] = '2026-09-01T08:30:00Z';
        $payload['data']['created_at'] = '2026-09-01T08:30:00Z';

        $this->sendPayload($payload)->assertOk();

        $note = MasarOrderNote::query()->sole();

        $this->assertSame('2026-09-01 08:30:00', $note->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-06 12:00:00', $note->created_at->format('Y-m-d H:i:s'));
    }

    /**
     * Many notes on one order, all kept, in the order they were written
     * (§13.16.2).
     *
     * The property a high-water mark would have destroyed.
     */
    public function test_every_note_on_one_order_is_kept(): void
    {
        $order = $this->assignedOrder();

        foreach ([[11, 'الأولى'], [12, 'الثانية'], [13, 'الثالثة']] as [$id, $content]) {
            $this->sendPayload($this->envelope($order, noteId: $id, content: $content))
                ->assertOk()
                ->assertJsonPath('status', 'processed');
        }

        $notes = MasarOrderNote::query()->orderBy('masar_note_id')->get();

        $this->assertCount(3, $notes);
        $this->assertSame([11, 12, 13], $notes->map(fn ($n) => (int) $n->masar_note_id)->all());
        $this->assertSame(['الأولى', 'الثانية', 'الثالثة'], $notes->map(fn ($n) => $n->content)->all());
    }

    /**
     * A note that arrives after a higher-numbered one is still stored
     * (§13.16.2).
     *
     * This is the case that separates identity from version. On the data or
     * status channel this would be `ignored_stale`; here it is an ordinary
     * append, because the two notes are not versions of one another.
     */
    public function test_a_lower_numbered_note_arriving_late_is_stored_not_ignored(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, noteId: 90, content: 'الأحدث'))->assertOk();

        $this->sendPayload($this->envelope($order, noteId: 12, content: 'وصلت متأخّرة'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame(2, MasarOrderNote::query()->count());
        $this->assertSame('وصلت متأخّرة', MasarOrderNote::query()->where('masar_note_id', 12)->sole()->content);

        // And nothing on this channel is ever filed as stale.
        $this->assertSame(0, MasarIntegrationEvent::query()->where('result', 'ignored_stale')->count());
    }

    /** Two orders may hold notes under the same Masar id (§13.16.3). */
    public function test_the_identity_is_unique_per_order_not_globally(): void
    {
        $first = $this->assignedOrder();
        $second = $this->assignedOrder();

        $this->sendPayload($this->envelope($first, noteId: 5, content: 'على الأوّل'))->assertOk();
        $this->sendPayload($this->envelope($second, noteId: 5, content: 'على الثاني'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame(2, MasarOrderNote::query()->count());
    }

    // -------------------------------------------------------- idempotency

    public function test_a_retry_of_the_same_event_replays_its_answer(): void
    {
        $order = $this->assignedOrder();
        $payload = $this->envelope($order, noteId: 42);

        $this->sendPayload($payload)->assertOk()->assertJsonPath('status', 'processed');

        $this->sendPayload($payload)
            ->assertOk()
            ->assertJsonPath('status', 'already_processed')
            ->assertJsonPath('note_id', 42);

        $this->assertSame(1, MasarOrderNote::query()->count());
    }

    public function test_one_event_id_with_a_different_payload_is_a_conflict(): void
    {
        $order = $this->assignedOrder();
        $payload = $this->envelope($order, noteId: 42, content: 'الأصل');

        $this->sendPayload($payload)->assertOk();

        $payload['data']['content'] = 'شيء آخر';

        $this->sendPayload($payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $this->assertSame('الأصل', MasarOrderNote::query()->sole()->content);
    }

    /**
     * The same note announced again under a fresh `event_id` is settled, not
     * re-applied (§13.16.3).
     *
     * A note is immutable, so a second announcement of one adds nothing. Storing
     * it twice is what the unique index forbids; refusing it would make an
     * honest re-send look like a fault.
     */
    public function test_the_same_note_under_a_new_event_id_is_already_processed(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, noteId: 42, content: 'نصّ واحد'))->assertOk();

        $this->sendPayload($this->envelope($order, noteId: 42, content: 'نصّ واحد'))
            ->assertOk()
            ->assertJsonPath('status', 'already_processed')
            ->assertJsonPath('note_id', 42);

        $this->assertSame(1, MasarOrderNote::query()->count());
    }

    /**
     * One identity with two different texts is refused (§13.16.3).
     *
     * Masar would have contradicted its own immutability rule, and this system
     * has no basis for choosing which of the two a courier actually wrote.
     */
    public function test_one_note_id_with_different_content_is_an_identity_conflict(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, noteId: 42, content: 'الأصل'))->assertOk();

        $this->sendPayload($this->envelope($order, noteId: 42, content: 'نصّ مختلف'))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'NOTE_IDENTITY_CONFLICT');

        $this->assertSame('الأصل', MasarOrderNote::query()->sole()->content);
    }

    /** A different author under one identity is refused for the same reason. */
    public function test_one_note_id_with_a_different_author_is_an_identity_conflict(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, noteId: 42, representativeId: 3))->assertOk();

        $this->sendPayload($this->envelope($order, noteId: 42, representativeId: 9))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'NOTE_IDENTITY_CONFLICT');
    }

    public function test_a_refused_event_replays_its_refusal(): void
    {
        $payload = $this->envelope(externalOrderId: '999999');

        $this->sendPayload($payload)->assertNotFound()->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
        $this->sendPayload($payload)->assertNotFound()->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

        $this->assertSame(1, MasarIntegrationEvent::query()->count());
    }

    // ------------------------------------------------------------- no echo

    /**
     * Storing a note enqueues nothing (§13.16.4).
     *
     * The barrier that keeps an announcement from returning to its author.
     */
    public function test_applying_a_note_enqueues_nothing(): void
    {
        $order = $this->assignedOrder();

        $before = IntegrationOutbox::query()->count();

        $this->sendPayload($this->envelope($order))->assertOk();

        $this->assertSame($before, IntegrationOutbox::query()->count());
    }

    /**
     * And it does not touch the order's own outbound notion of a note
     * (§13.16.4).
     *
     * `delivery_orders` has no note column at all, and this asserts the adjacent
     * fact: nothing about the order row moves, so nothing this system later
     * announces will carry the courier's words back.
     */
    public function test_applying_a_note_leaves_the_order_row_untouched(): void
    {
        $order = $this->assignedOrder();

        $before = DeliveryOrder::query()->whereKey($order->getKey())->sole()->toArray();

        $this->sendPayload($this->envelope($order))->assertOk();

        $after = DeliveryOrder::query()->whereKey($order->getKey())->sole()->toArray();

        $this->assertEquals($before, $after);
    }

    /** The note channel moves no other channel's high-water mark (§13.12). */
    public function test_a_note_moves_no_version_on_the_order(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order))->assertOk();

        $fresh = DeliveryOrder::query()->whereKey($order->getKey())->sole();

        $this->assertSame(0, (int) $fresh->masar_status_version);
        $this->assertSame(0, (int) $fresh->masar_data_version);
        $this->assertSame(0, (int) $fresh->masar_location_version);
    }

    /** The ledger records the note's identity and no version (§13.16.2). */
    public function test_the_ledger_records_the_note_identity_and_no_version(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->envelope($order, noteId: 77))->assertOk();

        $event = MasarIntegrationEvent::query()->sole();

        $this->assertSame('order.note.created', $event->event_type);
        $this->assertSame(77, (int) $event->masar_note_id);
        $this->assertSame('processed', $event->result);
        $this->assertNull($event->status_version);
        $this->assertNull($event->data_version);
        $this->assertNull($event->location_version);
    }

    // ------------------------------------------------------------- helpers

    private function envelope(
        ?DeliveryOrder $order = null,
        ?string $externalOrderId = null,
        int $noteId = 1,
        string $content = 'ملاحظة',
        int $representativeId = 7,
    ): array {
        return [
            'contract_version' => MasarNoteEnvelope::CONTRACT_VERSION,
            'event_id' => (string) Str::uuid(),
            'event_type' => MasarNoteEnvelope::EVENT_TYPE,
            'occurred_at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'data' => [
                'order_id' => $externalOrderId ?? (string) $order?->getKey(),
                'note_id' => $noteId,
                'content' => $content,
                'representative_id' => $representativeId,
                'created_at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'),
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
