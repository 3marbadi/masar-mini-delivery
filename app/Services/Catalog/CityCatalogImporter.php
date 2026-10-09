<?php

namespace App\Services\Catalog;

use App\Enums\FulfilmentKind;
use App\Models\DeliveryCity;
use App\Models\DeliveryRegion;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Builds the city and region catalog from the source CSV (PLAN D1 §4.2).
 *
 * Re-runnable by design: the same file imported twice writes nothing the second
 * time and creates nothing twice. That is not a convenience — it is what makes
 * the file the catalog's source of truth rather than a one-off seed, and it is
 * the reason every match is on `source_city_id` / `source_region_id` and never
 * on a name.
 *
 * ## The shape of the file, and why counting is not obvious
 *
 * One row is a *region*, with its city's columns repeated beside it. طرابلس
 * appears on 50 rows and is one city; the 84 cities with no regions appear on
 * one row each with the region columns blank. So the importer folds rows into
 * records first and writes second, and every count it reports is a count of
 * records. Counting rows would report 305 cities where there are 95.
 *
 * ## Rejection is per record, and the run still commits
 *
 * A malformed or self-contradictory record is dropped, counted, and reported
 * with its line number and reason; the records around it are still written. The
 * alternative — abort everything on one bad row — makes a single typo anywhere
 * in the file block a catalog refresh entirely, and turns the
 * `inserted/updated/unchanged/rejected` report PLAN §4.2 asks for into a report
 * that can only ever show one of those four. Callers that genuinely want
 * all-or-nothing pass `$strict`, which rolls the whole transaction back if
 * anything was rejected.
 *
 * Either way the writes are one transaction, so the failure mode PLAN §4.2
 * forbids — a half-updated catalog — cannot happen: on any error nothing lands.
 *
 * ## What is contradictory, and what is merely repeated
 *
 * Because the city columns repeat, the file can disagree with itself. Two rows
 * giving city `6` a different price are not a row to choose between; they are a
 * source defect, and picking the first or the last would publish a price nobody
 * decided. Such a city is rejected whole, with every line that mentions it
 * named. The same applies to a region id appearing twice with different
 * contents. A region id appearing twice with *identical* contents is just the
 * repetition the format invites, and is imported once.
 *
 * Two regions sharing a `region_code`, or a name, are neither of those things.
 * `s5` is on 60 regions and «البرناوي» names two of them (ids 207 and 208) —
 * both normal, both imported, because identity here is the source id and nothing
 * else.
 *
 * ## What the importer will not do
 *
 * **It never deletes, and never deactivates.** A city absent from a newer file
 * has not been withdrawn; it is absent. Inferring a withdrawal would retire
 * destinations because someone exported a filtered sheet, and orders reference
 * these rows. Withdrawal is `is_active`, and it is an operator's act.
 *
 * **It never touches `is_active` on a row that exists.** Insert sets it true;
 * update does not include the column. An import that reset it would undo an
 * operator's deliberate withdrawal every time the file was refreshed.
 *
 * **It never silently re-parents a region.** If region `27` is stored under
 * طرابلس and the file now puts it under بنغازي, that is rejected rather than
 * applied: the orders already pointing at it were placed against the first
 * reading, and moving the row rewrites them. A person decides that one.
 *
 * **It never invents a price.** An empty price is stored as null, which is the
 * four unpriced cities' real state, and `0.00` stays `0.00`. Prices are read as
 * text and padded as text — never through a float — so `30.00` cannot arrive as
 * `29.999999`.
 */
final class CityCatalogImporter
{
    /**
     * The header this catalog is defined by.
     *
     * All nine are required, by name rather than by position: a column order
     * that changed between exports would otherwise swap names for prices in
     * silence. Extra columns are ignored, because a source system adding a field
     * is not a reason to stop importing.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'city_id',
        'city_name',
        'is_region_required',
        'delivery_price_lyd',
        'city_darb_branch',
        'region_id',
        'region_name',
        'region_code',
        'region_darb_branch',
    ];

    /** A positive integer and nothing else. `0` is not an identifier. */
    private const SOURCE_ID = '/^[1-9][0-9]*$/';

