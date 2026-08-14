<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\IntegrationEventType;
use App\Enums\IntegrationOutboxStatus;
use App\Enums\IntegrationSendResult;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\OrderIntegrationState;
use App\Models\Representative;
use App\Services\IntegrationOutboxSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationOutboxSenderTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationOutboxSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-14 14:00:00');
        config()->set([
            'services.masar.base_url' => 'https://masar.test/base',
            'services.masar.token' => 'test-secret-token',
            'services.masar.endpoint_path' => '/api/integration/events',
            'services.masar.timeout' => 10,
            'services.masar.batch_limit' => 50,
        ]);
        $this->sender = app(IntegrationOutboxSender::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_all_contract_terminal_success_responses_mark_event_sent(): void
    {
        $statuses = ['processed', 'already_processed', 'ignored_stale'];
        Http::fake(fn (Request $request) => Http::response([
            'success' => true,
            'event_id' => $request['event_id'],
            'status' => array_shift($statuses),
        ]));

        foreach (range(1, 3) as $_iteration) {
            $event = $this->outbox();

            $result = $this->sender->send($event);
            $event->refresh();

            $this->assertSame(IntegrationSendResult::Sent, $result, (string) $event->last_error);
            $this->assertSame(IntegrationOutboxStatus::Sent, $event->status);
            $this->assertSame(1, $event->attempts);
            $this->assertNotNull($event->last_attempt_at);
            $this->assertNotNull($event->sent_at);
            $this->assertNull($event->last_error);
        }
    }

    public function test_only_successful_assignment_sets_assigned_transmitted_at(): void
    {
        $assigned = $this->outbox(type: IntegrationEventType::OrderAssigned);
        $updated = $this->outbox(type: IntegrationEventType::OrderUpdated);
        Http::fake(fn (Request $request) => Http::response([
            'success' => true,
            'event_id' => $request['event_id'],
            'status' => 'processed',
        ]));

        $this->sender->send($assigned);
        $this->sender->send($updated);

        $this->assertNotNull($assigned->deliveryOrder->integrationState->fresh()->assigned_transmitted_at);
        $this->assertNull($updated->deliveryOrder->integrationState->fresh()->assigned_transmitted_at);
    }

    public function test_connection_failure_and_retryable_http_statuses_remain_pending(): void
    {
        $connectionEvent = $this->outbox();
        Http::fake(fn () => throw new ConnectionException('Connection timed out with secret test-secret-token'));
        $this->assertSame(IntegrationSendResult::PendingRetry, $this->sender->send($connectionEvent));
        $connectionEvent->refresh();
        $this->assertSame(IntegrationOutboxStatus::Pending, $connectionEvent->status);
        $this->assertSame(1, $connectionEvent->attempts);
        $this->assertSame('Connection timeout', $connectionEvent->last_error);
        $this->assertNull($connectionEvent->sent_at);

        foreach ([429, 500, 503] as $status) {
            $event = $this->outbox();
            Http::fake(['*' => Http::response([], $status)]);

            $this->assertSame(IntegrationSendResult::PendingRetry, $this->sender->send($event));
            $this->assertSame(IntegrationOutboxStatus::Pending, $event->fresh()->status);
            $this->assertSame(1, $event->fresh()->attempts);
        }
    }

    public function test_non_retryable_http_statuses_fail_without_exposing_token(): void
    {
        foreach ([400, 401, 404, 409, 422] as $status) {
            $event = $this->outbox();
            Http::fake(['*' => Http::response([
                'error' => ['message' => 'test-secret-token'],
            ], $status)]);

            $this->assertSame(IntegrationSendResult::Failed, $this->sender->send($event));
            $event->refresh();
            $this->assertSame(IntegrationOutboxStatus::Failed, $event->status);
            $this->assertSame(1, $event->attempts);
            $this->assertNull($event->sent_at);
            $this->assertStringNotContainsString('test-secret-token', $event->last_error);
        }
    }

    public function test_invalid_success_status_and_event_id_mismatch_are_protocol_failures(): void
    {
        $unknown = $this->outbox();
        Http::fake(['*' => Http::response([
            'success' => true,
            'event_id' => $unknown->event_id,
            'status' => 'unknown',
        ])]);
        $this->assertSame(IntegrationSendResult::Failed, $this->sender->send($unknown));
        $this->assertSame('Invalid integration response: unsupported status', $unknown->fresh()->last_error);

        $mismatch = $this->outbox();
        Http::fake(['*' => Http::response([
            'success' => true,
            'event_id' => (string) Str::uuid(),
            'status' => 'processed',
        ])]);
        $this->assertSame(IntegrationSendResult::Failed, $this->sender->send($mismatch));
        $this->assertSame('Invalid integration response: event_id mismatch', $mismatch->fresh()->last_error);
    }

    public function test_batch_selects_only_earliest_unresolved_version_per_order(): void
    {
        $order = $this->order();
        $v1 = $this->outbox($order, version: 1);
        $v2 = $this->outbox($order, version: 2);
        $other = $this->outbox();

        $eligibleIds = $this->sender->eligibleBatch()->pluck('id')->all();

        $this->assertContains($v1->id, $eligibleIds);
        $this->assertContains($other->id, $eligibleIds);
        $this->assertNotContains($v2->id, $eligibleIds);

        Http::fake(fn (Request $request) => Http::response([
            'success' => true,
            'event_id' => $request['event_id'],
            'status' => 'processed',
        ]));
        $this->sender->send($v1);

        $this->assertContains($v2->id, $this->sender->eligibleBatch()->pluck('id')->all());
        $this->assertSame(0, $v2->fresh()->attempts);
        $this->assertNull($v2->fresh()->last_attempt_at);
    }

    public function test_failed_older_version_blocks_newer_version_without_attempt(): void
    {
        $order = $this->order();
        $this->outbox($order, version: 1, status: IntegrationOutboxStatus::Failed);
        $v2 = $this->outbox($order, version: 2);

        $this->assertNotContains($v2->id, $this->sender->eligibleBatch()->pluck('id')->all());
        $this->assertSame(IntegrationSendResult::Skipped, $this->sender->send($v2));
        $this->assertSame(0, $v2->fresh()->attempts);
        $this->assertNull($v2->fresh()->last_attempt_at);
        Http::assertNothingSent();
    }

    public function test_failure_for_one_order_does_not_block_another_order(): void
    {
        $first = $this->outbox();
        $second = $this->outbox();
        Http::fake(function (Request $request) use ($first) {
            if ($request['event_id'] === $first->event_id) {
                return Http::response([], 500);
            }

            return Http::response([
                'success' => true,
                'event_id' => $request['event_id'],
                'status' => 'processed',
            ]);
        });

        $this->assertSame(0, Artisan::call('integration:send'));
        $output = Artisan::output();
        $this->assertStringContainsString('Processed: 2', $output);
        $this->assertStringContainsString('Sent: 1', $output);
        $this->assertStringContainsString('Pending retry: 1', $output);

        $this->assertSame(IntegrationOutboxStatus::Pending, $first->fresh()->status);
        $this->assertSame(IntegrationOutboxStatus::Sent, $second->fresh()->status);
    }

    public function test_sender_posts_exact_stored_payload_to_configured_url_with_bearer_auth(): void
    {
        $event = $this->outbox();
        $storedPayload = $event->payload;
        $event->deliveryOrder->update([
            'value' => '999.00',
            'location_link' => 'changed-location',
        ]);
        $event->deliveryOrder->customer->update(['name' => 'Changed Customer']);
        Http::fake(['*' => Http::response([
            'success' => true,
            'event_id' => $event->event_id,
            'status' => 'processed',
        ])]);

        $this->sender->send($event);

        Http::assertSent(function (Request $request) use ($storedPayload): bool {
            return $request->url() === 'https://masar.test/base/api/integration/events'
                && $request->hasHeader('Authorization', 'Bearer test-secret-token')
                && $request->hasHeader('Accept', 'application/json')
                && $request->data() == $storedPayload;
        });
    }

    public function test_retry_reuses_stored_event_id_and_counts_each_http_attempt_once(): void
    {
        $event = $this->outbox();
        Http::fakeSequence()
            ->push([], 500)
            ->push([
                'success' => true,
                'event_id' => $event->event_id,
                'status' => 'processed',
            ]);

        $this->sender->send($event);
        $this->sender->send($event->fresh());

        $this->assertSame(2, $event->fresh()->attempts);
        $this->assertSame(IntegrationOutboxStatus::Sent, $event->fresh()->status);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['event_id'] === $event->event_id);
    }

    public function test_command_summary_never_exposes_token_or_payload(): void
    {
        $event = $this->outbox();
        Http::fake(['*' => Http::response([], 401)]);

        $this->artisan('integration:send')
            ->expectsOutput('Processed: 1 | Sent: 0 | Pending retry: 0 | Failed: 1 | Blocked: 0')
            ->doesntExpectOutputToContain('test-secret-token')
            ->doesntExpectOutputToContain($event->event_id)
            ->assertSuccessful();
    }

    private function outbox(
        ?DeliveryOrder $order = null,
        int $version = 1,
        IntegrationEventType $type = IntegrationEventType::OrderUpdated,
        IntegrationOutboxStatus $status = IntegrationOutboxStatus::Pending,
    ): IntegrationOutbox {
        $order ??= $this->order();
        OrderIntegrationState::query()->firstOrCreate(
            ['delivery_order_id' => $order->id],
            ['current_version' => $version],
        );
        $eventId = (string) Str::uuid();
        $payload = [
            'contract_version' => '1.0',
            'source_system' => 'mini_delivery',
            'event_id' => $eventId,
            'event_type' => $type->value,
            'occurred_at' => '2026-08-14T14:00:00Z',
            'order_version' => $version,
            'data' => ['snapshot' => 'stored-'.$eventId],
        ];

        return IntegrationOutbox::create([
            'event_id' => $eventId,
            'delivery_order_id' => $order->id,
            'event_type' => $type,
            'order_version' => $version,
            'payload' => $payload,
            'status' => $status,
        ]);
    }

    private function order(): DeliveryOrder
    {
        $customer = Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('+218', true),
        ]);
        $representative = Representative::create([
            'name' => 'Representative '.uniqid(),
            'is_active' => true,
        ]);

        return DeliveryOrder::create([
            'customer_id' => $customer->id,
            'representative_id' => $representative->id,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::Assigned,
        ]);
    }
}
