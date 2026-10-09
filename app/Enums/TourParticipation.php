<?php

namespace App\Enums;

/**
 * Where an order stands in Masar's tour execution (CONTRACT §13.29 — D31, draft).
 *
 * Masar owns this vocabulary whole. Nothing in Mini Delivery produces a value
 * here: the three states arrive on `order.tour.participation.updated`, they are
 * checked against this list, and they are stored. `None` is the fourth member
 * and the only one that never travels on the wire — it is the state of every row
 * that existed before this channel opened, and of every order Masar has not yet
 * said anything about.
 *
 * **One event type carries all three, and that is the contract rather than a
 * convenience.** §3.21.3 refused a second event type for the result clear with
 * the argument this channel inherits — «هو الحالُ نفسُها تبدّلت … ولا يُلزَم
 * المستقبِلُ بفرعٍ ثانٍ حيث يكفي فرعٌ واحد» — and §13.17 named its channel
 * `updated` rather than `completed` for the same reason. The decisive
 * consequence here is ordering: one event type means one sequence, so an `ended`
 * belonging to a finished tour and an `active` belonging to a newer one are
 * comparable. Two types with two sequences would not be, and the late `ended`
 * would silently undo the newer start.
 *
 * `Scheduled` and `Active` are deliberately distinct. A courier who pressed
 * "start tour" and chose a departure two hours out has *prepared* a tour, not
 * begun one, and the difference is decided at the source — by whether
 * `delivery_tours.started_at` is set — never here by comparing a clock. That is
 * what keeps the operational projection a pure function of stored columns: the
 * passage of time alone can never move an order into `in_progress`.
 */
enum TourParticipation: string
{
    /** Masar has said nothing about this order's participation. Never sent. */
    case None = 'none';

    /** Admitted to a tour whose departure has not been confirmed. */
    case Scheduled = 'scheduled';

    /** Admitted to a tour the courier has begun. */
    case Active = 'active';

    /** No longer counted in a started tour — reassigned away, or its tour closed. */
    case Ended = 'ended';

    /**
     * The three values this channel accepts on the wire.
     *
     * `None` is absent on purpose rather than by oversight: it is this system's
     * own initial state, and accepting it inbound would let Masar withdraw an
     * order from participation without saying which of the two withdrawals —
     * reassignment or closure — had happened.
     *
     * @return list<string>
     */
    public static function inboundCodes(): array
    {
        return [
            self::Scheduled->value,
            self::Active->value,
            self::Ended->value,
        ];
    }
}
