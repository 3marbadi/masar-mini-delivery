<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\DeliveryStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Services\Integration\MasarStatusEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The wire form, pinned (CONTRACT §3.21.3, §3.21.6, §3.21.11).
 *
 * Masar and Mini Delivery are separate deployments that share no code, so the
 * envelope exists twice — built there, verified here. Two suites can both be
 * green while disagreeing about what "the same event" means, and the
 * disagreement would surface only in production, on a retry, as a `409` on a
 * perfectly ordinary repeat.
 *
 * So the exact bytes and the exact digests are written out here as literals, and
 * the identical literals appear in Masar's `DeliveryStatusOwnershipTest`. If
 * either canonicalisation drifts, one of the two suites fails immediately
 * instead.
 *
 * Two literals rather than one, and the second is not decoration: `with_rep` at
 * version 2 is the clear, which v4.8 did not send at all and v4.9 does. It is
 * the shape most likely to be got wrong on one side only — a null reason, a
 * status the inbound direction still forbids — so it is pinned too.
 *
 * The literals are also *sent*, not merely hashed: an envelope that hashes
 * correctly but fails validation would be just as broken, and only a real
 * request through the real endpoint proves both at once. And they are sent in
 * order, so the pair proves the ordering rule as well as the format.
 */
class MasarStatusContractTest extends TestCase
{
    use RefreshDatabase;

    /** A postponement at version 1 — the first announcement about an order. */
    private const POSTPONED_V1 = [
        'contract_version' => '1.0',
        'event_id' => '9f1c1c2e-6d1a-4a55-9f3f-2b3f5a5e77aa',
        'event_type' => 'order.delivery_status.updated',
        'occurred_at' => '2026-09-05T11:42:00Z',
        'data' => [
            'order_id' => '125',
            'status_version' => 1,
            'delivery_status' => 'postponed',
            'status_reason' => 'customer_absent',
            'result_occurred_at' => '2026-09-05T11:42:00Z',
        ],
    ];

    /** The clear that withdraws it, at version 2. */
    private const CLEARED_V2 = [
        'contract_version' => '1.0',
        'event_id' => 'b47ac10b-58cc-4372-a567-0e02b2c3d479',
        'event_type' => 'order.delivery_status.updated',
        'occurred_at' => '2026-09-05T13:05:00Z',
        'data' => [
            'order_id' => '125',
            'status_version' => 2,
            'delivery_status' => 'with_rep',
            'status_reason' => null,
            'result_occurred_at' => '2026-09-05T13:05:00Z',
        ],
    ];

    /** The digests Masar computes for them, asserted there as literals too. */
    private const POSTPONED_V1_DIGEST = 'cee9aa28fe804cdb2fefee3b3f6027bdcee5b775c2d1f2a79fa6d3af5efea043';

    private const CLEARED_V2_DIGEST = 'e0c75bf2657aa58844768ff0475120a2f53d6c7cdc70d6c0e79286de0db236c8';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-05 14:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_receiver_computes_the_same_digests_masar_does(): void
    {
        $this->assertSame(self::POSTPONED_V1_DIGEST, MasarStatusEnvelope::hash(self::POSTPONED_V1));
        $this->assertSame(self::CLEARED_V2_DIGEST, MasarStatusEnvelope::hash(self::CLEARED_V2));
    }

    public function test_key_order_on_the_wire_does_not_change_the_identity(): void
    {
        // JSON has no ordering guarantee, and neither client controls how a
        // proxy or a serialiser lays out the object. If key order reached the
        // digest, an identical retry could be read as a different announcement.
        $shuffled = [
            'data' => [
                'status_reason' => 'customer_absent',
                'result_occurred_at' => '2026-09-05T11:42:00Z',
                'delivery_status' => 'postponed',
                'status_version' => 1,
                'order_id' => '125',
            ],
            'event_type' => 'order.delivery_status.updated',
            'occurred_at' => '2026-09-05T11:42:00Z',
            'contract_version' => '1.0',
            'event_id' => '9f1c1c2e-6d1a-4a55-9f3f-2b3f5a5e77aa',
        ];

        $this->assertSame(self::POSTPONED_V1_DIGEST, MasarStatusEnvelope::hash($shuffled));
    }

    public function test_the_pinned_envelopes_are_accepted_end_to_end_in_order(): void
    {
        $order = $this->order125();
        $token = $this->token();

        $this->sendEvent($token, self::POSTPONED_V1)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('event_id', self::POSTPONED_V1['event_id'])
            ->assertJsonPath('order_id', '125');

        $order->refresh();
        $this->assertSame(DeliveryStatus::Postponed, $order->delivery_status);
        $this->assertSame('customer_absent', $order->status_reason);
        $this->assertSame(1, $order->masar_status_version);

        $this->sendEvent($token, self::CLEARED_V2)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $order->refresh();
        $this->assertSame(DeliveryStatus::WithRepresentative, $order->delivery_status);
        $this->assertNull($order->status_reason);
        $this->assertSame(2, $order->masar_status_version);
    }

    /**
     * The cross-repository semantic case, run against the receiver with the
     * exact literals Masar pins.
     *
     * The two systems cannot be booted against each other here, so the proof is
     * split: Masar's suite asserts that these bytes are what it builds, and this
     * asserts that the receiver, fed those bytes in the wrong order, converges
     * on the right state anyway.
     */
    public function test_the_withdrawn_announcement_cannot_return_after_the_clear(): void
    {
        $order = $this->order125();
        $token = $this->token();

        $this->sendEvent($token, self::POSTPONED_V1)->assertOk();
        $this->sendEvent($token, self::CLEARED_V2)->assertOk();

        // The retry of the postponement, arriving after the clear that
        // withdrew it — the shape of the defect this contract version closes.
        $this->sendEvent($token, self::POSTPONED_V1)
            ->assertOk()
            ->assertJsonPath('status', 'already_processed');

        // A *different* event carrying the same withdrawn state and the same
        // old version: stale, not a replay.
        $resent = self::POSTPONED_V1;
        $resent['event_id'] = '0f8fad5b-d9cb-469f-a165-70867728950e';

        $this->sendEvent($token, $resent)
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale')
            ->assertJsonPath('applied_status_version', 2);

        $order->refresh();

        $this->assertSame(DeliveryStatus::WithRepresentative, $order->delivery_status);
        $this->assertNull($order->status_reason);
        $this->assertSame(2, $order->masar_status_version);
    }

    // -------------------------------------------------------------- fixtures

    private function token(): string
    {
        MasarIntegrationClient::query()->firstOrCreate(
            ['client_id' => 'masar'],
            ['name' => 'Masar', 'client_secret_hash' => Hash::make('masar-secret'), 'status' => 'active'],
        );

        return $this->postJson('/api/v1/integration/auth/token', [
            'client_id' => 'masar',
            'client_secret' => 'masar-secret',
        ])->assertOk()->json('access_token');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendEvent(string $token, array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/integration/events', $payload);
    }

    private function order125(): DeliveryOrder
    {
        $order = new DeliveryOrder;

        // The literals name order 125, so the fixture must be that order and no
        // other — the identity in the payload is Mini Delivery's own id
        // (§3.21.4), not something the receiver is free to reinterpret.
        //
        // `forceFill`, because the id is the point: `create()` would ignore it
        // as unfillable and give the row whatever the sequence had next.
        $order->forceFill([
            'id' => 125,
            'customer_id' => Customer::create(['name' => 'Customer', 'phone' => '0911234567'])->id,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ])->save();

        return $order;
    }
}
