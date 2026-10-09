<?php

namespace App\Services;

use App\Enums\AdministrativeState;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Enums\OperationalStatus;
use App\Enums\TourParticipation;
use App\Models\DeliveryOrder;
use BackedEnum;
use InvalidArgumentException;
use LogicException;

/**
 * The one definition of what an order's state *means*, in two renderings.
 *
 * Three questions are answered here and nowhere else: how far execution has got
 * (`OperationalStatus`), what this company has decided about the order
 * (`AdministrativeState`), and whether those two contradict each other. Each is
 * read in PHP for a screen and compiled to SQL for a sort, a filter or a count,
 * and **both renderings are produced from the same rule table** — the arrays
 * below. Nothing is written twice. A reviewer reading one rule sees the whole
 * of it: its order, its outcome and its conditions, on adjacent lines.
 *
 * That matters because the alternative was tried and rejected. A hand-written
 * `CASE` beside a hand-written PHP branch is two statements of one rule, and the
 * drift between them would surface as a badge that disagrees with the filter
 * that found it — the least debuggable class of bug this feature could have. The
 * rules are therefore *data*, and the two interpreters below know nothing about
 * delivery at all.
 *
 * **No clock, anywhere.** Neither projection reads the current time, and that is
 * a property rather than an omission: an order's operational status cannot
 * change unless a column changed, so a page refresh cannot move an order into
 * `in_progress` and a scheduled departure's hour passing cannot either. Whether
 * a tour has begun is decided at the source, by whether Masar set
 * `delivery_tours.started_at`, and arrives as `active` rather than being
 * inferred here from `masar_tour_departure_at`. That column is carried for
 * display and is deliberately absent from every rule.
 *
 * **Three-valued logic is the interpreter's job, not each rule's.** SQL and PHP
 * disagree about null: `NULL >= 0` is unknown in SQL and `null >= 0` is *true*
 * in PHP, which on the fence comparison below would have made an order with no
 * `base_order_version` read as validly in progress. So every comparison, in both
 * renderings, is false when any operand is null — the interpreters enforce it
 * once, and no rule has to remember.
 *
 * Six operational states and no seventh. Cancellation and local completion are a
 * separate dimension: folding them in would make one column answer two
 * questions, and a filter could then express neither on its own.
 */
final class OperationalStatusProjection
{
    /** The only shape a column or table identifier may take before it is quoted. */
    private const IDENTIFIER = '/^[a-z][a-z0-9_]*$/';

    /**
     * Masar's statement still concerns the assignment it was built on.
     *
     * Held apart from rule 4 and spread into it, so the clauses the rule applies
     * and the clauses {@see self::participationValidFor()} reports on are the
     * same array rather than two copies that agree today.
     *
     * @var list<array<int, mixed>>
     */
    private const PARTICIPATION_FENCE = [
        ['masar_participation_base_order_version', 'IS NOT NULL'],
        ['masar_participation_base_order_version', '>=', ['column' => 'assignment_order_version']],
    ];

    /**
     * The courier Masar named is resolvable, and is the one holding the order.
     *
     * @var list<array<int, mixed>>
     */
    private const PARTICIPATION_COURIER = [
        ['masar_tour_started_representative_id', 'IS NOT NULL'],
        ['masar_tour_started_representative_id', '=', ['column' => 'representative_id']],
    ];

