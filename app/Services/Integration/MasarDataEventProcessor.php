<?php

namespace App\Services\Integration;

use App\Exceptions\MasarIntegrationException;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use App\Models\OrderIntegrationState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies one data correction from Masar, exactly once (CONTRACT §13.8, §13.14).
 *
 * The data channel's counterpart to `MasarStatusEventProcessor`, and deliberately
 * the same machine: the idempotency identity, the order lookup, the row lock, the
 * transaction and the event log are that processor's, because §13.8.6 adopts
 * §3.21.6 and §3.21.9 «حرفاً حرفاً» rather than describing a new model. Where the
 * two differ they would be wrong.
 *
 * Two things are genuinely this channel's own.
 *
 * The first is **where the values land**. They land on the order's own recipient
 * snapshot and never on `customers` (§13.14, D7). Masar addressed one order: one
 * `order_id`, one `data_version`, one `base_order_version` that is this order's.
 * Writing the shared profile would rewrite what every sibling order of that
 * person displays, silently, with the announcement claiming to be about one of
 * them. That is the failure D7 was decided to end.
 *
 * The second is **`base_order_version`**, which the status channel has no
 * equivalent of. `data_version` orders Masar's corrections against each other;
 * `base_order_version` says which of *our* `order.updated` versions the
 * correction was built on (§13.8.4). The two answer different questions and
 * neither substitutes for the other: a correction can be the newest Masar has
 * sent and still be built on a stale picture of this order, because our own
 * outbound sequence moved after Masar read it and before the courier acted. A
 * receiver that checked only the version would apply a name the courier chose
 * while looking at a number this system has since replaced.
 *
 * The precedence is fixed and each step exists to keep the one before it
 * honest:
 *
 *   1. **already seen** — a legitimate retry must answer as the original did,
 *      whatever any version now says.
 *   2. **stale** — an older correction than the one applied. Not an error: a
 *      newer one from the same source is in place, which is what this channel is
 *      for.
 *   3. **version conflict** — two different events claiming one version. Masar
 *      mints one version per accepted correction, so one of the two is wrong and
 *      the receiver cannot tell which. It applies neither and says so.
 *   4. **base conflict** — newer, but built on a picture of this order that has
 *      moved. Refused rather than applied, and refused *after* the staleness
 *      checks so a stale retry is still answered as the ordinary convergence it
 *      is rather than as a fault.
 *   5. apply.
 *
 * Nothing here enqueues an outbound event. That is the no-echo barrier and it
 * lives in `MasarRecipientDataWriter`, which is the only writer this processor
 * calls.
 */
