<?php

namespace App\Services\Integration;

use App\Exceptions\MasarIntegrationException;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use App\Models\MasarOrderNote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stores one note from Masar, exactly once (CONTRACT §13.16).
 *
 * The idempotency identity, the order lookup, the row lock, the transaction and
 * the event log are the status and data processors', because §13.16.5 adopts
 * §3.21.6 and §3.21.9 «حرفاً حرفاً» rather than describing a new model.
 *
 * One thing is genuinely this channel's own, and it is the absence of a version.
 * §13.16.2 refuses a sequence for notes: two notes on one order are two
 * independent facts, not two versions of one, so there is no high-water mark
 * here and **no `ignored_stale`** — the contract says an `ignored_stale` on this
 * channel would be a receiver's bug rather than a contracted outcome. A note
 * whose retry arrives after a later note's is applied normally, because nothing
 * about it is superseded.
 *
 * What replaces the version is the identity, and the precedence is:
 *
 *   1. **already seen** (`event_id`) — a legitimate retry answers as the
 *      original did.
 *   2. **order unknown** — `404`, terminal.
 *   3. **note already stored** (`masar_note_id`) — a second announcement of one
 *      immutable fact. Answered `already_processed` when what is stored matches,
 *      because there is nothing to re-apply; refused
 *      `NOTE_IDENTITY_CONFLICT` when it does not, because Masar would then have
 *      contradicted its own immutability rule and this system cannot pick which
 *      of the two texts is the real one (§13.16.3).
 *   4. store.
 *
 * Nothing here enqueues an outbound event. That is the no-echo barrier and it
 * lives in `MasarOrderNoteWriter`, which is the only writer this processor calls.
 */
class MasarNoteEventProcessor
{
    public function __construct(private readonly MasarOrderNoteWriter $writer) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{body: array<string, mixed>, status: int}
     */
    public function process(MasarIntegrationClient $client, array $payload, string $requestId): array
    {
        $hash = MasarNoteEnvelope::hash($payload);
        $eventId = (string) $payload['event_id'];
        $externalOrderId = (string) $payload['data']['order_id'];
        $noteId = (int) $payload['data']['note_id'];

        // The common case, answered before anything is locked. A committed row
        // is a settled fact, so reading it needs no transaction — which keeps
        // the ordinary retry free of one.
        $existing = $this->seen($client, $eventId);

        if ($existing !== null) {
            return $this->duplicate($existing, $hash, $externalOrderId);
        }

        try {
            return DB::transaction(function () use ($client, $payload, $requestId, $hash, $eventId, $externalOrderId, $noteId): array {
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
                // exactly as a missing order.
                //
                // The order is locked even though no column of it is written:
                // the lock serialises two announcements about one order, so the
                // identity check below and the insert after it cannot be split
                // by a competitor committing in between.
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

                $received = Carbon::now();

                // 3 — §13.16.3. A note is immutable at the source, so a second
                // announcement of one is either a harmless re-announcement under
                // a fresh `event_id` or a contradiction. Both are decided here,
                // by content rather than by recency: there is no "newer" note
                // with the same id.
                $stored = MasarOrderNote::query()
                    ->where('delivery_order_id', $order->getKey())
                    ->where('masar_note_id', $noteId)
                    ->first();

                if ($stored !== null) {
                    return $this->settleExisting($client, $payload, $requestId, $hash, $eventId,
                        $externalOrderId, $noteId, $order->getKey(), $stored, $received);
                }

                $this->writer->apply($order, $payload['data'], $eventId, $received);

                $this->log($client, $payload, $requestId, $hash, $eventId, $externalOrderId,
                    $noteId, $order->getKey(), 'processed', $received);

                return [
                    'body' => [
                        'success' => true,
                        'status' => 'processed',
                        'event_id' => $eventId,
                        'order_id' => $externalOrderId,
                        'note_id' => $noteId,
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
            // A unique index spoke — either the event ledger's or the note
            // table's. Another copy of this retry committed while this one was
            // working, and its answer is the answer.
            $committed = $this->seen($client, $eventId);

            if ($committed !== null) {
                return $this->duplicate($committed, $hash, $externalOrderId);
            }

            throw $exception;
        }
    }

    /**
     * A note we already hold, announced again (§13.16.3).
     *
     * Matching content is answered as the settled fact it is. A mismatch is
     * refused rather than overwritten: the stored row is what a courier actually
     * wrote, this system has no basis for preferring the newer bytes, and
     * silently replacing an immutable record would be worse than saying so.
     *
     * @return array{body: array<string, mixed>, status: int}
     */
    private function settleExisting(
        MasarIntegrationClient $client,
        array $payload,
        string $requestId,
        string $hash,
        string $eventId,
        string $externalOrderId,
        int $noteId,
        int $deliveryOrderId,
        MasarOrderNote $stored,
        Carbon $received,
    ): array {
        if ($stored->content !== (string) $payload['data']['content']
            || $stored->masar_representative_id !== (int) $payload['data']['representative_id']) {
            throw new MasarIntegrationException(
                'NOTE_IDENTITY_CONFLICT',
                "Note {$noteId} is already stored for this order with different content.",
                409,
            );
        }

        $this->log($client, $payload, $requestId, $hash, $eventId, $externalOrderId,
            $noteId, $deliveryOrderId, 'already_processed', $received);

        return [
            'body' => [
                'success' => true,
                'status' => 'already_processed',
                'event_id' => $eventId,
                'order_id' => $externalOrderId,
                'note_id' => $noteId,
            ],
            'status' => 200,
        ];
    }

    private function seen(MasarIntegrationClient $client, string $eventId): ?MasarIntegrationEvent
    {
        return MasarIntegrationEvent::query()
            ->where('masar_integration_client_id', $client->id)
            ->where('event_id', $eventId)
            ->first();
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

        // There is no `ignored_stale` branch here, and there must not be
        // (§13.16.2): this channel has no sequence, so no event of it can ever
        // have been filed as stale.
        return [
            'body' => [
                'success' => true,
                'status' => 'already_processed',
                'event_id' => $event->event_id,
                'order_id' => $event->external_order_id ?? $externalOrderId,
                'note_id' => $event->masar_note_id,
            ],
            'status' => 200,
        ];
    }

    /**
     * Record what happened to one event, inside the transaction that did it.
     *
     * The event log and the stored note move together or not at all: a note
     * stored with no event recorded would be re-stored on the next retry and
     * refused by the unique index, and an event recorded with no note would make
     * a genuine announcement unrepeatable.
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
        int $noteId,
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
            // The note's identity, and none of the three version columns: this
            // event said nothing about execution state, data or location, and a
            // zero in any of them would claim it had.
            'masar_note_id' => $noteId,
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
        // A conflict on the identity we key by is not recorded: the row it
        // collided with already holds that identity, and writing a second one
        // under the same key is exactly what the unique index forbids.
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
                // Kept even on a refusal: «which note did they claim» is the
                // whole question a `NOTE_IDENTITY_CONFLICT` raises.
                'masar_note_id' => isset($payload['data']['note_id'])
                    ? (int) $payload['data']['note_id']
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
