<?php

namespace App\Console\Commands;

use App\Models\DeliveryOrder;
use App\Models\Representative;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-resolves the courier of a participation statement after the representative
 * mapping has been repaired.
 *
 * **The hole this fills.** When Masar announces that an order joined a tour, the
 * payload names the courier by `external_courier_id`. If that uid matches no
 * representative here — the row has not been created yet, or its mapping was
 * wrong — the receiver stores the uid verbatim and leaves the local reference
 * null. It does *not* refuse the event: Masar treats every 4xx as terminal and
 * never retries, so refusing would discard a true statement for good over a
 * mapping this channel does not own. Instead the statement is kept, and the
 * projection declines to show the order as being delivered by a courier it
 * cannot name.
 *
 * That leaves the order reading "assigned" until something fills the reference
 * in. Masar re-announcing would do it, but Masar has no reason to: from its side
 * nothing changed. A replay will not do it either, and must not — the saved
 * answer to an `event_id` is the saved answer, and reconsidering it would make
 * the reply to one event depend on when it was asked. So the repair is local, and
 * this is it.
 *
 * **Why it does not violate idempotency.** It mints no event, sends nothing, and
 * asks Masar nothing. It re-derives one local lookup from a uid that was already
 * received and stored, which is precisely what the processor did at apply time —
 * only later, with a mapping that now exists. The participation state, its
 * version, the `event_id` and the whole event log are untouched, so a replay of
 * the original event still answers `already_processed`.
 *
 * **What it cannot do.** It does not bypass either guard. Filling the reference
 * in makes the courier check pass; the *assignment fence* is a separate
 * condition and this command does not touch it, so an order whose participation
 * was superseded by a reassignment stays "assigned" after a successful repair —
 * correctly, because that participation no longer concerns the current
 * assignment. Nor does it ever match on a name or a phone number: a uid either
 * resolves or it does not.
 */
class ResolveParticipationCouriers extends Command
{
    protected $signature = 'masar:resolve-participation-couriers
        {--apply : Write the resolutions. Without this the command only reports what it would do.}
        {--limit=500 : The most orders to examine in one pass.}';

    protected $description = 'Re-resolve the local representative of a Masar participation statement after the courier mapping is repaired.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));

        $database = DB::connection()->getDatabaseName();

        $this->line('Database : '.$database);
        $this->line('Mode     : '.($apply ? 'APPLY (writes)' : 'DRY RUN (no writes)'));
        $this->newLine();

        // Candidates: every order carrying a courier uid from Masar.
        //
        // Deliberately **not** narrowed to those missing a local reference. A
        // row whose recorded reference disagrees with its uid is a real
        // inconsistency — the mapping was repaired to point somewhere else, or
        // the uid was reassigned — and a scan blind to it would report a clean
        // pass over a database that is not. Such rows are reported and never
        // written; §10 is explicit that a correct-looking mapping is not
        // replaced without the conflict being documented and the record left
        // alone.
        //
        // Selected by id so the pass is deterministic, and re-read under a lock
        // one at a time below: a set chosen here is a set as it was a moment
        // ago, and the write has to answer to the row as it is.
        $candidates = DeliveryOrder::query()
            ->whereNotNull('masar_tour_started_courier_uid')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $counts = ['resolved' => 0, 'unmapped' => 0, 'conflicted' => 0, 'consistent' => 0, 'changed' => 0];

        foreach ($candidates as $orderId) {
            $outcome = $apply
                ? $this->repair((int) $orderId)
                : $this->inspect((int) $orderId);

            $counts[$outcome['status']]++;

            // `consistent` is the ordinary case on a healthy database and would
            // drown the two that need a person, so it is counted and not
            // printed unless asked for.
            if (! in_array($outcome['status'], ['consistent'], true) || $this->output->isVerbose()) {
                $this->line(sprintf('  order %-8s %-11s %s', $orderId, $outcome['status'], $outcome['detail']));
            }
        }

        $this->newLine();
        $this->table(
            ['examined', 'resolvable/resolved', 'still unmapped', 'conflicted', 'already consistent', 'changed under us'],
            [[
                $candidates->count(),
                $counts['resolved'],
                $counts['unmapped'],
                $counts['conflicted'],
                $counts['consistent'],
                $counts['changed'],
            ]],
        );

