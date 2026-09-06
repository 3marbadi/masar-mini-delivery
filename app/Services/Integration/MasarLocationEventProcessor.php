<?php

namespace App\Services\Integration;

use App\Exceptions\MasarIntegrationException;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies one completed location from Masar, exactly once (CONTRACT §13.17).
 *
 * The idempotency identity, the order lookup, the row lock, the transaction and
 * the event log are the data processor's, because §13.17.6 adopts §13.16.5 which
 * adopts §3.21.6 and §3.21.9 «حرفاً حرفاً». The precedence is the data
 * channel's too, and each step exists to keep the one before it honest:
 *
 *   1. **already seen** — a legitimate retry must answer as the original did,
 *      whatever any number now says.
 *   2. **stale within Masar's own stream** — a lower `location_version` than the
 *      one already applied from Masar. Not an error: a newer announcement from
 *      the same sender is in place, which is what this channel is for.
 *   3. **version conflict** — two different events claiming one Masar version.
 *      Masar mints one per meaningful save, so one of the two is wrong and the
 *      receiver cannot tell which. It applies neither and says so.
 *   4. **last business write wins** — the cross-system comparison (§13.17.5).
 *   5. apply.
 *
 * Steps 2 and 4 answer different questions and both are needed. Step 2 asks "is
 * this the newest thing *Masar* has said?" and compares two of Masar's own
 * version numbers, which is legitimate because they come from one sequence. Step
 * 4 asks "is this the newest thing that *happened*?" and compares instants,
 * because that is the only thing two independent systems can compare. Step 2
 * never rejects a genuinely newer change — it only ever measures Masar against
 * itself.
 *
 * **The arbitration is last business write wins, not last arrival** (D13). A
 * delayed announcement carrying an older change is answered `ignored_stale` and
 * changes nothing, no matter how many times it is retried, because the stamp it
 * carries was frozen when the change happened and a retry does not refresh it.
 * `base_order_version` is gone from this channel entirely, and with it
 * `LOCATION_BASE_VERSION_CONFLICT`: that rule could refuse a genuinely newer
 * location merely because *our* unrelated sequence had advanced, which is
 * conflict detection rather than last-write-wins. The data channel keeps its own
 * base-version rule untouched (D3, §13.8.4) — a different question with a
 * different answer.
 *
 * **Gaps are applied, not refused** (§13.17.3). The payload carries the order's
 * whole current location rather than a difference, so an event at version 5
 * arriving on a high-water mark of 2 is complete in itself, and waiting for the
 * missing ones would strand a correct location for the sake of events that may
 * never arrive. That is deliberately unlike the inbound direction (§3.7), where
 * a gap is a `409` — there the sender owns a sequence it guarantees contiguous,
 * and here the sender's retries may reorder.
 *
 * Nothing here enqueues an outbound event. On this channel that is the whole
 * barrier against an endless loop, and it lives in `MasarLocationWriter`, which
 * is the only writer this processor calls.
 */
