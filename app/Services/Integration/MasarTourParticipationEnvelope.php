<?php

namespace App\Services\Integration;

/**
 * The receiving half of the participation channel's envelope
 * (CONTRACT §13.29 — D31, draft; adopting §3.21.3 and §3.21.6).
 *
 * Deliberately a copy of the canonicalisation the other four channels apply,
 * not a shared base class: the two systems are separate deployments with
 * separate release cycles, and what is shared between them is the *contract*,
 * not a library. The contract test in each suite pins the same literal payload,
 * so a drift in either end's digest shows up as a failing assertion rather than
 * as a `409` in production.
 *
 * The digest is taken over a recursively key-sorted structure, so key order and
 * whitespace in the transport cannot change the identity of an event. That is
 * what makes "the same event_id with the same payload" mean the same thing on
 * both sides of the wire.
 *
 * `CONTRACT_VERSION` stays `1.0` and that is a decision with a precedent rather
 * than an oversight: §13.12 says `contract_version` «لا يتبدّل برفع إصدار هذه
 * الوثيقة — يتبدّل لعلّةٍ سلكيّة وحدها», and v5.5 added two whole channels
 * without moving it. A fifth channel is an addition to the catalogue of event
 * types, not a change to the shape of the envelope every channel shares.
 */
final class MasarTourParticipationEnvelope
{
    /** The one event type this channel accepts. */
    public const EVENT_TYPE = 'order.tour.participation.updated';

    public const CONTRACT_VERSION = '1.0';

    /**
     * @param  array<string, mixed>  $envelope
     */
    public static function hash(array $envelope): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($envelope),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn ($item) => self::canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
