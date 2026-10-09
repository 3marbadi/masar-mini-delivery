<?php

namespace App\Enums;

/**
 * The six operational states an operator reads (approved scope, prompt 1 §1).
 *
 * **Six and only six.** The administrative facts this company owns —
 * cancellation and local completion — are deliberately *not* members here;
 * they are a second, orthogonal dimension in `AdministrativeState`. Folding
 * `cancelled` in as a seventh value would make the two questions "how far has
 * execution got?" and "did the company withdraw this order?" share one column,
 * and a filter could then express neither of them independently.
 *
 * Nothing writes this. It is derived by `OperationalStatusProjection` from
 * columns two different systems own, and the derivation is a pure function of
 * those columns — no clock, no request time, no ambient state. An order's
 * operational status therefore cannot change unless something was written, which
 * is the property that makes it safe to show, sort, filter and count by.
 */
enum OperationalStatus: string
{
    case NewOrder = 'new';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Postponed = 'postponed';
    case Returned = 'returned';
    case Delivered = 'delivered';

    /**
     * The Arabic label, exhaustive by construction.
     *
     * A `match` over `$this` with one arm per case and no `default`: adding a
     * seventh case makes this a compile-time-visible omission rather than an
     * `UnhandledMatchError` discovered in production — which is exactly what the
     * four existing `DeliveryOrderStatus` formatters would do today.
     */
    public function label(): string
    {
        return match ($this) {
            self::NewOrder => 'جديدة',
            self::Assigned => 'مسندة',
            self::InProgress => 'جاري التوصيل',
            self::Postponed => 'مؤجل',
            self::Returned => 'راجع',
            self::Delivered => 'تم التسليم',
        };
    }

    /**
     * The badge colour, exhaustive for the same reason the label is.
     *
     * `in_progress` takes `primary` rather than another semantic colour because
     * it is the only one of the six that is neither an outcome nor a waiting
     * state: it says work is happening now, and it must not read as a success
     * or a warning.
     */
    public function color(): string
    {
        return match ($this) {
            self::NewOrder => 'gray',
            self::Assigned => 'info',
            self::InProgress => 'primary',
            self::Postponed => 'warning',
            self::Returned => 'danger',
            self::Delivered => 'success',
        };
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
