<?php

namespace App\Services\Integration;

/**
 * The receiving half of the completed-location contract (CONTRACT §13.17.1).
 *
 * The fourth envelope, independent of the three beside it. A location is not a
 * state of execution, not a correction to one of five whitelisted fields, and
 * not an append-only remark — §13.17.1 gives it its own `data` block, and this
 * is where that block's shape is known on this side.
 *
 * The coordinates arrive as strings and are kept as strings all the way to the
 * column (§13.17.1). Both ends store DECIMAL(10,7), and Masar normalises to
 * seven places before comparing, precisely because 32.1 and 32.1000000 are one
 * point. Casting to float here to "tidy" the value would reintroduce exactly the
 * representation error the string form exists to avoid, in a coordinate a
 * courier drives to.
 */
final class MasarLocationEnvelope
{
    /** The one location event type of this contract version (§13.17.1). */
    public const EVENT_TYPE = 'order.location.updated';

    public const CONTRACT_VERSION = '1.0';

    /** The stored scale, and the scale on the wire (§13.17.1). */
    public const COORDINATE_SCALE = 7;

    /** @param array<string, mixed> $envelope */
    public static function hash(array $envelope): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($envelope),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** Key-sorted depth-first, lists left in their own order. */
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
