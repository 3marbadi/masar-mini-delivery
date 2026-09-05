<?php

namespace App\Services\Integration;

use App\Enums\DeliveryStatus;
use App\Exceptions\MasarIntegrationException;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies one announcement from Masar, exactly once (CONTRACT §3.21.6, §3.21.10).
 *
 * Three responsibilities and no fourth: decide whether this event has been seen,
 * resolve the order it names, and apply the two fields Masar owns. It builds no
 * outbound event, touches no version counter and calls no local update service —
 * §3.21.10's rule is kept here, at the only place an inbound announcement
 * becomes a write.
 *
 * The idempotency identity is (authenticated client, `event_id`), and it is held
 * by a unique index rather than by a read-then-write. Two retries arriving
 * together are decided by the database: one inserts, the other meets the index
 * and converges on the committed row. A check alone would let both pass and
 * apply the change twice.
 *
 * `payload_hash` is what separates a retry from a reuse. The same `event_id`
 * with the same digest replays the saved answer; the same id with a different
 * digest is `409` and changes nothing, because one identity cannot honestly
 * describe two different announcements.
 *
 * `status_version` is the second guard and answers a different question
 * (§3.21.11). `event_id` says whether *this* announcement has arrived; the
 * version says whether it is still the newest. Without it a retry of an older
 * event — one whose first send failed and whose result the courier has since
 * corrected — is a perfectly valid new event and is applied over the correction,
 * quietly reverting this system to a state Masar has withdrawn. The two guards
 * are not interchangeable and neither is redundant.
 *
 * The order of the two checks is load-bearing. Identity first: a legitimate
 * retry of an event that *was* applied must answer `already_processed`, and
 * checking the version first would answer it `ignored_stale` — technically
 * harmless, but it would tell the sender its event never landed when it had.
 *
 * And this guard lives here rather than in the sender, because the sender cannot
 * carry it. Network reordering, a retry that overtakes, a crash between sending
 * and recording, the immediate-send path racing the scheduled one, several
 * workers — none of those are things Masar can order from its own side. The
 * receiver is the last place the question can be answered, so it is answered
 * here, under the order's row lock, inside the transaction that applies the
 * change.
 */
class MasarStatusEventProcessor
{
    public function __construct(private readonly MasarDeliveryStatusWriter $writer) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{body: array<string, mixed>, status: int}
     */
    public function process(MasarIntegrationClient $client, array $payload, string $requestId): array
    {
        $hash = MasarStatusEnvelope::hash($payload);
        $eventId = (string) $payload['event_id'];
        $externalOrderId = (string) $payload['data']['order_id'];
        $incomingVersion = (int) $payload['data']['status_version'];

        // The common case, answered before anything is locked. A committed row
        // is a settled fact, so reading it needs no transaction — which keeps
        // the ordinary retry, by far the most frequent request on this channel,
        // free of one.
        $existing = MasarIntegrationEvent::query()
            ->where('masar_integration_client_id', $client->id)
            ->where('event_id', $eventId)
            ->first();

        if ($existing !== null) {
            return $this->duplicate($existing, $hash, $externalOrderId);
        }

        try {
            return DB::transaction(function () use ($client, $payload, $requestId, $hash, $eventId, $externalOrderId, $incomingVersion): array {
                // Asked again under the transaction, so a competitor that
                // committed between the read above and this point is found
                // rather than collided with.
                $duplicate = MasarIntegrationEvent::query()
                    ->where('masar_integration_client_id', $client->id)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($duplicate !== null) {
                    return $this->duplicate($duplicate, $hash, $externalOrderId);
                }

                // §3.21.4 — Mini Delivery's own id, as Masar was given it.
                // A non-numeric string can never name a row here, and is
                // answered exactly as a missing order: the sender's recourse is
                // the same either way, and §3.21.7 gives it one code.
                $order = ctype_digit($externalOrderId)
                    ? DeliveryOrder::query()->lockForUpdate()->find((int) $externalOrderId)
                    : null;

                if ($order === null) {
                    throw new MasarIntegrationException(
                        'ORDER_NOT_FOUND',
                        'The order named by this event does not exist.',
                        404,
                    );
                }

                // §3.21.11 — read under the row lock taken above, so the
                // comparison and the write that follows it cannot be split by
                // a concurrent event on the same order.
                $appliedVersion = (int) $order->masar_status_version;
                $received = Carbon::now();

                // Equal versions from different events. One of the two is
                // wrong — Masar mints one version per accepted change — and
                // the receiver cannot tell which, so it applies neither and
                // says so plainly instead of picking.
                if ($incomingVersion === $appliedVersion) {
                    throw new MasarIntegrationException(
                        'VERSION_CONFLICT',
                        'A different event has already been applied at this status version.',
                        409,
                    );
                }

                // The late arrival. Not an error and not retryable: something
                // newer from the same source is already in place, which is
                // exactly what this channel is for. The order is left alone and
                // the event is filed as the successful convergence it is.
                if ($incomingVersion < $appliedVersion) {
                    $this->log($client, $payload, $requestId, $hash, $eventId, $externalOrderId,
                        $incomingVersion, $order->getKey(), 'ignored_stale', $received);

                    return [
                        'body' => [
                            'success' => true,
                            'status' => 'ignored_stale',
                            'event_id' => $eventId,
                            'order_id' => $externalOrderId,
                            'applied_status_version' => $appliedVersion,
                        ],
                        'status' => 200,
                    ];
                }

                $status = DeliveryStatus::from((string) $payload['data']['delivery_status']);
                $reason = $payload['data']['status_reason'] ?? null;

                // §3.21.10 — the dedicated writer, and never the local update
                // service. This single call is the loop guard.
                $this->writer->apply($order, $status, $reason === null ? null : (string) $reason, $incomingVersion);

                $this->log($client, $payload, $requestId, $hash, $eventId, $externalOrderId,
                    $incomingVersion, $order->getKey(), 'processed', $received);

                return [
                    'body' => [
                        'success' => true,
                        'status' => 'processed',
                        'event_id' => $eventId,
                        'order_id' => $externalOrderId,
                    ],
                    'status' => 200,
                ];
            }, 3);
        } catch (MasarIntegrationException $exception) {
            // The transaction has rolled back, so the refusal is recorded on its
            // own. §3.21.7 makes `404` a state a person has to repair, and the
            // evidence of it is what they will look for.
            $this->recordRejection($client, $payload, $requestId, $hash, $externalOrderId, $exception);

            throw $exception;
        } catch (QueryException $exception) {
            // The unique index spoke: another copy of this retry committed while
            // this one was working. Its answer is the answer.
            $committed = MasarIntegrationEvent::query()
                ->where('masar_integration_client_id', $client->id)
                ->where('event_id', $eventId)
                ->first();

            if ($committed !== null) {
                return $this->duplicate($committed, $hash, $externalOrderId);
            }

            throw $exception;
        }
    }