        if (! $apply && $counts['resolved'] > 0) {
            $this->warn('Dry run: nothing was written. Re-run with --apply to write these resolutions.');
        }

        if ($counts['conflicted'] > 0) {
            $this->error('Some orders were skipped because a different courier was already recorded. Those need a person.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * What a repair would do, without writing.
     *
     * Reads without a lock on purpose: a dry run reports, and a report that
     * took row locks over a whole batch would be a worse neighbour than the
     * thing it is describing.
     *
     * @return array{status: string, detail: string}
     */
    private function inspect(int $orderId): array
    {
        $order = DeliveryOrder::query()->find($orderId);

        if ($order === null) {
            return ['status' => 'changed', 'detail' => 'the order disappeared between the scan and the read'];
        }

        $uid = (string) $order->masar_tour_started_courier_uid;
        $representativeId = $this->resolve($uid);

        if ($representativeId === null) {
            return ['status' => 'unmapped', 'detail' => 'no representative carries this uid yet'];
        }

        $existing = $order->masar_tour_started_representative_id;

        if ($existing === null) {
            return ['status' => 'resolved', 'detail' => "would resolve to representative {$representativeId}"];
        }

        return (int) $existing === $representativeId
            ? ['status' => 'consistent', 'detail' => 'already resolved to the representative this uid names']
            : [
                'status' => 'conflicted',
                'detail' => "recorded as {$existing}, but uid {$uid} names {$representativeId} — would leave untouched",
            ];
    }

    /**
     * Resolve and write one order's courier reference.
     *
     * Everything is re-read under the order's row lock, and that is the whole of
     * the safety. Between the scan above and this transaction a newer
     * participation event may have arrived — carrying its own uid and its own
     * resolution — and writing the old answer over it would repair a statement
     * that is no longer the current one. So the uid is compared again, the
     * reference is checked for having been filled in the meantime, and anything
     * that moved is reported rather than overwritten.
     *
     * @return array{status: string, detail: string}
     */
    private function repair(int $orderId): array
    {
        return DB::transaction(function () use ($orderId): array {
            $order = DeliveryOrder::query()->lockForUpdate()->find($orderId);

            if ($order === null) {
                return ['status' => 'changed', 'detail' => 'the order disappeared before the write'];
            }

            $uid = (string) $order->masar_tour_started_courier_uid;

            if ($uid === '') {
                // A newer event arrived and carried no courier, so the uid this
                // pass was going to repair is gone. Nothing to do, and nothing
                // to complain about.
                return ['status' => 'changed', 'detail' => 'the recorded uid was replaced by a newer statement'];
            }

            $representativeId = $this->resolve($uid);

            if ($representativeId === null) {
                return ['status' => 'unmapped', 'detail' => 'no representative carries this uid yet'];
            }

            $existing = $order->masar_tour_started_representative_id;

            if ($existing !== null) {
                // Agreement needs no write. Disagreement is left exactly as it
                // is and reported, because choosing between two couriers is not
                // a decision a repair tool gets to make — the uid may have been
                // reassigned, or the mapping repaired to point elsewhere, and
                // only a person can say which is right.
                return (int) $existing === $representativeId
                    ? ['status' => 'consistent', 'detail' => 'already resolved to the representative this uid names']
                    : [
                        'status' => 'conflicted',
                        'detail' => "recorded as {$existing}, but uid {$uid} names {$representativeId} — left untouched",
                    ];
            }

            // The one column this command writes. Not the participation, not its
            // version, not the uid, not `delivery_status`, not `status`, not
            // `result`, and nothing that would raise `order_version` or put a
            // row in the outbox.
            $order->forceFill(['masar_tour_started_representative_id' => $representativeId])->save();

            return ['status' => 'resolved', 'detail' => "resolved to representative {$representativeId}"];
        });
    }

    /**
     * The local representative a uid names, or null.
     *
     * `integration_uid` and nothing else. Never a name, never a phone number:
     * both change, and a repair that guessed from them would hand one courier's
     * tour to another with full confidence.
     */
    private function resolve(string $uid): ?int
    {
        if ($uid === '') {
            return null;
        }

        $id = Representative::query()->where('integration_uid', $uid)->value('id');

        return $id === null ? null : (int) $id;
    }
}
