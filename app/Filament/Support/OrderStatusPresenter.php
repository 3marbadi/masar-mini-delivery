<?php

namespace App\Filament\Support;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryStatus;
use App\Enums\TourParticipation;
use App\Models\DeliveryOrder;
use App\Services\OperationalStatusProjection;
use Illuminate\Support\Carbon;

/**
 * How an order's two state dimensions are shown, in one place.
 *
 * **It derives nothing.** Every judgement here is asked of
 * `OperationalStatusProjection` — the operational status, the administrative
 * state, the conflict, the participation fence, the courier match — and this
 * class only decides which of them leads, what each is called, and what colour
 * it wears. The division matters: a screen that re-derived "is this in
 * progress?" from columns would be a second rule able to disagree with the
 * filter that found the row, and that disagreement is the least debuggable bug
 * this feature could have.
 *
 * Five surfaces share it (the orders table, the order page, the dashboard's
 * recent list, the customer's orders panel, the statistics), so a label or a
 * precedence rule changes once.
 *
 * **Display precedence.** When this company has closed an order — cancelled or
 * completed locally — that decision leads, and execution is shown beside it as
 * separate information. An order the company withdrew must never read as merely
 * active. While the order is open, execution leads, because that is the
 * question an operator is actually asking.
 *
 * Neither dimension is ever hidden, and neither is ever written: the two
 * sources keep their own columns and their own meanings, and a disagreement
 * between them is surfaced as a disagreement rather than smoothed away.
 */
final class OrderStatusPresenter
{
    /**
     * The badge that leads — administrative when the company has closed the
     * order, operational otherwise.
     *
     * @return array{label: string, color: string}
     */
    public static function primary(DeliveryOrder $order): array
    {
        $administrative = OperationalStatusProjection::administrativeFor($order);

        if ($administrative->dominatesDisplay()) {
            return ['label' => $administrative->label(), 'color' => $administrative->color()];
        }

        $operational = OperationalStatusProjection::operationalFor($order);

        return ['label' => $operational->label(), 'color' => $operational->color()];
    }

    /**
     * The badge that accompanies it, or null when the primary already carries
     * the whole story.
     *
     * For a closed order this is the execution state, which is exactly the
     * information §5 forbids hiding: an order cancelled after Masar announced a
     * delivery still has to show that delivery. For an open order there is
     * nothing to add — the operational badge *is* the primary.
     *
     * @return array{label: string, color: string}|null
     */
    public static function secondary(DeliveryOrder $order): ?array
    {
        if (! OperationalStatusProjection::administrativeFor($order)->dominatesDisplay()) {
            return null;
        }

        $operational = OperationalStatusProjection::operationalFor($order);

        return ['label' => $operational->label(), 'color' => 'gray'];
    }

    /** The operational badge on its own, for surfaces that show both columns. */
    public static function operationalLabel(DeliveryOrder $order): string
    {
        return OperationalStatusProjection::operationalFor($order)->label();
    }

    public static function operationalColor(DeliveryOrder $order): string
    {
        return OperationalStatusProjection::operationalFor($order)->color();
    }

    /**
     * The diagnostic notes an operator needs to read the two badges honestly.
     *
     * Each is a fact with a named cause, not a guess. They are deliberately
     * *notes* rather than states: turning any of them into a seventh
     * operational status would make the six stop being a partition, and the
     * filters would stop matching the badges.
     *
     * @return list<array{text: string, tone: string}>
     */
    public static function notes(DeliveryOrder $order): array
    {
        $notes = [];

        // §7.A — Masar announced a result and then withdrew it. The order is
        // back with the courier, and the operational status cannot say so on
        // its own: `with_rep` is also the state of an order Masar has never
        // spoken about, and the version is what tells them apart.
        if (self::resultWasWithdrawn($order)) {
            $notes[] = ['text' => 'أُعيد إلى المندوب بعد تعديل النتيجة', 'tone' => 'warning'];
        }

        // §7.B — a participation statement that reassignment has superseded.
        // Shown rather than dropped: the operator would otherwise see an order
        // that Masar believes is out for delivery reading as merely assigned,
        // with nothing to explain the difference.
        if (self::participationWasSuperseded($order)) {
            $notes[] = ['text' => 'مشاركة سابقة أُبطلت بإعادة الإسناد', 'tone' => 'warning'];
        }

        // §7.C — the courier Masar named is not the one holding the order, or
        // is not resolvable here at all. Two different repairs, so two
        // different sentences; neither invents an identity.
        foreach (self::courierNotes($order) as $note) {
            $notes[] = $note;
        }

        // §5.1, §5.2 — the company closed the order and Masar's execution says
        // something else. Stated as a disagreement between two named sources,
        // which is what it is.
        if (OperationalStatusProjection::hasConflict($order)) {
            $notes[] = ['text' => self::conflictSentence($order), 'tone' => 'danger'];
        }

        return $notes;
    }