    /** Unsigned, at most two decimals, and no exponent, sign or separator. */
    private const PRICE = '/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/';

    /** @var list<array{line: int|null, record: string, reason: string}> */
    private array $rejections = [];

    /**
     * Import the file, or report why each record could not be.
     *
     * @param  bool  $dryRun  Do every read, comparison and write, then roll back.
     *                        The counts are the ones a real run would produce,
     *                        which a read-only simulation could not promise.
     * @param  bool  $strict  Commit only if nothing was rejected.
     *
     * @throws CityCatalogImportFailure when the file, not a record, is the problem
     */
    public function import(string $path, bool $dryRun = false, bool $strict = false): CityCatalogImportReport
    {
        $this->rejections = [];

        $rows = $this->read($path);

        [$cities, $regions] = $this->fold($rows);

        $cityCounts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        $regionCounts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];

        DB::beginTransaction();

        try {
            $cityIds = $this->writeCities($cities, $cityCounts);
            $this->writeRegions($regions, $cityIds, $regionCounts);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        // Three reasons to keep the writes and one not to, decided after the
        // work so the counts describe what would have happened either way.
        $commit = ! $dryRun && ! ($strict && $this->rejections !== []);

        $commit ? DB::commit() : DB::rollBack();

        return new CityCatalogImportReport(
            cities: $cityCounts,
            regions: $regionCounts,
            rejections: $this->rejections,
            rowsRead: count($rows),
            committed: $commit,
        );
    }

