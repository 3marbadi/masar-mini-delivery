<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The receiver's treatment of a participation event that carries no scheduled
 * departure (stage-5D contract review).
 *
 * **Why this file exists.** The review of the proposed §13.29 compared the
 * payload table against both implementations field by field, and found the one
 * place where they disagree: `tour_departure_at` is `required` here, with no
 * `nullable`, while Masar emits `null` for it on every `ended` transition —
 * `TourCloser`, `TourMembershipWriter::endForReassignedOrder()` and the
 * reconciler's ended branch all pass null, because a participation that has
 * ended has no departure to report and the reconciler deliberately refuses to
 * invent one.
 *
 * Neither side's own tests could catch that. Masar's suites assert against a
 * faked receiver that validates only `order_id`; this repository's suites build
 * their payloads by hand and have always supplied a departure. The two were
 * never actually joined on this field.
 *
 * **Resolved in D31, and these are now its regression tests.** The rule became
 * `['present', 'nullable', …]`: the key stays mandatory, because a payload
 * omitting it is a different shape and not an ending, and only the value may be
 * null — exactly as `base_order_version` already was. The first case below
 * asserts the acceptance; the others hold the line that nullability is not
 * looseness.
 */
class MasarParticipationNullDepartureTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTS = '/api/v1/integration/events';

    private const TOKENS = '/api/v1/integration/auth/token';

    private const PARTICIPATION = 'order.tour.participation.updated';

    private const NOW = '2026-10-08 07:00:00';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);

        MasarIntegrationClient::create([
            'name' => 'Masar',
            'client_id' => 'masar',
            'client_secret_hash' => Hash::make('masar-secret'),
            'status' => 'active',
        ]);

        $this->token = $this->postJson(self::TOKENS, [
            'client_id' => 'masar',
            'client_secret' => 'masar-secret',
        ])->assertOk()->json('access_token');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The correction, stated as a test: an `ended` with a null departure —
     * exactly what a tour closure produces — is **accepted and applied**.
     *
     * Before D31 this was answered `422`, which §3.21.7 makes terminal, so every
     * ending was lost for good and the order kept reading «جاري التوصيل» for a
     * tour that had finished. The rule now matches what the sender emits.
     */
    public function test_an_ended_event_with_a_null_departure_is_accepted(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->participation($order, 'ended', 1);
        $payload['data']['tour_departure_at'] = null;

        $this->sendPayload($payload)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $order->refresh();
        $this->assertSame('ended', $order->masar_participation->value);
        $this->assertSame(1, $order->masar_participation_version);
        $this->assertNull($order->masar_tour_departure_at);
        $this->assertNotNull($order->masar_participation_ended_at);
    }

    /**
     * A malformed value is still refused — nullability is not looseness.
     */
    public function test_a_malformed_departure_is_still_refused(): void
    {
        $order = $this->assignedOrder();

        foreach (['not-a-date', '2026-10-08 07:30:00', '2026-10-08T07:30:00', 1760000000] as $bad) {
            $payload = $this->participation($order, 'scheduled', 1);
            $payload['data']['tour_departure_at'] = $bad;

            $this->sendPayload($payload)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }

        $this->assertSame(0, $order->fresh()->masar_participation_version);
        $this->assertSame(0, MasarIntegrationEvent::query()->count());
    }

    /** Omitting the key altogether is refused for the same reason. */
    public function test_an_ended_event_missing_the_departure_key_is_refused(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->participation($order, 'ended', 1);
        unset($payload['data']['tour_departure_at']);

        $this->sendPayload($payload)->assertStatus(422);

        $this->assertSame(0, $order->fresh()->masar_participation_version);
    }

    /**
     * The contrast that isolates the cause: the identical event with a departure
     * is accepted.
     *
     * So the refusal is about this one field and nothing else — not the
     * `ended` value, not the version, not the shape.
     */
    public function test_the_same_ended_event_is_accepted_when_a_departure_is_present(): void
    {
        $order = $this->assignedOrder();

        $this->sendPayload($this->participation($order, 'ended', 1))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $order->refresh();
        $this->assertSame('ended', $order->masar_participation->value);
        $this->assertSame(1, $order->masar_participation_version);
    }

    /**
     * And `base_order_version` shows the treatment the field needs: its key is
     * required and its value may be null.
     *
     * Recorded here because it is the precedent the review recommends following
     * — `['present', 'nullable', …]` rather than `['required', …]` — and because
     * it proves the receiver already knows how to carry a nullable member of
     * `data` without loosening anything else.
     */
    public function test_a_null_base_order_version_is_already_accepted(): void
    {
        $order = $this->assignedOrder();

        $payload = $this->participation($order, 'scheduled', 1);
        $payload['data']['base_order_version'] = null;

        $this->sendPayload($payload)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertNull($order->fresh()->masar_participation_base_order_version);
    }

    // ------------------------------------------------------------ machinery

    /** @return array<string, mixed> */
    private function participation(DeliveryOrder $order, string $participation, int $version): array
    {
        return [
            'contract_version' => '1.0',
            'event_id' => (string) Str::uuid(),
            'event_type' => self::PARTICIPATION,
            'occurred_at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'data' => [
                'order_id' => (string) $order->integration_uid,
                'participation_version' => $version,
                'participation' => $participation,
                'base_order_version' => (int) $order->assignment_order_version,
                'external_courier_id' => (string) $order->representative->integration_uid,
                'tour_reference' => '4120',
                'tour_departure_at' => '2026-10-08T07:30:00Z',
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function sendPayload(array $payload): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson(self::EVENTS, $payload);
    }

    private function assignedOrder(): DeliveryOrder
    {
        $customer = Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => '091'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        ]);

        $representative = Representative::create([
            'name' => 'Rep '.uniqid(),
            'phone' => '092'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'is_active' => true,
        ]);

        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ]);

        return app(DeliveryOrderLifecycleService::class)->assignRepresentative($order, $representative);
    }
}
