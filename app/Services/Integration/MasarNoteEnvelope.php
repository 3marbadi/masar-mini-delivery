<?php

namespace App\Services\Integration;

/**
 * The receiving half of the note contract (CONTRACT §13.16.1).
 *
 * A third envelope beside the status and data ones, not a widening of either.
 * §13.16.1 fixes each `data` block separately, so the three are described here
 * and there and none can drift into another. The digest rule is shared in
 * wording and duplicated in code, for the reason the older envelopes give: two
 * deployments with two release cycles must not depend on one library.
 *
 * This envelope carries no version, and that absence is the contract rather than
 * an omission (§13.16.2). Notes are independent facts on an append-only log, so
 * there is nothing for a sequence to order and no meaning to give
 * `ignored_stale` on this channel. The identity is `note_id`, which Masar mints
 * once and never changes — and it is compared for uniqueness, never for
 * recency.
 */
final class MasarNoteEnvelope
{
    /** The one note event type of this contract version (§13.16.1). */
    public const EVENT_TYPE = 'order.note.created';

    public const CONTRACT_VERSION = '1.0';

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
