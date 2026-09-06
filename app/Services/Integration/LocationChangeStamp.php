<?php

namespace App\Services\Integration;

use Illuminate\Support\Carbon;

/**
 * Which of two location changes actually happened later (CONTRACT §13.17.5,
 * D13).
 *
 * The location is shared-write: Masar's courier writes it from the field, and
 * this system writes it from its own edit path. Neither owns it. The
 * owner's decision is that **the last real business write wins** — and the word
 * *business* is the whole of it. Not the last HTTP request to arrive, not the
 * last retry: a delayed event carrying an older change must never overwrite a
 * newer one, however many times it is re-sent.
 *
 * That rules out every counter in this system as the arbiter. `location_version`
 * is Masar's own sequence and `order_version` is the company's, and two counters
 * minted by two independent systems are not comparable — "version 3 here" says
 * nothing against "version 7 there". What *is* comparable is when each change
 * actually happened, so the arbiter is a timestamp taken at the origin, at the
 * moment the change committed, and frozen from then on (§13.17.5).
 *
 * Ties are broken deterministically rather than by arrival, because two changes
 * can share a second. The order is:
 *
 *   1. `changedAt` — later wins.
 *   2. `source` — the greater code wins. `masar` beats `delivery_company`.
 *   3. `eventId` — the greater id wins, which is always decisive because no two
 *      distinct changes share one.
 *
 * The exact choice in steps 2 and 3 is arbitrary and says so. What is not
 * arbitrary is that it is fixed, written down, and **identical in both
 * systems** — Masar carries a comparator identical to this one, term for term,
 * because two systems that broke ties differently would diverge on the first tie
 * and nothing would detect it. Duplicated rather than shared, for the reason the
 * envelope digests are duplicated: two deployments with two release cycles must
 * not depend on one library.
 *
 * The comparison assumes the two servers' clocks are reasonably synchronised
 * (§13.17.5). That is stated in the contract as an operating condition rather
 * than hidden here: a badly skewed clock misorders changes, and the remedy is to
 * fix the clock, not to invent a second arbiter. Courier device time is never
 * used for any of this.
 */
final class LocationChangeStamp
{
    /** A change that reached us from Masar (§13.17.1). */
    public const SOURCE_MASAR = 'masar';

    /** A change made here, by this system's own edit path (§13.17.8). */
    public const SOURCE_DELIVERY_COMPANY = 'delivery_company';

    public function __construct(
        public readonly Carbon $changedAt,
        public readonly string $source,
        public readonly string $eventId,
    ) {}

    /**
     * Whether `$incoming` describes a later business change than `$accepted`.
     *
     * A null `$accepted` means no location change has ever been recorded for
     * this order, so anything at all is newer — there is nothing for it to lose
     * to.
     */
    public static function wins(self $incoming, ?self $accepted): bool
    {
        return $accepted === null || self::compare($incoming, $accepted) > 0;
    }

    /**
     * The total order of §13.17.5: positive when `$a` is the later change.
     *
     * Compared to the second, because that is the resolution both systems store
     * and put on the wire. Sub-second precision would be a difference the
     * transport does not carry, so it must not be a difference the decision
     * depends on.
     */
    public static function compare(self $a, self $b): int
    {
        $byInstant = $a->changedAt->getTimestamp() <=> $b->changedAt->getTimestamp();

        if ($byInstant !== 0) {
            return $byInstant;
        }

        $bySource = strcmp($a->source, $b->source);

        if ($bySource !== 0) {
            return $bySource <=> 0;
        }

        return strcmp($a->eventId, $b->eventId) <=> 0;
    }

    /**
     * The stamp an order currently holds, or null if it has never held one.
     *
     * All three columns move together or not at all — a partial stamp would be a
     * comparison with a missing term — so a null in any of them is read as "no
     * accepted change", which is the safe reading: the next change wins and
     * writes a complete stamp.
     */
    public static function accepted(?string $changedAt, ?string $source, ?string $eventId): ?self
    {
        if ($changedAt === null || $source === null || $eventId === null) {
            return null;
        }

        return new self(Carbon::parse($changedAt)->utc(), $source, $eventId);
    }

    /** The wire form: one written moment for one instant, as every channel writes it. */
    public function instant(): string
    {
        return $this->changedAt->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
