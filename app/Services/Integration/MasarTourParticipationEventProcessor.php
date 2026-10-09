<?php

namespace App\Services\Integration;

use App\Enums\TourParticipation;
use App\Exceptions\MasarIntegrationException;
use App\Models\DeliveryOrder;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationEvent;
use App\Models\Representative;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies one participation statement from Masar, exactly once
 * (CONTRACT §13.29 — D31, draft; adopting §3.21.6, §3.21.10 and §3.21.11).
 *
 * The idempotency identity, the order lookup, the row lock, the transaction and
 * the event log are the status channel's, mirrored rather than reinvented: the
 * draft channel adopts §3.21.6 and §3.21.11 «حرفاً حرفاً», so a divergence here
 * would be a bug in this file rather than a design of its own.
 *
 * The precedence of the four checks is load-bearing, and each step keeps the one
 * before it honest:
 *
 *   1. **already seen** — a legitimate retry must answer as the original did,
 *      whatever any version number now says. Checking the version first would
 *      answer a successfully applied retry `ignored_stale`, telling the sender
 *      its statement never landed when it had.
 *   2. **version conflict** — two different events claiming one participation
 *      version. Masar mints one per transition, so one of the two is wrong and
 *      the receiver cannot tell which. It applies neither and says so plainly
 *      rather than picking.
 *   3. **stale** — a lower version than the one already applied. Not an error
 *      and not retryable: something newer from the same sender is in place,
 *      which is exactly what this channel is for.
 *   4. **apply.**
 *
 * **Why one sequence covers all three states.** `scheduled`, `active` and
 * `ended` travel on one event type and share `participation_version`, so an
 * `ended` belonging to a closed tour and an `active` belonging to a newer one
 * are comparable: the stale guard resolves them. Two event types with two
 * sequences would have left them incomparable, and the late `ended` would have
 * silently undone the newer start — the failure §3.21.11 was written to end,
 * reappearing on a new channel.
 *
 * **What this processor does not decide.** Whether a participation statement is
 * still *operative* is not asked here. An `active` built on a superseded
 * assignment is applied and answered `processed`, because Masar did say it and
 * the log must show that; it is the projection that declines to act on it, by
 * comparing `base_order_version` against this company's own assignment fence.
 * Refusing the write instead would lose the statement and leave nothing to
 * explain why an operator saw no change.
 *
 * Nothing here enqueues an outbound event, raises `order_version` or touches a
 * single column of the status, data or location channels. §3.21.10's loop guard
 * is kept at the only place an inbound statement becomes a write, and it is kept
 * by ownership rather than by idempotency — no number can break a cycle in which
 * every lap is a genuinely new event.
 */
