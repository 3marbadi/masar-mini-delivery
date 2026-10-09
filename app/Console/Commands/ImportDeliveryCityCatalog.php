<?php

namespace App\Console\Commands;

use App\Services\Catalog\CityCatalogImporter;
use App\Services\Catalog\CityCatalogImportFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds or refreshes the city and region catalog from the source CSV.
 *
 * A thin shell around {@see CityCatalogImporter}: it resolves the path, prints
 * what happened, and decides the exit code. Every rule about what may be
 * imported lives in the service, so the import can be exercised by a test
 * without going through a console.
 *
 * Safe to run repeatedly — that is the design, not a tolerance. A second run of
 * the same file reports every record unchanged and writes nothing.
 *
 * **Why rejections fail the command.** A run that drops five records and exits
 * `0` is a run a deployment script treats as a success, and the five records are
 * then missing from a catalog everyone believes is complete. Every rejection is
 * a source-file defect that needs a person, so it is surfaced the one way
 * automation cannot ignore. The accepted records are still committed; the exit
 * code reports that the file was not fully importable, not that nothing
 * happened.
 */
class ImportDeliveryCityCatalog extends Command
{
    protected $signature = 'delivery:import-city-catalog
        {file : Path to the cities/regions CSV.}
        {--dry-run : Do the whole import and roll it back. Reports the counts a real run would produce.}
        {--strict : Commit only if nothing was rejected, instead of committing the records that were accepted.}';

    protected $description = 'Import the delivery city and region catalog, with its prices, from the source CSV.';

    public function handle(CityCatalogImporter $importer): int
    {
        $file = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');
        $strict = (bool) $this->option('strict');

        $this->line('Database : '.DB::connection()->getDatabaseName());
        $this->line('File     : '.$file);
        $this->line('Mode     : '.($dryRun ? 'DRY RUN (rolled back)' : 'APPLY').($strict ? ' + STRICT' : ''));
        $this->newLine();

        try {
            $report = $importer->import($file, dryRun: $dryRun, strict: $strict);
        } catch (CityCatalogImportFailure $e) {
            // The file, not a record. Nothing was written, and nothing about the
            // catalog can be inferred from this run.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['', 'inserted', 'updated', 'unchanged'],
            [
                ['cities', $report->cities['inserted'], $report->cities['updated'], $report->cities['unchanged']],
                ['regions', $report->regions['inserted'], $report->regions['updated'], $report->regions['unchanged']],
            ],
        );

        $this->line("Rows read: {$report->rowsRead}   Rejected records: {$report->rejectedCount()}");

        if ($report->rejections !== []) {
            $this->newLine();
            $this->warn('Rejected — each of these needs a correction in the source file:');

            foreach ($report->rejections as $rejection) {
                $where = $rejection['line'] === null ? 'several lines' : 'line '.$rejection['line'];

                $this->line(sprintf('  %-14s %-16s %s', $where, $rejection['record'], $rejection['reason']));
            }
        }

        $this->newLine();

        if (! $report->committed) {
            $this->warn($dryRun
                ? 'Dry run: everything above was rolled back. Nothing was written.'
                : 'Strict mode: records were rejected, so the whole import was rolled back. Nothing was written.');
        } else {
            $this->info($report->wroteAnything()
                ? 'Committed.'
                : 'Committed: the catalog already matched the file, so nothing needed writing.');
        }

        return $report->rejections === [] ? self::SUCCESS : self::FAILURE;
    }
}
