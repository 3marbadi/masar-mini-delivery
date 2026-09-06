<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Models\MasarOrderNote;
use App\Services\Integration\MasarLocationEnvelope;
use App\Services\Integration\MasarNoteEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The wire form of the two v5.5 channels, pinned (CONTRACT §13.16.1, §13.17.1).
 *
 * The counterpart of `MasarStatusContractTest`, and it exists for the same
 * reason. Masar and Mini Delivery are separate deployments that share no code,
 * so each envelope exists twice — built there, verified here. Two suites can
 * both be green while disagreeing about what "the same event" means, and the
 * disagreement would surface only in production, on a retry, as a `409` on a
 * perfectly ordinary repeat.
 *
 * So the exact bytes and the exact digests are written out here as literals, and
 * the identical literals appear in Masar's `OutboundChannelContractTest`. If
 * either canonicalisation drifts, one of the two suites fails immediately.
 *
 * The literals are also *sent*, not merely hashed: an envelope that hashes
 * correctly but fails validation would be just as broken, and only a real
 * request through the real endpoint proves both at once.
 *
 * The note literal carries Arabic text on purpose. `JSON_UNESCAPED_UNICODE` is
 * part of the digest rule on both sides, and a deployment that escaped instead
 * would produce a different hash for identical content — a divergence that only
 * non-ASCII would reveal, and every real note here is non-ASCII.
 */
class MasarNoteLocationContractTest extends TestCase
{
    use RefreshDatabase;

    /** One note, written by courier 7 on order 125 (§13.16.1). */
    private const NOTE = [
        'contract_version' => '1.0',
        'event_id' => 'b41d0f5a-3c1e-4a77-9c22-7d5e2f0a1b33',
        'event_type' => 'order.note.created',
        'occurred_at' => '2026-09-06T11:42:00Z',
        'data' => [
            'order_id' => '125',
            'note_id' => 481,
            'content' => 'المستلِم غير متاح، أعاود غداً',
            'representative_id' => 7,
            'created_at' => '2026-09-06T11:42:00Z',
        ],
    ];

    private const NOTE_DIGEST = 'ebc9ccdae2e331cafcd1925c27d8676aa4b4f4f8fde2e353f315f59f3892f24d';

    /** One completed location at version 1, stamped when it committed (§13.17.1). */
    private const LOCATION = [
        'contract_version' => '1.0',
        'event_id' => 'c52e1a6b-4d2f-4b88-8d33-6e4f3a1b2c44',
        'event_type' => 'order.location.updated',
        'occurred_at' => '2026-09-06T12:00:00Z',
        'data' => [
            'order_id' => '125',
            'location_version' => 1,
            'location_changed_at' => '2026-09-06T12:00:00Z',
            'location_change_source' => 'masar',
            'latitude' => '32.8851000',
            'longitude' => '13.2010000',
            'location_completed_at' => '2026-09-06T12:00:00Z',
        ],
    ];

    private const LOCATION_DIGEST = '9d2994487d4e9debcd3997e5c5b0754f0790f225e1ba1e6cc621fa9c02478c0f';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 12:00:00');