    /**
     * §7.A — `with_rep` at a version above zero means a withdrawal.
     *
     * Composed from the columns the status channel owns rather than restated as
     * a rule: there is nothing to filter or sort by here, so it needs no SQL
     * rendering and gets none.
     */
    public static function resultWasWithdrawn(DeliveryOrder $order): bool
    {
        return (int) $order->masar_status_version >= 1
            && $order->delivery_status === DeliveryStatus::WithRepresentative;
    }

    /**
     * §7.B — Masar says the order is in a tour, and the fence says that
     * statement belongs to a superseded assignment.
     *
     * Built by composing the projection's own fence rather than by repeating
     * its clauses, so there is still one definition of what the fence is.
     */
    public static function participationWasSuperseded(DeliveryOrder $order): bool
    {
        return self::participationIsLive($order)
            && ! OperationalStatusProjection::participationValidFor($order);
    }

    /**
     * §7.C — the courier notes, which are two cases and not one.
     *
     * Only asked while the participation is both live and still valid for the
     * current assignment: a superseded statement already has its own note, and
     * adding a courier complaint to it would describe one situation twice.
     *
     * @return list<array{text: string, tone: string}>
     */
    public static function courierNotes(DeliveryOrder $order): array
    {
        if (! self::participationIsLive($order) || ! OperationalStatusProjection::participationValidFor($order)) {
            return [];
        }

        if (OperationalStatusProjection::participationCourierMatchesFor($order)) {
            return [];
        }

        // Unresolvable: Masar named a courier this database cannot match to a
        // row. The uid is kept, so the repair is a mapping fix followed by
        // `masar:resolve-participation-couriers` — and the uid is shown,
        // abbreviated, because it is what the person doing that fix needs.
        if ($order->masar_tour_started_representative_id === null) {
            $uid = (string) $order->masar_tour_started_courier_uid;

            return [[
                'text' => 'مندوب مَسار غير مربوط محلياً'
                    .($uid === '' ? '' : ' (‏'.mb_substr($uid, 0, 8).'…)'),
                'tone' => 'danger',
            ]];
        }

        // Resolvable, and not this order's courier. A different problem with a
        // different repair, so it gets its own sentence.
        return [[
            'text' => 'المندوب في واقعة المشاركة يخالف المندوب المسند حالياً',
            'tone' => 'danger',
        ]];
    }

    /**
     * §7.D — how old Masar's last participation statement is, as information.
     *
     * Deliberately **not** a threshold and **not** a state. Tour closure is not
     * synchronised on any channel, so an age is evidence of silence and nothing
     * more: it cannot show that a tour ended, and no amount of it turns `active`
     * into `ended`. The figure is offered so an operator can see that what they
     * are reading is old; the judgement stays theirs.
     */
    public static function participationAge(DeliveryOrder $order, ?Carbon $now = null): ?string
    {
        $changedAt = $order->masar_participation_changed_at;

        if ($changedAt === null) {
            return null;
        }

        return $changedAt->diffForHumans($now ?? Carbon::now(), ['locale' => 'ar']);
    }

    /** Whether Masar currently places this order inside a tour. */
    private static function participationIsLive(DeliveryOrder $order): bool
    {
        return in_array(
            $order->masar_participation,
            [TourParticipation::Scheduled, TourParticipation::Active],
            true,
        );
    }

    /**
     * The conflict, spelled out with both sources named.
     *
     * A sentence rather than a flag, because "there is a conflict" is not
     * actionable and "the company cancelled this order and Masar reports it
     * delivered" is.
     */
    private static function conflictSentence(DeliveryOrder $order): string
    {
        $masar = self::masarResultLabel($order);

        $local = match (true) {
            $order->result === DeliveryOrderResult::Delivered => 'مكتمل محلياً: تم التسليم',
            $order->result === DeliveryOrderResult::NotDelivered => 'مكتمل محلياً: لم يتم التسليم',
            default => 'ملغي إدارياً',
        };

        return "تعارض: {$local} — ومَسار يُعلن «{$masar}»";
    }

    /**
     * Masar's standing result in words, for the secondary line §5.1 asks for.
     *
     * Null when Masar has not spoken: `delivery_status` defaults to `with_rep`,
     * so reading it without checking the version would report a result that was
     * never announced.
     */
    public static function masarResultLabel(DeliveryOrder $order): ?string
    {
        if ((int) $order->masar_status_version < 1) {
            return null;
        }

        return match ($order->delivery_status) {
            DeliveryStatus::Delivered => 'تم التسليم',
            DeliveryStatus::Postponed => 'مؤجل',
            DeliveryStatus::Returned => 'راجع',
            DeliveryStatus::WithRepresentative => 'مع المندوب (نتيجة مسحوبة)',
            null => null,
        };
    }
}