    /**
     * Execution, in precedence order. First match wins; the last rule matches
     * everything.
     *
     * A standing result outranks participation, and that single ordering settles
     * three different problems at once — which is the sign it is the right one:
     *
     *   - a late `active` arriving after a delivery cannot move the order back
     *     into `in_progress`, because rule 1 is read before rule 4;
     *   - a postponed order admitted to a fresh tour still shows *why* it is in
     *     trouble, rather than hiding the reason behind "being delivered";
     *   - `in_progress` therefore means exactly "started, and no result stands",
     *     which is the state the other five already imply.
     *
     * Rule 4 carries **two independent guards, and neither is redundant** —
     * `in_progress` is the one state that asserts work is physically underway,
     * so it is the one that has to be earned.
     *
     * *The version fence* (clauses 2 and 3). `base_order_version` is the
     * `order_version` Masar had applied when it spoke, and
     * `assignment_order_version` is the `order_version` of the last effective
     * assignment here — one sequence, this company's, so the comparison is
     * legitimate where §13.12 forbids comparing two. Reassignment only ever
     * raises the second, so once a participation fact is overtaken it can never
     * become operative again without a newer statement built on the newer
     * assignment: a real new tour.
     *
     * *The courier check* (clauses 4 and 5). The participation must name a
     * courier this database can resolve, and it must be the one the order is
     * currently assigned to.
     *
     * Each catches precisely what the other cannot. The fence is the only thing
     * that stops an order reassigned A → B → A from reviving A's finished tour,
     * because by then the courier matches again. The courier check is the only
     * thing that stops an order from reading as being delivered by a courier
     * this database cannot name — an unmapped `external_courier_id` — or by one
     * it is not assigned to at all; both of those pass the fence untouched.
     * Without clauses 4 and 5 an order with `representative_id` null read
     * `in_progress`, which was the sharpest form of the defect: execution
     * claimed for an order with no courier whatsoever.
     *
     * The null guards are not decoration either. `masar_tour_started_representative_id`
     * is null for every unmapped courier and `representative_id` is null for
     * every unassigned order, and the interpreter's three-valued rule turns
     * both into a refusal rather than into an accidental match.
     *
     * @var list<array{0: string, 1: list<array<int, mixed>>}>
     */
    private const OPERATIONAL = [
        ['delivered', [
            ['masar_status_version', '>=', 1],
            ['delivery_status', '=', 'delivered'],
        ]],
        ['postponed', [
            ['masar_status_version', '>=', 1],
            ['delivery_status', '=', 'postponed'],
        ]],
        ['returned', [
            ['masar_status_version', '>=', 1],
            ['delivery_status', '=', 'returned'],
        ]],
        ['in_progress', [
            ['masar_participation', '=', 'active'],
            ...self::PARTICIPATION_FENCE,
            ...self::PARTICIPATION_COURIER,
        ]],
        ['assigned', [
            ['representative_id', 'IS NOT NULL'],
        ]],
        ['new', []],
    ];

    /**
     * This company's own verdict, read from the column it has always owned.
     *
     * `new` and `assigned` both answer this question `open`: the distinction
     * between them is execution progress, which belongs to the table above.
     *
     * @var list<array{0: string, 1: list<array<int, mixed>>}>
     */
    private const ADMINISTRATIVE = [
        ['cancelled', [
            ['status', '=', 'cancelled'],
        ]],
        ['completed_locally', [
            ['status', '=', 'completed'],
        ]],
        ['open', []],
    ];

    /**
     * Where the two dimensions contradict each other.
     *
     * Not a defect to smooth over. Masar accepts a delivery that chronologically
     * preceded a company cancellation (§3.14.5, class (ب)), so "cancelled here,
     * delivered there" is a legitimate and genuinely alarming state — and one an
     * operator has to see. Hiding it to keep a badge tidy is the misleading
     * display this whole split exists to avoid.
     *
     * Guarded on `masar_status_version >= 1` throughout: before Masar has spoken
     * there is nothing to contradict, and `delivery_status`'s default would
     * otherwise read as a statement.
     *
     * @var list<array{0: string, 1: list<array<int, mixed>>}>
     */
    private const CONFLICT = [
        ['1', [
            ['masar_status_version', '>=', 1],
            ['status', '=', 'cancelled'],
            ['delivery_status', 'IN', ['delivered', 'postponed', 'returned']],
        ]],
        ['1', [
            ['masar_status_version', '>=', 1],
            ['status', '=', 'completed'],
            ['result', '=', 'delivered'],
            ['delivery_status', 'IN', ['postponed', 'returned']],
        ]],
        ['1', [
            ['masar_status_version', '>=', 1],
            ['status', '=', 'completed'],
            ['result', '=', 'not_delivered'],
            ['delivery_status', '=', 'delivered'],
        ]],
        ['0', []],
    ];

    // ------------------------------------------------------------------ PHP

    public static function operationalFor(DeliveryOrder $order): OperationalStatus
    {
        return OperationalStatus::from(self::evaluate(self::OPERATIONAL, $order));
    }

    public static function administrativeFor(DeliveryOrder $order): AdministrativeState
    {
        return AdministrativeState::from(self::evaluate(self::ADMINISTRATIVE, $order));
    }

    public static function hasConflict(DeliveryOrder $order): bool
    {
        return self::evaluate(self::CONFLICT, $order) === '1';
    }

    /**
     * Whether Masar's participation statement still concerns the current
     * assignment — rule 4's fence, exposed on its own.
     *
     * The projection uses it inside `in_progress`; the interface needs it
     * separately, to say "a participation fact was set aside locally" rather
     * than silently showing nothing. Built from the same clauses, so the two can
     * never disagree.
     */
    public static function participationValidFor(DeliveryOrder $order): bool
    {
        return self::matches(self::PARTICIPATION_FENCE, $order);
    }

