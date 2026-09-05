<?php

namespace App\Services\Integration;

/**
 * The receiving half of the envelope contract (CONTRACT §3.21.3, §3.21.6).
 *
 * Deliberately a copy of the rule Masar's `OutboundStatusEnvelope` applies, not a
 * shared library: the two systems are separate deployments with separate release
 * cycles, and a code dependency between them would make one undeployable without
 * the other. What is shared is the *contract*, and the contract test in each
 * suite pins the same literal payload so a drift in either canonicalisation
 * shows up as a failing assertion rather than as a `409` in production.
 *
 * The digest is taken over a recursively key-sorted structure so key order and
 * whitespace in the transport cannot change the identity of an event. That is
 * what makes "the same event_id with the same payload" mean the same thing on
 * both sides of the wire.
 */
final class MasarStatusEnvelope
{
    /** The one event type this channel accepts (§3.21.3). */
    public const EVENT_TYPE = 'order.delivery_status.updated';

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