        MasarIntegrationClient::create([
            'name' => 'Masar',
            'client_id' => 'masar',
            'client_secret_hash' => Hash::make('masar-secret'),
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_receiver_computes_the_same_digests_masar_does(): void
    {
        $this->assertSame(self::NOTE_DIGEST, MasarNoteEnvelope::hash(self::NOTE));
        $this->assertSame(self::LOCATION_DIGEST, MasarLocationEnvelope::hash(self::LOCATION));
    }

    public function test_key_order_on_the_wire_does_not_change_either_identity(): void
    {
        // JSON has no ordering guarantee, and neither client controls how a
        // proxy or a serialiser lays out the object. If key order reached the
        // digest, an identical retry could be read as a different announcement.
        $shuffledNote = [
            'data' => [
                'created_at' => '2026-09-06T11:42:00Z',
                'representative_id' => 7,
                'content' => 'المستلِم غير متاح، أعاود غداً',
                'note_id' => 481,
                'order_id' => '125',
            ],
            'event_type' => 'order.note.created',
            'occurred_at' => '2026-09-06T11:42:00Z',
            'contract_version' => '1.0',
            'event_id' => 'b41d0f5a-3c1e-4a77-9c22-7d5e2f0a1b33',
        ];

        $shuffledLocation = [
            'data' => [
                'location_completed_at' => '2026-09-06T12:00:00Z',
                'longitude' => '13.2010000',
                'latitude' => '32.8851000',
                'location_change_source' => 'masar',
                'location_changed_at' => '2026-09-06T12:00:00Z',
                'location_version' => 1,
                'order_id' => '125',
            ],
            'event_id' => 'c52e1a6b-4d2f-4b88-8d33-6e4f3a1b2c44',
            'occurred_at' => '2026-09-06T12:00:00Z',
            'event_type' => 'order.location.updated',
            'contract_version' => '1.0',
        ];

        $this->assertSame(self::NOTE_DIGEST, MasarNoteEnvelope::hash($shuffledNote));
        $this->assertSame(self::LOCATION_DIGEST, MasarLocationEnvelope::hash($shuffledLocation));
    }

    /**
     * The two channels never collide on identity, however alike the envelopes
     * look (§13.12).
     */
    public function test_the_two_channels_hash_differently(): void
    {
        $this->assertNotSame(self::NOTE_DIGEST, self::LOCATION_DIGEST);
    }

    public function test_the_pinned_envelopes_are_accepted_end_to_end(): void
    {
        $this->order125();
        $token = $this->token();

        $this->send($token, self::NOTE)
            ->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('note_id', 481);

        $this->send($token, self::LOCATION)
            ->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('applied_location_version', 1);

        $note = MasarOrderNote::query()->sole();
        $this->assertSame(481, (int) $note->masar_note_id);
        $this->assertSame('المستلِم غير متاح، أعاود غداً', $note->content);

        $order = DeliveryOrder::query()->whereKey(125)->sole();
        $this->assertSame('32.8851000', $order->latitude);
        $this->assertSame('13.2010000', $order->longitude);
        $this->assertSame(1, (int) $order->masar_location_version);
        // The note channel left every version alone (§13.12).
        $this->assertSame(0, (int) $order->masar_status_version);
        $this->assertSame(0, (int) $order->masar_data_version);
    }

    /** Both pinned envelopes replay as themselves (§13.16.3, §13.17.3). */
    public function test_the_pinned_envelopes_replay_as_themselves(): void
    {
        $this->order125();
        $token = $this->token();

        $this->send($token, self::NOTE)->assertOk()->assertJsonPath('status', 'processed');
        $this->send($token, self::NOTE)->assertOk()->assertJsonPath('status', 'already_processed');

        $this->send($token, self::LOCATION)->assertOk()->assertJsonPath('status', 'processed');
        $this->send($token, self::LOCATION)->assertOk()->assertJsonPath('status', 'already_processed');

        $this->assertSame(1, MasarOrderNote::query()->count());
    }

    // ------------------------------------------------------------- helpers

    private function send(string $token, array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/integration/events', $payload);
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/integration/auth/token', [
            'client_id' => 'masar',
            'client_secret' => 'masar-secret',
        ])->json('access_token');
    }

    /**
     * The order the pinned envelopes name.
     *
     * Its outbound sequence is seeded so the fixture resembles a real order, but
     * nothing in the location literal depends on it any more: D13 arbitrates by
     * stamp, and this order holds none, so the announcement wins against nothing
     * (§13.17.5).
     */
    private function order125(): DeliveryOrder
    {
        $customer = Customer::create(['name' => 'زبون', 'phone' => '+218910000125']);

        $order = new DeliveryOrder;
        $order->forceFill([
            'id' => 125,
            'customer_id' => $customer->id,
            'recipient_name' => 'زبون',
            'recipient_phone' => '+218910000125',
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder->value,
        ])->save();

        $order->integrationState()->create(['current_version' => 12]);

        return $order->refresh();
    }
}
