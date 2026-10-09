<?php

namespace App\Services\Catalog;

/**
 * What one import run did, and what it refused to do.
 *
 * Four counts per table, and `rejected` is the one that matters: a run that
 * reports 300 accepted rows and says nothing about the five it dropped is a run
 * that looks successful. Every rejection therefore also carries its line number
 * and the reason, so a source-file problem can be found in the source file.
 *
 * Counts are of *source records*, not of CSV rows. The file repeats a city's
 * columns on every one of its region rows — طرابلس appears 50 times — and
 * counting rows would report 50 cities where there is one.
 */
final class CityCatalogImportReport
{
    /**
     * @param  array{inserted: int, updated: int, unchanged: int}  $cities
     * @param  array{inserted: int, updated: int, unchanged: int}  $regions
     * @param  list<array{line: int|null, record: string, reason: string}>  $rejections
     */
    public function __construct(
        public readonly array $cities,
        public readonly array $regions,
        public readonly array $rejections,
        public readonly int $rowsRead,
        public readonly bool $committed,
    ) {}

    public function rejectedCount(): int
    {
        return count($this->rejections);
    }

    /**
     * Whether anything at all was written.
     *
     * Distinct from "whether the run succeeded". A second import of the same
     * file writes nothing and is a complete success; a dry run writes nothing
     * and proves nothing about the database.
     */
    public function wroteAnything(): bool
    {
        return $this->cities['inserted'] + $this->cities['updated']
            + $this->regions['inserted'] + $this->regions['updated'] > 0;
    }
}