    /**
     * Every data row of the file, keyed by column name, with its line number.
     *
     * The BOM is stripped before the header is parsed. Excel writes one, and a
     * header read with it attached has a first column called `\xEF\xBB\xBFcity_id`
     * — which is not `city_id`, so the file would be refused for lacking a column
     * it plainly has.
     *
     * @return list<array{line: int, values: array<string, string>}>
     *
     * @throws CityCatalogImportFailure
     */
    private function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new CityCatalogImportFailure("The catalog file [{$path}] does not exist or cannot be read.");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new CityCatalogImportFailure("The catalog file [{$path}] could not be opened.");
        }

        try {
            $bom = fread($handle, 3);

            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $header = fgetcsv($handle);

            if ($header === false || $header === [null]) {
                throw new CityCatalogImportFailure("The catalog file [{$path}] is empty.");
            }

            $header = array_map(static fn ($value) => trim((string) $value), $header);

            $missing = array_diff(self::COLUMNS, $header);

            if ($missing !== []) {
                throw new CityCatalogImportFailure(
                    'The catalog file is missing required column(s): '.implode(', ', $missing)
                    .'. Expected: '.implode(', ', self::COLUMNS).'.',
                );
            }

            $rows = [];
            $line = 1;

            while (($record = fgetcsv($handle)) !== false) {
                $line++;

                // A trailing newline, or a row of empty cells, is not a record
                // and is not worth a rejection.
                if ($record === [null] || array_filter($record, static fn ($v) => trim((string) $v) !== '') === []) {
                    continue;
                }

                $values = [];

                foreach ($header as $index => $column) {
                    $values[$column] = trim((string) ($record[$index] ?? ''));
                }

                $rows[] = ['line' => $line, 'values' => $values];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Fold rows into city and region records, rejecting what cannot be folded.
     *
     * Everything that can be decided from the file alone is decided here, before
     * the transaction opens: shape, encoding, identifiers, prices, and the file's
     * agreement with itself. Only the two questions that need the database —
     * does this record differ from the stored one, and has this region moved
     * city — are left for the write.
     *
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return array{0: array<int, array{line: int, attributes: array<string, mixed>}>, 1: array<int, array{line: int, sourceCityId: int, attributes: array<string, mixed>}>}
     */
    private function fold(array $rows): array
    {
        /** @var array<int, array{line: int, lines: list<int>, attributes: array<string, mixed>}> $cities */
        $cities = [];
        /** @var array<int, array{line: int, lines: list<int>, sourceCityId: int, attributes: array<string, mixed>}> $regions */
        $regions = [];

        // Collected rather than rejected on sight: a city is only contradictory
        // once a second, disagreeing row has been seen, and the rejection has to
        // name every line involved.
        $contradictoryCities = [];
        $contradictoryRegions = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $values = $row['values'];

            if (! $this->isUtf8($values)) {
                $this->reject($line, 'row', 'contains bytes that are not valid UTF-8');

                continue;
            }

            $city = $this->readCity($values, $line);

            if ($city === null) {
                // The city columns are unusable, so the region beside them has
                // no owner that can be named. Rejecting the row once, on the
                // city, is the whole story — a second rejection for the region
                // would double-count one defect.
                continue;
            }

            [$sourceCityId, $cityAttributes] = $city;

            if (isset($cities[$sourceCityId])) {
                if ($cities[$sourceCityId]['attributes'] !== $cityAttributes) {
                    $contradictoryCities[$sourceCityId] = true;
                }

                $cities[$sourceCityId]['lines'][] = $line;
            } else {
                $cities[$sourceCityId] = ['line' => $line, 'lines' => [$line], 'attributes' => $cityAttributes];
            }

            $region = $this->readRegion($values, $line, $sourceCityId);

            if ($region === false) {
                continue;
            }

            if ($region === null) {
                // A city-only row. The 84 cities with no regions.
                continue;
            }

            [$sourceRegionId, $regionAttributes] = $region;

            if (isset($regions[$sourceRegionId])) {
                if ($regions[$sourceRegionId]['attributes'] !== $regionAttributes
                    || $regions[$sourceRegionId]['sourceCityId'] !== $sourceCityId) {
                    $contradictoryRegions[$sourceRegionId] = true;
                }

                $regions[$sourceRegionId]['lines'][] = $line;
            } else {
                $regions[$sourceRegionId] = [
                    'line' => $line,
                    'lines' => [$line],
                    'sourceCityId' => $sourceCityId,
                    'attributes' => $regionAttributes,
                ];
            }
        }

        foreach (array_keys($contradictoryCities) as $sourceCityId) {
            $this->reject(
                null,
                "city {$sourceCityId}",
                'the file gives this city different values on different rows (lines '
                .implode(', ', $cities[$sourceCityId]['lines']).'), and no row is more right than another',
            );

            unset($cities[$sourceCityId]);
        }

        foreach (array_keys($contradictoryRegions) as $sourceRegionId) {
            $this->reject(
                null,
                "region {$sourceRegionId}",
                'the file gives this region different values or different cities on different rows (lines '
                .implode(', ', $regions[$sourceRegionId]['lines']).')',
            );

            unset($regions[$sourceRegionId]);
        }

        // A region whose city was rejected has nowhere to attach. Done after the
        // city rejections above so a city contradicted on its last row still
        // takes its regions with it.
        foreach ($regions as $sourceRegionId => $region) {
            if (! isset($cities[$region['sourceCityId']])) {
                $this->reject(
                    $region['line'],
                    "region {$sourceRegionId}",
                    "its city {$region['sourceCityId']} was itself rejected, so the region has no owner",
                );

                unset($regions[$sourceRegionId]);
            }
        }

        return [$cities, $regions];
    }

    /**
     * The city columns of one row, or null if they cannot be trusted.
     *
     * @return array{0: int, 1: array<string, mixed>}|null
     */
    private function readCity(array $values, int $line): ?array
    {
        $sourceCityId = $values['city_id'];

        if (preg_match(self::SOURCE_ID, $sourceCityId) !== 1) {
            $this->reject($line, 'row', "city_id [{$sourceCityId}] is not a positive integer");

            return null;
        }

        $sourceCityId = (int) $sourceCityId;

        if ($values['city_name'] === '') {
            $this->reject($line, "city {$sourceCityId}", 'city_name is empty');

            return null;
        }

        $required = $values['is_region_required'];

        if ($required !== '0' && $required !== '1') {
            $this->reject($line, "city {$sourceCityId}", "is_region_required [{$required}] is neither 0 nor 1");

            return null;
        }

        $price = $this->readPrice($values['delivery_price_lyd'], $line, "city {$sourceCityId}");

        if ($price === false) {
            return null;
        }

        return [$sourceCityId, [
            'name' => $values['city_name'],
            'delivery_price_lyd' => $price,
            'is_region_required' => $required === '1',
            // Derived from the id list on the model, re-derived on every import
            // so a change to that list takes effect on the next run. Unlike
            // `is_active`, this is not an operator's state for an import to
            // clobber — it is a property of which source record this is.
            'fulfilment_kind' => in_array($sourceCityId, DeliveryCity::OFFICE_PICKUP_SOURCE_CITY_IDS, true)
                ? FulfilmentKind::OfficePickup
                : FulfilmentKind::Delivery,
            'darb_branch' => $values['city_darb_branch'] === '' ? null : $values['city_darb_branch'],
        ]];
    }

    /**
     * The region columns of one row.
     *
     * Three outcomes, and they are genuinely different: an array is a region,
     * `null` is a row that legitimately has none, and `false` is a region that
     * was rejected. Returning `null` for the last would import a city-only row
     * where the file meant to name a region, losing it without a word.
     *
     * @return array{0: int, 1: array<string, mixed>}|null|false
     */
    private function readRegion(array $values, int $line, int $sourceCityId): array|null|false
    {
        $sourceRegionId = $values['region_id'];
        $name = $values['region_name'];

        if ($sourceRegionId === '') {
            if ($name !== '') {
                // Named but unidentified. It cannot be upserted — a second
                // import would have no way to recognise it and would insert it
                // again — so it is refused rather than given an invented id.
                $this->reject($line, 'region', "named [{$name}] but carries no region_id");

                return false;
            }

            return null;
        }

        if (preg_match(self::SOURCE_ID, $sourceRegionId) !== 1) {
            $this->reject($line, 'region', "region_id [{$sourceRegionId}] is not a positive integer");

            return false;
        }

        $sourceRegionId = (int) $sourceRegionId;

        if ($name === '') {
            $this->reject($line, "region {$sourceRegionId}", 'region_name is empty');

            return false;
        }

        return [$sourceRegionId, [
            'name' => $name,
            // Verbatim and unvalidated beyond emptiness. It is a label from
            // another system, it repeats across regions, and inventing a format
            // for it here would reject real data to enforce a rule nobody set.
            'region_code' => $values['region_code'] === '' ? null : $values['region_code'],
            'darb_branch' => $values['region_darb_branch'] === '' ? null : $values['region_darb_branch'],
        ]];
    }

    /**
     * A price as the database should hold it: null, or a string with exactly two
     * decimals.
     *
     * Text throughout. `(float) '0.07'` is not `0.07`, and a catalog that routed
     * money through a float would be wrong by a hundredth somewhere and have no
     * way to say where. Padding `'30.5'` to `'30.50'` is string work on digits
     * the regex has already proved are digits.
     *
     * Empty means *no price decided* and returns null — never `'0.00'`, which is
     * a different and deliberate answer (PLAN §4.3).
     *
     * @return string|null|false `false` is a rejection, `null` is a real absence
     */
    private function readPrice(string $raw, int $line, string $record): string|null|false
    {
        if ($raw === '') {
            return null;
        }

        if (preg_match(self::PRICE, $raw, $matches) !== 1) {
            $this->reject($line, $record, "delivery_price_lyd [{$raw}] is not an unsigned amount with at most two decimals");

            return false;
        }

        return $matches[1].'.'.str_pad($matches[2] ?? '', 2, '0');
    }

    /**
     * Upsert the cities and return `source_city_id => id`.
     *
     * The map is the point: regions arrive naming a source city id, and nothing
     * downstream should have to guess the internal key it became.
     *
     * @param  array<int, array{line: int, lines: list<int>, attributes: array<string, mixed>}>  $cities
     * @param  array{inserted: int, updated: int, unchanged: int}  $counts
     * @return array<int, int>
     */
    private function writeCities(array $cities, array &$counts): array
    {
        $existing = DeliveryCity::query()
            ->whereIn('source_city_id', array_keys($cities))
            ->get()
            ->keyBy('source_city_id');

        $ids = [];

        foreach ($cities as $sourceCityId => $city) {
            $attributes = $city['attributes'];
            $stored = $existing->get($sourceCityId);

            if ($stored === null) {
                // `is_active` is not in the payload: the column's default is
                // true, and a new destination is offered until someone says
                // otherwise.
                $created = DeliveryCity::create(['source_city_id' => $sourceCityId] + $attributes);

                $ids[$sourceCityId] = $created->id;
                $counts['inserted']++;

                continue;
            }

            $ids[$sourceCityId] = $stored->id;

            $stored->fill($attributes);

            if ($stored->isDirty()) {
                $stored->save();
                $counts['updated']++;
            } else {
                $counts['unchanged']++;
            }
        }

        return $ids;
    }

    /**
     * Upsert the regions, refusing any that would change city.
     *
     * The re-parenting check is the one validation that cannot be done from the
     * file: it is a disagreement between the file and what is already stored, so
     * it belongs here and not in the fold. It compares internal ids — the
     * region's stored `city_id` against the id its source city resolved to — so
     * a city that was renamed, or inserted on this very run, still compares
     * correctly.
     *
     * @param  array<int, array{line: int, lines: list<int>, sourceCityId: int, attributes: array<string, mixed>}>  $regions
     * @param  array<int, int>  $cityIds
     * @param  array{inserted: int, updated: int, unchanged: int}  $counts
     */
    private function writeRegions(array $regions, array $cityIds, array &$counts): void
    {
        $existing = DeliveryRegion::query()
            ->whereIn('source_region_id', array_keys($regions))
            ->get()
            ->keyBy('source_region_id');

        foreach ($regions as $sourceRegionId => $region) {
            $cityId = $cityIds[$region['sourceCityId']];
            $attributes = $region['attributes'];
            $stored = $existing->get($sourceRegionId);

            if ($stored === null) {
                DeliveryRegion::create([
                    'source_region_id' => $sourceRegionId,
                    'city_id' => $cityId,
                ] + $attributes);

                $counts['inserted']++;

                continue;
            }

            if ((int) $stored->city_id !== $cityId) {
                $this->reject(
                    $region['line'],
                    "region {$sourceRegionId}",
                    'the file moves this region to another city; it is stored under city id '
                    ."{$stored->city_id} and the file puts it under source city {$region['sourceCityId']} "
                    ."(id {$cityId}). Left untouched — orders may already reference it.",
                );

                continue;
            }

            $stored->fill($attributes);

            if ($stored->isDirty()) {
                $stored->save();
                $counts['updated']++;
            } else {
                $counts['unchanged']++;
            }
        }
    }

    /**
     * Whether every cell of a row is valid UTF-8.
     *
     * Checked before anything is parsed, because an Arabic name mangled by a
     * wrong-encoding export is not a name — it would import as a row of
     * replacement characters, match no later import, and be noticed by nobody.
     *
     * @param  array<string, string>  $values
     */
    private function isUtf8(array $values): bool
    {
        foreach ($values as $value) {
            if (! mb_check_encoding($value, 'UTF-8')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Record one refusal, with where it was and why.
     *
     * The line is null for a defect no single line owns — a city contradicted
     * across four rows is not line 7's fault — and the reason names the lines
     * instead.
     */
    private function reject(?int $line, string $record, string $reason): void
    {
        $this->rejections[] = ['line' => $line, 'record' => $record, 'reason' => $reason];
    }
}