class MasarTourParticipationEventProcessor
{
    public function __construct(private readonly MasarTourParticipationWriter $writer) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{body: array<string, mixed>, status: int}
     */
    public function process(MasarIntegrationClient $client, array $payload, string $requestId): array
    {
        $hash = MasarTourParticipationEnvelope::hash($payload);
        $eventId = (string) $payload['event_id'];
        $externalOrderId = (string) $payload['data']['order_id'];
        $incomingVersion = (int) $payload['data']['participation_version'];

        // The common case, answered before anything is locked. A committed row
        // is a settled fact, so reading it needs no transaction — which keeps
        // the ordinary retry, by far the most frequent request on any of these
        // channels, free of one.
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

                // §3.21.4 — Mini Delivery's own id, as Masar was given it:
                // `integration_uid`, the durable identity, never `id`. Matched
                // against the same column the outbound payload sends, so the
                // value makes the round trip unchanged. A string naming no row
                // is answered exactly as a missing order, because the sender's
                // recourse is the same either way.
                $order = DeliveryOrder::query()
                    ->where('integration_uid', $externalOrderId)
                    ->lockForUpdate()
                    ->first();

                if ($order === null) {
                    throw new MasarIntegrationException(
                        'ORDER_NOT_FOUND',
                        'The order named by this event does not exist.',
                        404,
                    );
                }

                // Read under the row lock taken above, so the comparison and the
                // write that follows it cannot be split by a concurrent event on
                // the same order. Two requests racing here serialise on this
                // row: the loser re-reads the winner's version and is answered
                // `ignored_stale` or `409`, never applied over it.
                $appliedVersion = (int) $order->masar_participation_version;
                $received = Carbon::now();

                if ($incomingVersion === $appliedVersion) {
                    throw new MasarIntegrationException(
                        'VERSION_CONFLICT',
                        'A different event has already been applied at this participation version.',
                        409,
                    );
                }

                if ($incomingVersion < $appliedVersion) {
                    $this->log($client, $payload, $requestId, $hash, $eventId, $externalOrderId,
                        $incomingVersion, $order->getKey(), 'ignored_stale', $received);

                    return $this->staleBody($eventId, $externalOrderId, $appliedVersion);
                }

                $participation = TourParticipation::from((string) $payload['data']['participation']);

                // Resolved before the call rather than inside the argument
                // list, so the order of evaluation is not load-bearing.
                $courierUid = $this->optionalString($payload['data']['external_courier_id'] ?? null);

                $this->writer->apply(
                    $order,
                    $participation,
                    $incomingVersion,
                    $this->optionalInteger($payload['data']['base_order_version'] ?? null),
                    // The uid as it arrived, kept whether or not it resolves,
                    // and the resolution beside it.
                    //
                    // **Accepted, recorded, and not displayed** — in that order,
                    // and each part is a decision. A uid naming no
                    // representative stores null rather than guessing, because
                    // the projection will not show an order as being delivered
                    // by a courier it cannot name.
                    //
                    // But it is not a refusal, and refusing would be the worse
                    // error: Masar classifies every 4xx as terminal and never
                    // retries it, so a `422` here would discard a true
                    // participation statement for good over a *representative*
                    // mapping this channel does not own — and the order would
                    // then never read correctly even after the mapping was
                    // repaired, until some wholly new tour. Keeping the uid
                    // instead leaves the repair a local re-resolution, with no
                    // new event and nothing asked of idempotency.
                    $courierUid,
                    $this->resolveRepresentativeId($courierUid),
                    $this->optionalString($payload['data']['tour_reference'] ?? null),
                    $this->optionalDate($payload['data']['tour_departure_at'] ?? null),
                    // `occurred_at` is this channel's change instant. D31 puts
                    // no separate `participation_changed_at` in `data` because
                    // the envelope already carries it, on the same reasoning
                    // §13.16.1 gives for the note channel's `created_at`.
                    Carbon::parse((string) $payload['occurred_at'])->utc(),
                );

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
            $committed = $this->seen($client, $eventId);

            if ($committed !== null) {
                return $this->duplicate($committed, $hash, $externalOrderId);
            }

            throw $exception;
        }
    }

    private function seen(MasarIntegrationClient $client, string $eventId): ?MasarIntegrationEvent
    {
        return MasarIntegrationEvent::query()
            ->where('masar_integration_client_id', $client->id)
            ->where('event_id', $eventId)
            ->first();
    }

    /**
     * The local representative the courier uid names, or null.
     *
     * `integration_uid` and never the primary key, for the reason that column
     * was created: an integer is unique inside one incarnation of this database
     * and nowhere else, so a rebuilt database would resolve a new courier to the
     * old one's row — the success branch returning a confident wrong answer.
     */
    private function resolveRepresentativeId(?string $courierUid): ?int
    {
        if ($courierUid === null || $courierUid === '') {
            return null;
        }

        $id = Representative::query()->where('integration_uid', $courierUid)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function optionalInteger(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function optionalString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function optionalDate(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse((string) $value)->utc();
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
                'applied_participation_version' => $appliedVersion,
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
        // Answering `already_processed` here would tell the sender its statement
        // had taken effect when the whole point was that a newer one had. The
        // applied version is re-read rather than stored on this row, because it
        // belongs to the order and may have moved on again.
        if ($event->result === 'ignored_stale') {
            return $this->staleBody(
                $event->event_id,
                $event->external_order_id ?? $externalOrderId,
                (int) DeliveryOrder::query()
                    ->whereKey($event->delivery_order_id)
                    ->value('masar_participation_version'),
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
     * The event log and the order's state move together or not at all. A version
     * advanced with no participation applied, or participation applied with no
     * event recorded, would each leave the two systems disagreeing about what
     * has been said — the condition this whole mechanism exists to prevent.
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
        int $participationVersion,
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
            // This channel's own number. The status, data and location versions
            // and the note id stay null: this event said nothing about any of
            // them, and a zero would claim it had (§13.12).
            'participation_version' => $participationVersion,
            // Reused rather than duplicated: the data channel's column holds a
            // value from the same sequence with the same meaning (§13.8.4).
            'base_order_version' => isset($payload['data']['base_order_version'])
                ? $this->optionalInteger($payload['data']['base_order_version'])
                : null,
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
                'participation_version' => isset($payload['data']['participation_version'])
                    ? (int) $payload['data']['participation_version']
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
