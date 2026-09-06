<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\DeliveryStatus;
use App\Enums\OrderResultReason;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\Integration\MasarStatusEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The two follow-up timer reasons, arriving on the existing status channel
 * (CONTRACT §3.21.5, §13.6 — v5.2).
 *
 * Masar's vocabulary grew from eleven codes to twelve, and this receiver has to
 * be able to store what Masar legitimately sends. Nothing else about the channel
 * moves: no new event type, no change to `status_version` ordering, to
 * `event_id` idempotency, to `VERSION_CONFLICT`, to `ignored_stale`, or to the
 * no-echo barrier. The point of this file is to prove that the vocabulary is the
 * only thing that changed.
 *
 * **Acceptance is not selection.** Neither code belongs to this company's own
 * result vocabulary and nothing here offers them as a choice; they arrive from
 * Masar and are stored, which is what a mirror does.
 *
 * The pairing is checked in both directions like every other reason's:
 * `follow_up_timer_opened` only with `postponed`, `follow_up_timer_expired` only
 * with `returned`, and the crossed pairs refused.
 */
class MasarTimerReasonReceiverTest extends TestCase
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

        $response = $this->postJson(self::TOKENS, ['client_id' => 'masar', 'client_secret' => 'masar-secret']);
        $response->assertOk();
        $this->token = $response->json('access_token');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------- accepted

    /** Both codes are stored, each with the status it belongs to. */
    #[DataProvider('timerAnnouncements')]
    public function test_a_timer_reason_is_applied_with_its_own_status(string $status, string $reason): void
    {
        $order = $this->assignedOrder();

        $this->send($order, $status, $reason)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $order->refresh();
        $this->assertSame($status, $order->delivery_status->value);
        $this->assertSame($reason, $order->status_reason);
        $this->assertSame(1, (int) $order->masar_status_version);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function timerAnnouncements(): array
    {
        return [
            'the courier opened a timer' => ['postponed', 'follow_up_timer_opened'],
            'the timer ran out' => ['returned', 'follow_up_timer_expired'],
        ];
    }

    /**
     * The whole life of one timer, in the order Masar announces it.
     *
     * Two events, two versions, and the second replaces the first — which is the
     * ordinary behaviour of this channel and is asserted here to show the new
     * codes ride it unchanged.
     */
    public function test_an_opening_then_an_expiry_converges_on_the_return(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, 'postponed', 'follow_up_timer_opened', version: 1)->assertOk();

        $order->refresh();
        $this->assertSame('postponed', $order->delivery_status->value);
        $this->assertSame('follow_up_timer_opened', $order->status_reason);

        $this->send($order, 'returned', 'follow_up_timer_expired', version: 2)->assertOk();

        $order->refresh();
        $this->assertSame('returned', $order->delivery_status->value);
        $this->assertSame('follow_up_timer_expired', $order->status_reason);
        $this->assertSame(2, (int) $order->masar_status_version);
    }

    // ------------------------------------------------------------- refused

    /**
     * §3.21.5 — «القسمةُ ملزمةٌ في الطرفين لا في المرسِل وحده».
     *
     * The two new codes obey the same division as the ten: each belongs to one
     * status, and the crossed pair is refused with the same `422` an unknown
     * code gets.
     */
    #[DataProvider('crossedPairs')]
    public function test_a_timer_reason_with_the_wrong_status_is_refused(string $status, string $reason): void
    {
        $order = $this->assignedOrder();

        $this->send($order, $status, $reason)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $order->refresh();
        $this->assertSame(DeliveryStatus::WithRepresentative, $order->delivery_status);
        $this->assertSame(0, (int) $order->masar_status_version);
        $this->assertSame(0, MasarIntegrationEvent::query()->count());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function crossedPairs(): array
    {
        return [
            'the opening reason with a return' => ['returned', 'follow_up_timer_opened'],
            'the expiry reason with a postponement' => ['postponed', 'follow_up_timer_expired'],
            'the opening reason with a delivery' => ['delivered', 'follow_up_timer_opened'],
            'the expiry reason with a clear' => ['with_rep', 'follow_up_timer_expired'],
        ];
    }

    /** And a code outside the twelve is still refused. */
    public function test_an_unknown_reason_is_still_refused(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, 'postponed', 'follow_up_timer_paused')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // ------------------------------------------------ the channel is unchanged

    /** §52 — replay, staleness and conflict behave exactly as in v4.9. */
    public function test_the_ordering_rules_are_unchanged_for_the_new_codes(): void
    {
        $order = $this->assignedOrder();

        $opening = $this->envelope($order, 'postponed', 'follow_up_timer_opened', 4);

        // Applied.
        $this->sendPayload($opening)->assertOk()->assertJsonPath('status', 'processed');

        // The same event again — the saved answer, not a second application.
        $this->sendPayload($opening)->assertOk()->assertJsonPath('status', 'already_processed');

        // An older announcement — a successful convergence, not an error.
        $this->sendPayload($this->envelope($order, 'returned', 'follow_up_timer_expired', 2))
            ->assertOk()
            ->assertJsonPath('status', 'ignored_stale')
            ->assertJsonPath('applied_status_version', 4);

        // A different event claiming the applied version — neither is applied.
        $this->sendPayload($this->envelope($order, 'returned', 'follow_up_timer_expired', 4))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $order->refresh();
        $this->assertSame('postponed', $order->delivery_status->value);
        $this->assertSame('follow_up_timer_opened', $order->status_reason);
        $this->assertSame(4, (int) $order->masar_status_version);
    }

    /**
     * §53 — applying a timer status produces no event back.
     *
     * The barrier that keeps the two systems from announcing one change to each
     * other for ever. It is v4.9's and unchanged; asserted here because a new
     * reason code reaching a different writer would break it silently.
     */
    public function test_applying_a_timer_status_announces_nothing(): void
    {
        $order = $this->assignedOrder();

        $outboxBefore = IntegrationOutbox::query()->count();

        $this->send($order, 'postponed', 'follow_up_timer_opened')->assertOk();
        $this->send($order, 'returned', 'follow_up_timer_expired', version: 2)->assertOk();

        $this->assertSame($outboxBefore, IntegrationOutbox::query()->count());
    }

    /**
     * §51 — the local lifecycle columns are not touched by this channel.
     *
     * `result`, `status` and `completed_at` are this company's own and Masar
     * does not decide them (§3.21.10). A timer expiry arriving as `returned`
     * must not be translated into a local completion.
     */
    public function test_the_local_lifecycle_columns_are_untouched(): void
    {
        $order = $this->assignedOrder();

        $this->send($order, 'returned', 'follow_up_timer_expired')->assertOk();

        $order->refresh();
        $this->assertSame(DeliveryOrderStatus::Assigned, $order->status);
        $this->assertNull($order->result);
        $this->assertNull($order->completed_at);
    }

    /** The vocabulary itself: twelve codes, each bound to one status. */
    public function test_the_reason_vocabulary_holds_twelve_codes(): void
    {
        $this->assertCount(12, OrderResultReason::codes());

        $this->assertContains('follow_up_timer_opened', OrderResultReason::codesFor(DeliveryStatus::Postponed));
        $this->assertContains('follow_up_timer_expired', OrderResultReason::codesFor(DeliveryStatus::Returned));

        $this->assertNotContains('follow_up_timer_expired', OrderResultReason::codesFor(DeliveryStatus::Postponed));
        $this->assertNotContains('follow_up_timer_opened', OrderResultReason::codesFor(DeliveryStatus::Returned));
    }

    // ------------------------------------------------------------- machinery

    private function send(DeliveryOrder $order, string $status, ?string $reason, int $version = 1): TestResponse
    {
        return $this->sendPayload($this->envelope($order, $status, $reason, $version));
    }

    /** @param array<string, mixed> $payload */
    private function sendPayload(array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson(self::EVENTS, $payload);
    }

    /** @return array<string, mixed> */
    private function envelope(DeliveryOrder $order, string $status, ?string $reason, int $version = 1): array
    {
        $instant = Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'contract_version' => MasarStatusEnvelope::CONTRACT_VERSION,
            'event_id' => (string) Str::uuid(),
            'event_type' => MasarStatusEnvelope::EVENT_TYPE,
            'occurred_at' => $instant,
            'data' => [
                'order_id' => (string) $order->getKey(),
                'status_version' => $version,
                'delivery_status' => $status,
                'status_reason' => $reason,
                'result_occurred_at' => $instant,
            ],
        ];
    }

    private function assignedOrder(): DeliveryOrder
    {
        $customer = Customer::create([
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
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);

        return app(DeliveryOrderLifecycleService::class)
            ->assignRepresentative($order, $representative);
    }
}