class MasarLocationEventProcessor
{
    public function __construct(private readonly MasarLocationWriter $writer) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{body: array<string, mixed>, status: int}
     */
    public function process(MasarIntegrationClient $client, array $payload, string $requestId): array
    {
        $hash = MasarLocationEnvelope::hash($payload);
        $eventId = (string) $payload['event_id'];
        $externalOrderId = (string) $payload['data']['order_id'];
        $incomingVersion = (int) $payload['data']['location_version'];

        $existing = $this->seen($client, $eventId);

        if ($existing !== null) {
            return $this->duplicate($existing, $hash, $externalOrderId);
        }

        try {
            return DB::transaction(function () use ($client, $payload, $requestId, $hash, $eventId, $externalOrderId, $incomingVersion): array {
                $duplicate = MasarIntegrationEvent::query()
                    ->where('masar_integration_client_id', $client->id)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($duplicate !== null) {
                    return $this->duplicate($duplicate, $hash, $externalOrderId);
                }

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

                $appliedVersion = (int) $order->masar_location_version;
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
                        'A different event has already been applied at this location version.',
                        409,
                    );
                }

                // 4 — §13.17.5, D13. Read under the row lock taken above, so the
                // comparison and the write that follows cannot be split by a
                // local edit committing in between. This is the whole of the
                // cross-system arbitration: an announcement describing an older
                // change than the one this order already holds is converged
                // away, not applied and not refused.
                if (! $this->winsLastWrite($payload, $order, $eventId)) {
                    $this->log($client, $payload, $requestId, $hash, $eventId, $externalOrderId,
                        $incomingVersion, $order->getKey(), 'ignored_stale', $received);

                    return $this->staleBody($eventId, $externalOrderId, (int) $order->masar_location_version);
                }

                $applied = $this->writer->apply($order, $payload['data'], $incomingVersion, $eventId);

                $this->log($client, $payload, $requestId, $hash, $eventId, $externalOrderId,
                    $incomingVersion, $order->getKey(), 'processed', $received);

                return [
                    'body' => [
                        'success' => true,
                        'status' => 'processed',
                        'event_id' => $eventId,
                        'order_id' => $externalOrderId,
                        // What actually landed, so Masar can see the coordinates
                        // were applied and not merely accepted.
                        'latitude' => $applied['latitude'],
                        'longitude' => $applied['longitude'],
                        'applied_location_version' => $incomingVersion,
                    ],
                    'status' => 200,
                ];
            }, 3);
        } catch (MasarIntegrationException $exception) {
            $this->recordRejection($client, $payload, $requestId, $hash, $externalOrderId, $exception);

            throw $exception;
        } catch (QueryException $exception) {
            $committed = $this->seen($client, $eventId);

            if ($committed !== null) {
                return $this->duplicate($committed, $hash, $externalOrderId);
            }

            throw $exception;
        }
    }

    /**
     * §13.17.5, D13 — whether this announcement is the later business change.
     *
     * The location is shared-write: it arrives here from Masar and it is written
     * here by this system's own edit path, and neither side owns it. The owner's
     * rule is that the later *change* wins — not the later arrival — so the
     * comparison is between the instant Masar recorded when its change committed
     * and the instant stored on this order for whichever change it currently
     * holds.
     *
     * A retry cannot win by being repeated: the stamp it carries was frozen when
     * the change happened, so an announcement that lost once loses identically
     * every time, however long the network delays it.
     *
     * A null accepted stamp means no location change has ever been recorded for
     * this order, so the incoming one wins against nothing — the right answer,
     * and not a special case.
     *
     * @param  array<string, mixed>  $payload
     */
    private function winsLastWrite(array $payload, DeliveryOrder $order, string $eventId): bool
    {
        $incoming = new LocationChangeStamp(
            Carbon::parse((string) $payload['data']['location_changed_at'])->utc(),
            (string) $payload['data']['location_change_source'],
            $eventId,
        );

        $accepted = LocationChangeStamp::accepted(
            $order->location_changed_at?->toDateTimeString(),
            $order->location_change_source,
            $order->location_change_event_id,
        );

        // Same origin as the change we already hold: its own sequence has
        // already answered this, in step 2 above, and answered it better
        // (§13.17.3). `location_version` is monotonic within Masar's stream, so
        // reaching here at all means this announcement is the later of the two —
        // whereas the stamp has only second resolution, and two saves in one
        // second would otherwise fall through to a tie-break between two random
        // identities and settle at random.
        //
        // The stamp exists to compare changes that *no* single sequence orders,
        // which is exactly the cross-origin case below.
        if ($accepted !== null && $accepted->source === $incoming->source) {
            return true;
        }

        return LocationChangeStamp::wins($incoming, $accepted);
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
                'applied_location_version' => $appliedVersion,
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
        // the answer to one event depend on when it was asked.
        if ($event->result === 'rejected') {
            throw new MasarIntegrationException(
                (string) $event->error_code,
                (string) $event->error_message,
                $event->http_status,
            );
        }

        // An event ignored as stale replays as ignored, not as applied.
        // Answering `already_processed` here would tell the sender its location
        // had taken effect when the whole point was that a newer one had.
        if ($event->result === 'ignored_stale') {
            return $this->staleBody(
                $event->event_id,
                $event->external_order_id ?? $externalOrderId,
                (int) DeliveryOrder::query()->whereKey($event->delivery_order_id)->value('masar_location_version'),
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
     * §13.17.2 — the event log and the order's state move together or not at
     * all. A version advanced with no coordinates applied, or coordinates
     * applied with no event recorded, would each leave the two systems
     * disagreeing about what has been said.
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
        int $locationVersion,
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
            // This channel's own number. The status and data versions stay
            // null: this event said nothing about either, and a zero would claim
            // it had. `base_order_version` stays null too — this channel stopped
            // carrying one with D13 (§13.17.5).
            'location_version' => $locationVersion,
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
                // Kept even on a refusal: «which version did they claim» is the
                // first question anyone investigating a conflict asks, and it
                // survives nowhere else once the transaction has rolled back.
                'location_version' => isset($payload['data']['location_version'])
                    ? (int) $payload['data']['location_version']
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
