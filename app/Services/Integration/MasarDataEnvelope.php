<?php

namespace App\Services\Integration;

/**
 * The receiving half of the data-correction contract (CONTRACT §13.8.1, §13.8.3).
 *
 * A second envelope beside `MasarStatusEnvelope`, not a widening of it. §13.8.1
 * forbids carrying corrections in the status event and fixes each `data` block
 * separately, so the two are described here and there and neither can drift into
 * the other. The digest rule is shared in wording and duplicated in code, for
 * the reason the status envelope already gives: two deployments with two release
 * cycles must not depend on one library.
 *
 * The vocabulary is closed and stated once. A path outside it is refused rather
 * than ignored — a correction accepted with one of its fields quietly dropped
 * would be answered `processed` about something that never landed, which is the
 * one outcome this channel must never produce.
 */
final class MasarDataEnvelope
{
    /** The one data event type of this contract version (§13.8.1). */
    public const EVENT_TYPE = 'order.data.updated';

    public const CONTRACT_VERSION = '1.0';

    /**
     * §13.8.3 — the five paths a courier may correct, mapped to where each one
     * lands here.
     *
     * The three recipient paths land on the order's own snapshot and never on
     * `customers`: that is D7, and the reason is set out in the migration that
     * added the columns. `order.amount` is this system's `value`, which is what
     * the outbound snapshot already calls `amount`. `order.delivery_payer` has a
     * column of its own added for this channel, because Mini Delivery has no
     * fee-bearer concept and accepting the path without storing it would be a
     * lie told with a 200.
     */
    public const PATHS = [
        'order.recipient_name' => 'recipient_name',
        'order.recipient_phone' => 'recipient_phone',
        'order.recipient_alternate_phone' => 'recipient_alternate_phone',
        'order.amount' => 'value',
        'order.delivery_payer' => 'delivery_payer',
    ];

    /** @return list<string> */
    public static function paths(): array
    {
        return array_keys(self::PATHS);
    }

    /** @param array<string, mixed> $envelope */
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