    /**
     * Whether the courier Masar named is the one this order is assigned to.
     *
     * Separated from the fence because the two failures want different words on
     * the screen: a fence failure means "this participation belongs to an
     * assignment that has since been superseded", and a courier failure means
     * "Masar named a courier we cannot match to this order". Collapsing them
     * into one boolean would make the interface guess which had happened.
     */
    public static function participationCourierMatchesFor(DeliveryOrder $order): bool
    {
        return self::matches(self::PARTICIPATION_COURIER, $order);
    }

    // ------------------------------------------------------------------ SQL

    /**
     * The same rules as a `CASE` expression, with its bindings.
     *
     * Returned as a pair rather than an interpolated string: every literal is a
     * bound parameter, and only identifiers are ever written into the SQL — each
     * one checked against {@see self::IDENTIFIER} and back-quoted. Nothing a
     * caller passes can reach the statement except the table alias, which is
     * checked the same way.
     *
     * @return array{0: string, 1: list<scalar>}
     */
    public static function operationalSql(string $alias = 'delivery_orders'): array
    {
        return self::compile(self::OPERATIONAL, $alias);
    }

    /** @return array{0: string, 1: list<scalar>} */
    public static function administrativeSql(string $alias = 'delivery_orders'): array
    {
        return self::compile(self::ADMINISTRATIVE, $alias);
    }

    /** @return array{0: string, 1: list<scalar>} */
    public static function conflictSql(string $alias = 'delivery_orders'): array
    {
        return self::compile(self::CONFLICT, $alias);
    }

    // --------------------------------------------------------- interpreters

    /**
     * @param  list<array{0: string, 1: list<array<int, mixed>>}>  $rules
     */
    private static function evaluate(array $rules, DeliveryOrder $order): string
    {
        self::assertWellFormed($rules);

        foreach ($rules as [$outcome, $clauses]) {
            if (self::matches($clauses, $order)) {
                return $outcome;
            }
        }

        // Unreachable: `assertWellFormed` requires a final rule with no clauses,
        // and `matches([])` is true. Stated rather than assumed, because a rule
        // table that silently returned nothing would be worse than a throw.
        throw new LogicException('The rule table matched nothing, which its final rule forbids.');
    }