class MasarDataEventProcessor
{
    public function __construct(private readonly MasarRecipientDataWriter $writer) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{body: array<string, mixed>, status: int}
     */
    public function process(MasarIntegrationClient $client, array $payload, string $requestId): array
    {
        $hash = MasarDataEnvelope::hash($payload);
        $eventId = (string) $payload['event_id'];
        $externalOrderId = (string) $payload['data']['order_id'];
        $incomingVersion = (int) $payload['data']['data_version'];

        // The common case, answered before anything is locked. A committed row
        // is a settled fact, so reading it needs no transaction — which keeps
        // the ordinary retry, by far the most frequent request on this channel,
        // free of one.
        $existing = $this->seen($client, $eventId);

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

                // §3.21.4 — Mini Delivery's own id, as Masar was given it. A
                // non-numeric string can never name a row here and is answered
                // exactly as a missing order: the sender's recourse is the same
                // either way.
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

                $appliedVersion = (int) $order->masar_data_version;
                $received = Carbon::now();

                // 2 — the late arrival. The order is left alone and the event is
                // filed as the successful convergence it is.
                if ($incomingVersion < $appliedVersion) {
                    $this->log($client, $payload, $requestId, $hash, $eventId, $externalOrderId,
                        $incomingVersion, $order->getKey(), 'ignored_stale', $received);

                    return $this->staleBody($eventId, $externalOrderId, $appliedVersion);
                }

                // 3 — equal versions from different events.
                if ($incomingVersion === $appliedVersion) {
                    throw new MasarIntegrationException(
                        'VERSION_CONFLICT',
                        'A different event has already been applied at this data version.',
                        409,
                    );
                }

                // 4 — §13.8.4. Read under the row lock taken above, so the
                // comparison and the write that follows cannot be split by a
                // local edit committing in between.
                $this->assertBaseVersion($payload, $order);

                $applied = $this->writer->apply(
                    $order,
                    $this->changedFields($payload),
                    $incomingVersion,
                );

                $this->log($client, $payload, $requestId, $hash, $eventId, $externalOrderId,
                    $incomingVersion, $order->getKey(), 'processed', $received);

                return [
                    'body' => [
                        'success' => true,
                        'status' => 'processed',
                        'event_id' => $eventId,
                        'order_id' => $externalOrderId,
                        // What actually landed, so Masar can see that every path
                        // it sent was applied and not merely accepted.
                        'applied_fields' => array_keys($this->changedFields($payload)),
                        'applied_data_version' => $incomingVersion,
                    ],
                    'status' => 200,
                ];
            }, 3);
        } catch (MasarIntegrationException $exception) {
            // The transaction has rolled back, so the refusal is recorded on its
            // own. §3.21.7 makes these states something a person has to repair,
            // and the evidence of them is what they will look for.
            $this->recordRejection($client, $payload, $requestId, $hash, $externalOrderId, $exception);

            throw $exception;
        } catch (QueryException $exception) {
            // The unique index spoke: another copy of this retry committed while
            // this one was working. Its answer is the answer.
            $committed = $this->seen($client, $eventId);

            if ($committed !== null) {
                return $this->duplicate($committed, $hash, $externalOrderId);
            }

            throw $exception;
        }
    }

    /**
     * §13.8.4 — the precondition, checked against our own outbound sequence.
     *
     * `null` is not a failure. Masar sends it for an order it holds without an
     * integration mapping, which means it has no picture of ours to have built
     * on — there is nothing to disagree with, and refusing would strand a
     * correction that is perfectly applicable.
     *
     * The comparison is against `order_integration_states.current_version`,
     * which is the version of the last `order.updated` this system emitted for
     * this order — the exact number Masar stores as `last_applied_order_version`
     * and echoes back here. An order that has never been announced has no state
     * row and counts as version 0.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertBaseVersion(array $payload, DeliveryOrder $order): void
    {
        $base = $payload['data']['base_order_version'] ?? null;

        if ($base === null) {
            return;
        }

        $current = (int) (OrderIntegrationState::query()
            ->where('delivery_order_id', $order->getKey())
            ->value('current_version') ?? 0);

        if ((int) $base === $current) {
            return;
        }

        throw new MasarIntegrationException(
            'DATA_BASE_VERSION_CONFLICT',
            "This correction was built on order version {$base}, and this order is now at version {$current}.",
            409,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string|null>
     */
    private function changedFields(array $payload): array
    {
        /** @var array<string, string|null> $fields */
        $fields = $payload['data']['changed_fields'];

        return $fields;
    }

    private function seen(MasarIntegrationClient $client, string $eventId): ?MasarIntegrationEvent
    {
        return MasarIntegrationEvent::query()
            ->where('masar_integration_client_id', $client->id)
            ->where('event_id', $eventId)
            ->first();
    }

    /** @return array{body: array<string, mixed>, status: int} */
    private function staleBody(string $eventId, string $externalOrderId, int $appliedVersion): array
    {
        return [
            'body' => [
                'success' => true,
                'status' => 'ignored_stale',
                'event_id' => $eventId,
                'order_id' => $externalOrderId,
                'applied_data_version' => $appliedVersion,
            ],
            'status' => 200,
        ];
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
        // the answer to one event depend on when it was asked. That matters more
        // here than on the status channel, because the commonest rejection is
        // `DATA_BASE_VERSION_CONFLICT` — a state that *does* change over time, so
        // a reconsidered retry could apply a correction the first attempt
        // correctly refused.
        if ($event->result === 'rejected') {
            throw new MasarIntegrationException(
                (string) $event->error_code,
                (string) $event->error_message,
                $event->http_status,
            );
        }

        // An event that was ignored as stale replays as ignored, not as applied.
        // Answering `already_processed` here would tell the sender its
        // correction had taken effect when the whole point was that a newer one
        // had. The applied version is re-read rather than stored on this row,
        // because it belongs to the order and may have moved on again.
        if ($event->result === 'ignored_stale') {
            return $this->staleBody(
                $event->event_id,
                $event->external_order_id ?? $externalOrderId,
                (int) DeliveryOrder::query()->whereKey($event->delivery_order_id)->value('masar_data_version'),
            );
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
     * §13.8.2 — the event log and the order's state move together or not at all.
     * A version advanced with no values applied, or values applied with no event
     * recorded, would each leave the two systems disagreeing about what has been
     * said.
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
        int $dataVersion,
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
            // The data channel's own two numbers. `status_version` is left null:
            // this event said nothing about execution state, and a zero there
            // would claim it had.
            'data_version' => $dataVersion,
            'base_order_version' => $payload['data']['base_order_version'] ?? null,
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
                // Kept even on a refusal: "which version did they claim, and
                // what did they think ours was" is the whole question a
                // `DATA_BASE_VERSION_CONFLICT` raises, and neither number
                // survives anywhere else.
                'data_version' => isset($payload['data']['data_version'])
                    ? (int) $payload['data']['data_version']
                    : null,
                'base_order_version' => $payload['data']['base_order_version'] ?? null,
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
