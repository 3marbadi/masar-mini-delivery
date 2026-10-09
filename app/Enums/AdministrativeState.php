<?php

namespace App\Enums;

/**
 * Mini Delivery's own lifecycle verdict on an order, read as one value.
 *
 * The second of the two dimensions the operational status is split from. It is
 * derived from `delivery_orders.status`, which this company owns outright and
 * which no Masar channel may write (CONTRACT.md line 4105, §3.21.10) — so this
 * enum is a *reading* of the existing column, never a replacement for it.
 * `status` keeps every reader it has: the lifecycle guards, `canEdit`, the four
 * dashboard counters and the reception-rate denominator are untouched.
 *
 * Three values because `status` has four and two of them answer this question
 * the same way: `new` and `assigned` both mean the company has not closed the
 * order, which is `Open`. The distinction between them is execution progress,
 * and that belongs to `OperationalStatus`.
 */
enum AdministrativeState: string
{
    /** The company has neither completed nor withdrawn this order. */
    case Open = 'open';

    /** Withdrawn by the company (`status = cancelled`). */
    case Cancelled = 'cancelled';

    /** Closed by the company with its own result (`status = completed`). */
    case CompletedLocally = 'completed_locally';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'مفتوح إدارياً',
            self::Cancelled => 'ملغي إدارياً',
            self::CompletedLocally => 'مكتمل محلياً',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'gray',
            self::Cancelled => 'danger',
            self::CompletedLocally => 'success',
        };
    }

    /**
     * Whether this state should take visual precedence over the operational one.
     *
     * `open` says the company has not closed the order, which adds nothing to
     * what the operational badge already shows — so it steps aside. The other
     * two are decisions this company made, and an order carrying one of them
     * must not be read as merely active.
     */
    public function dominatesDisplay(): bool
    {
        return $this !== self::Open;
    }

    /**
     * value => label, for a select filter.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            [],
        );
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_column(self::cases(), 'value');
    }
}