    /**
     * @param  list<array<int, mixed>>  $clauses
     */
    private static function matches(array $clauses, DeliveryOrder $order): bool
    {
        foreach ($clauses as $clause) {
            if (! self::clauseHolds($clause, $order)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One clause, with null handled exactly as the SQL rendering handles it.
     *
     * @param  array<int, mixed>  $clause
     */
    private static function clauseHolds(array $clause, DeliveryOrder $order): bool
    {
        $left = self::scalarOf($order, self::column($clause[0]));
        $operator = $clause[1];

        if ($operator === 'IS NOT NULL') {
            return $left !== null;
        }

        // Any null operand makes the comparison false, matching the explicit
        // `IS NOT NULL` guards the compiler emits. Without this, `null >= 0`
        // would be true here and unknown in SQL.
        if ($left === null) {
            return false;
        }

        $right = $clause[2];

        if (is_array($right) && array_key_exists('column', $right)) {
            $right = self::scalarOf($order, self::column($right['column']));

            if ($right === null) {
                return false;
            }
        }

        return match ($operator) {
            '=' => $left === $right || (is_numeric($left) && is_numeric($right) && $left == $right),
            '>=' => (float) $left >= (float) $right,
            'IN' => in_array($left, $right, true),
            default => throw new InvalidArgumentException("Unsupported operator [{$operator}]."),
        };
    }

    /**
     * The model's value for one column, reduced to a comparable scalar.
     *
     * Enum casts are unwrapped to their backing value so that a rule can be
     * written against the wire vocabulary — `'delivered'`, `'active'`,
     * `'cancelled'` — and mean the same thing in both renderings. Nothing else
     * is coerced: an integer stays an integer and null stays null.
     */
    private static function scalarOf(DeliveryOrder $order, string $column): string|int|float|null
    {
        $value = $order->getAttribute($column);

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        return (string) $value;
    }

    /**
     * @param  list<array{0: string, 1: list<array<int, mixed>>}>  $rules
     * @return array{0: string, 1: list<scalar>}
     */
    private static function compile(array $rules, string $alias): array
    {
        self::assertWellFormed($rules);

        if (preg_match(self::IDENTIFIER, $alias) !== 1) {
            throw new InvalidArgumentException("Unsafe table alias [{$alias}].");
        }

        $sql = 'CASE';
        $bindings = [];

        foreach ($rules as [$outcome, $clauses]) {
            if ($clauses === []) {
                $sql .= ' ELSE ?';
                $bindings[] = $outcome;

                continue;
            }

            $fragments = [];

            foreach ($clauses as $clause) {
                [$fragment, $clauseBindings] = self::compileClause($clause, $alias);
                $fragments[] = $fragment;
                $bindings = array_merge($bindings, $clauseBindings);
            }

            $sql .= ' WHEN ('.implode(' AND ', $fragments).') THEN ?';
            $bindings[] = $outcome;
        }

        return [$sql.' END', $bindings];
    }

    /**
     * @param  array<int, mixed>  $clause
     * @return array{0: string, 1: list<scalar>}
     */
    private static function compileClause(array $clause, string $alias): array
    {
        $left = self::qualified($alias, self::column($clause[0]));
        $operator = $clause[1];

        if ($operator === 'IS NOT NULL') {
            return [$left.' IS NOT NULL', []];
        }

        $right = $clause[2];

        if (is_array($right) && array_key_exists('column', $right)) {
            $other = self::qualified($alias, self::column($right['column']));

            // Both sides guarded, so a null on either makes the clause false
            // rather than unknown — and `CASE WHEN unknown` falls through
            // exactly as `false` does, which is why the PHP side must agree.
            return ["({$left} IS NOT NULL AND {$other} IS NOT NULL AND {$left} {$operator} {$other})", []];
        }

        if ($operator === 'IN') {
            $placeholders = implode(', ', array_fill(0, count($right), '?'));

            return ["({$left} IS NOT NULL AND {$left} IN ({$placeholders}))", array_values($right)];
        }

        if ($operator !== '=' && $operator !== '>=') {
            throw new InvalidArgumentException("Unsupported operator [{$operator}].");
        }

        return ["({$left} IS NOT NULL AND {$left} {$operator} ?)", [$right]];
    }

    private static function column(mixed $name): string
    {
        if (! is_string($name) || preg_match(self::IDENTIFIER, $name) !== 1) {
            throw new InvalidArgumentException('Unsafe column identifier in the rule table.');
        }

        return $name;
    }

    private static function qualified(string $alias, string $column): string
    {
        return '`'.$alias.'`.`'.$column.'`';
    }

    /**
     * The two structural invariants the interpreters rely on.
     *
     * Checked rather than trusted because both are silent when broken: a table
     * with no catch-all would throw from `evaluate` on some row nobody tested,
     * and a clauseless rule in the middle would make every rule after it dead
     * code that reads as live.
     *
     * @param  list<array{0: string, 1: list<array<int, mixed>>}>  $rules
     */
    private static function assertWellFormed(array $rules): void
    {
        if ($rules === []) {
            throw new LogicException('A rule table cannot be empty.');
        }

        foreach ($rules as $index => [, $clauses]) {
            $isLast = $index === count($rules) - 1;

            if ($clauses === [] && ! $isLast) {
                throw new LogicException("Rule {$index} has no conditions, so every rule after it is unreachable.");
            }

            if ($clauses !== [] && $isLast) {
                throw new LogicException('The final rule must have no conditions, so that some rule always matches.');
            }
        }
    }

    /**
     * The vocabularies the rule tables are written against, asserted once.
     *
     * The literals above are wire values — `'delivered'`, `'active'`,
     * `'cancelled'` — and they are only meaningful while the enums still spell
     * them that way. A rename would otherwise leave the rules compiling, the SQL
     * running, and every comparison quietly false. The equivalence test calls
     * this.
     */
    public static function assertVocabulariesIntact(): void
    {
        // Pairs, not a map: `DeliveryStatus::Delivered` and
        // `DeliveryOrderResult::Delivered` both spell `delivered`, and keying on
        // the value would collapse them into one silently unchecked entry.
        $expected = [
            ['DeliveryStatus::Delivered', DeliveryStatus::Delivered->value, 'delivered'],
            ['DeliveryStatus::Postponed', DeliveryStatus::Postponed->value, 'postponed'],
            ['DeliveryStatus::Returned', DeliveryStatus::Returned->value, 'returned'],
            ['TourParticipation::Active', TourParticipation::Active->value, 'active'],
            ['DeliveryOrderStatus::Cancelled', DeliveryOrderStatus::Cancelled->value, 'cancelled'],
            ['DeliveryOrderStatus::Completed', DeliveryOrderStatus::Completed->value, 'completed'],
            ['DeliveryOrderResult::Delivered', DeliveryOrderResult::Delivered->value, 'delivered'],
            ['DeliveryOrderResult::NotDelivered', DeliveryOrderResult::NotDelivered->value, 'not_delivered'],
        ];

        foreach ($expected as [$case, $actual, $assumed]) {
            if ($actual !== $assumed) {
                throw new LogicException(
                    "The rule table assumes {$case} spells [{$assumed}], but it now spells [{$actual}].",
                );
            }
        }
    }
}