    /**
     * The saved answer to an event that has been seen before (§3.21.6).
     *
     * @return array{body: array<string, mixed>, status: int}
     */
    private function duplicate(MasarIntegrationEvent $event, string $hash, string $externalOrderId): array
    {
        if (! hash_equals($event->payload_hash, $hash)) {
            throw new MasarIntegrationException(
                'VERSION_CONFLICT',
                'This event_id was already used with a different payload.',
                409,
            );
        }

        // A previously rejected event replays its rejection rather than being
        // reconsidered: the identity is settled, and reconsidering it would make
        // the answer to one event depend on when it was asked.
        if ($event->result === 'rejected') {
            throw new MasarIntegrationException(
                (string) $event->error_code,
                (string) $event->error_message,
                $event->http_status,
            );
        }

        // An event that was ignored as stale replays as ignored, not as
        // applied. Answering `already_processed` here would tell the sender its
        // announcement had taken effect when the whole point was that a newer
        // one had. The applied version is re-read rather than stored on this
        // row, because it belongs to the order and may have moved on again.
        if ($event->result === 'ignored_stale') {
            return [
                'body' => [
                    'success' => true,
                    'status' => 'ignored_stale',
                    'event_id' => $event->event_id,
                    'order_id' => $event->external_order_id ?? $externalOrderId,
                    'applied_status_version' => (int) DeliveryOrder::query()
                        ->whereKey($event->delivery_order_id)
                        ->value('masar_status_version'),
                ],
                'status' => 200,
            ];
        }

        return [
            'body' => [
                'success' => true,
                'status' => 'already_processed',
                'event_id' => $event->event_id,
                'order_id' => $event->external_order_id ?? $externalOrderId,
            ],
            'status' => 200,
        ];
    }

    /**
     * Record what happened to one event, inside the transaction that did it.
     *
     * §3.21.11 — the event log and the order's state move together or not at
     * all. A version advanced with no state applied, or a state applied with no
     * event recorded, would each leave the two systems disagreeing about what
     * has been said, which is the condition this whole mechanism exists to
     * prevent.
     *
     * @param  array<string, mixed>  $payload
     */
    private function log(
        MasarIntegrationClient $client,
        array $payload,
        string $requestId,
        string $hash,
        string $eventId,
        string $externalOrderId,
        int $statusVersion,
        int $deliveryOrderId,
        string $result,
        Carbon $received,
    ): void {
        MasarIntegrationEvent::create([
            'masar_integration_client_id' => $client->id,
            'request_id' => $requestId,
            'event_id' => $eventId,
            'event_type' => (string) $payload['event_type'],
            'external_order_id' => $externalOrderId,
            'delivery_order_id' => $deliveryOrderId,
            'payload_hash' => $hash,
            'status_version' => $statusVersion,
            'result' => $result,
            'http_status' => 200,
            'received_at' => $received,
            'processed_at' => $received,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordRejection(
        MasarIntegrationClient $client,
        array $payload,
        string $requestId,
        string $hash,
        string $externalOrderId,
        MasarIntegrationException $exception,
    ): void {
        // A conflict is not recorded: the row it collided with already holds the
        // identity, and writing a second one under the same key is exactly what
        // the unique index forbids.
        if ($exception->errorCode === 'VERSION_CONFLICT') {
            return;
        }

        $now = Carbon::now();

        MasarIntegrationEvent::query()->firstOrCreate(
            ['masar_integration_client_id' => $client->id, 'event_id' => (string) $payload['event_id']],
            [
                'request_id' => $requestId,
                'event_type' => (string) $payload['event_type'],
                'external_order_id' => $externalOrderId,
                'delivery_order_id' => null,
                'payload_hash' => $hash,
                // Kept even on a refusal: "which version did they claim" is the
                // first question when the two systems disagree, and the order
                // row only remembers the version that won.
                'status_version' => isset($payload['data']['status_version'])
                    ? (int) $payload['data']['status_version']
                    : null,
                'result' => 'rejected',
                'http_status' => $exception->httpStatus,
                'error_code' => $exception->errorCode,
                'error_message' => $exception->getMessage(),
                'received_at' => $now,
                'processed_at' => $now,
            ],
        );
    }
}
