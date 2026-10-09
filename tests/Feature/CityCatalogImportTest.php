<?php

namespace Tests\Feature;

use App\Enums\FulfilmentKind;
use App\Models\DeliveryCity;
use App\Models\DeliveryRegion;
use App\Services\Catalog\CityCatalogImporter;
use App\Services\Catalog\CityCatalogImportFailure;
use App\Services\Catalog\CityCatalogImportReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Building the city and region catalog from the source file (PLAN D1 §4.2).
 *
 * The first group of cases runs the **real** `cities-regions-prices.csv` that
 * ships in `database/data`, not a reduced fixture, and asserts the numbers the
 * plan was approved on: 305 rows folding into 95 cities and 221 regions, nine
 * cities demanding a region, four with no price, طرابلس/السراج at `15.00`. A
 * trimmed fixture would pass while the real file failed — and the real file is
 * the one with the UTF-8 BOM, the Arabic names, the sixty regions sharing a
 * code, and the office-pickup row that is not a city.
 *
 * The second group builds small files on purpose, because the real one is
 * *correct* and correctness cannot demonstrate a refusal. Every rejection rule
 * is proved on a file written to contain exactly the defect under test.
 */
class CityCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The file the catalog is defined by.
     *
     * Versioned with the code rather than read from somebody's downloads: the
     * import is the catalog's only entry point, so the file it consumes is part
     * of the repository or the build is not reproducible.
     */
    private const SOURCE = 'database/data/cities-regions-prices.csv';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // The real source file
    // ---------------------------------------------------------------

    public function test_the_whole_source_file_imports_as_ninety_five_cities_and_two_hundred_twenty_one_regions(): void
    {
        $report = $this->importSource();

        // Rows are not records: the city columns repeat on every region row, so
        // 305 rows carry 95 cities between them.
        $this->assertSame(305, $report->rowsRead);
        $this->assertSame([], $report->rejections);

        $this->assertSame(95, $report->cities['inserted']);
        $this->assertSame(221, $report->regions['inserted']);
        $this->assertSame(0, $report->cities['updated'] + $report->regions['updated']);

        $this->assertSame(95, DeliveryCity::query()->count());
        $this->assertSame(221, DeliveryRegion::query()->count());

        // Every region found its city. A null `city_id` is impossible through
        // the column, so the real risk is a region silently dropped for having
        // no owner — which would show up here as a short count rather than an
        // error.
        $this->assertSame(221, DeliveryRegion::query()->whereNotNull('city_id')->count());
    }

    public function test_the_bom_on_the_source_header_does_not_hide_the_first_column(): void
    {
        // The file is an Excel export and begins with EF BB BF. Read naively, its
        // first header is `\xEF\xBB\xBFcity_id`, the required-column check fails,
        // and a perfectly good file is refused. Pinned because the byte is
        // invisible in every editor that would be used to inspect the file.
        $this->assertSame("\xEF\xBB\xBF", substr((string) file_get_contents(base_path(self::SOURCE)), 0, 3));

        $this->importSource();

        $this->assertSame(95, DeliveryCity::query()->count());
    }

    public function test_exactly_nine_cities_demand_a_region(): void
    {
        $this->importSource();

        $this->assertSame(9, DeliveryCity::query()->where('is_region_required', true)->count());
        $this->assertSame(86, DeliveryCity::query()->where('is_region_required', false)->count());

        // The nine by source id, so a tenth city quietly acquiring the flag is a
        // failure here rather than a surprise in the order form.
        $this->assertSame(
            [2, 3, 4, 5, 6, 10, 11, 12, 98],
            DeliveryCity::query()->where('is_region_required', true)
                ->orderBy('source_city_id')->pluck('source_city_id')->map('intval')->all(),
        );
    }

    public function test_tripoli_and_al_sarraj_arrive_with_their_source_ids_and_the_city_price(): void
    {
        $this->importSource();

        $region = DeliveryRegion::query()->where('source_region_id', 27)->sole();

        $this->assertSame('السراج', $region->name);
        $this->assertSame('s24', $region->region_code);

        $city = $region->city;

        $this->assertSame(2, (int) $city->source_city_id);
        $this->assertSame('طرابلس', $city->name);
        $this->assertTrue($city->is_region_required);

        // The price is the city's. The source file prices no region, and the
        // region must not have acquired one.
        $this->assertSame('15.00', $city->delivery_price_lyd);
        $this->assertFalse(array_key_exists('delivery_price_lyd', $region->getAttributes()));
    }

    public function test_the_four_unpriced_cities_are_stored_as_null(): void
    {
        $this->importSource();

        $unpriced = DeliveryCity::query()->whereNull('delivery_price_lyd')
            ->orderBy('source_city_id')->get();

        $this->assertSame([97, 98, 99, 100], $unpriced->pluck('source_city_id')->map('intval')->all());
        $this->assertSame(
            ['تساوة', 'ضواحي الزاوية', 'ضواحي صبراتة', 'هراوة'],
            $unpriced->pluck('name')->all(),
        );

        foreach ($unpriced as $city) {
            $this->assertNull($city->delivery_price_lyd);
            $this->assertFalse($city->hasDecidedPrice());
        }
    }

    public function test_a_deliberate_zero_price_stays_different_from_no_price(): void
    {
        $this->importSource();

        // «إستلام مكتب» is the one record the file prices at exactly 0.00, and it
        // is a decision. The four above are the absence of a decision. If these
        // two ever merge, four unpriced cities become four free deliveries.
        $officePickup = DeliveryCity::query()->where('source_city_id', 1)->sole();

        $this->assertSame('0.00', $officePickup->delivery_price_lyd);
        $this->assertTrue($officePickup->hasDecidedPrice());
        $this->assertNotNull($officePickup->delivery_price_lyd);

        $this->assertSame(1, DeliveryCity::query()->where('delivery_price_lyd', '0.00')->count());
        $this->assertSame(4, DeliveryCity::query()->whereNull('delivery_price_lyd')->count());

        // The two states are distinguishable in SQL, not merely in PHP — a
        // `WHERE price = 0` must not find the unpriced four.
        $this->assertSame(
            [1],
            DeliveryCity::query()->where('delivery_price_lyd', 0)
                ->pluck('source_city_id')->map('intval')->all(),
        );
    }

    public function test_office_pickup_is_preserved_but_not_offered_as_a_delivery_destination(): void
    {
        $this->importSource();

        $officePickup = DeliveryCity::query()->where('source_city_id', 1)->sole();

        // Preserved in full: the row, its name, its region, its price.
        $this->assertSame('إستلام مكتب', $officePickup->name);
        $this->assertSame(FulfilmentKind::OfficePickup, $officePickup->fulfilment_kind);
        $this->assertSame(1, $officePickup->regions()->count());

        // Not deactivated — that would record an operator's withdrawal that
        // never happened — and still kept out of the destination list.
        $this->assertTrue($officePickup->is_active);

        $selectable = DeliveryCity::query()->selectableForDelivery()->pluck('source_city_id')->map('intval')->all();

        $this->assertCount(94, $selectable);
        $this->assertNotContains(1, $selectable);

        // It is the only such record in the file; nothing else was swept up.
        $this->assertSame(1, DeliveryCity::query()
            ->where('fulfilment_kind', FulfilmentKind::OfficePickup)->count());
    }

    public function test_optional_regions_remain_available_for_selection(): void
    {
        $this->importSource();

        // «ضواحي صبراتة» lists seven regions and requires none of them. Having
        // regions is not the same question as demanding one, and an importer
        // that inferred the flag from the presence of regions would make this
        // city mandatory and lock out orders that legitimately have no region.
        $suburbs = DeliveryCity::query()->where('source_city_id', 99)->sole();

        $this->assertFalse($suburbs->is_region_required);
        $this->assertSame(7, $suburbs->regions()->count());

        $selectable = DeliveryRegion::query()->selectableForCity($suburbs->id)->get();

        $this->assertCount(7, $selectable);
        $this->assertTrue($selectable->every(fn (DeliveryRegion $r) => $r->is_active));
        $this->assertTrue($selectable->every(fn (DeliveryRegion $r) => $r->belongsToCity($suburbs->id)));

        // And the regions of an optional city are not confused with another
        // city's: selecting for صبراتة (source 11) returns only its own.
        $sabratha = DeliveryCity::query()->where('source_city_id', 11)->sole();

        $this->assertSame(
            ['وسط صبراتة'],
            DeliveryRegion::query()->selectableForCity($sabratha->id)->pluck('name')->all(),
        );
    }

    public function test_regions_sharing_a_region_code_do_not_collide(): void
    {
        $this->importSource();

        // `s5` is on 60 regions and `s48` on 51, and 48 regions have no code at
        // all. Each of those is one row with its own identity; a schema that
        // treated the code as a key would have imported one of each group.
        $this->assertSame(60, DeliveryRegion::query()->where('region_code', 's5')->count());
        $this->assertSame(51, DeliveryRegion::query()->where('region_code', 's48')->count());
        $this->assertSame(48, DeliveryRegion::query()->whereNull('region_code')->count());
        $this->assertSame(11, DeliveryRegion::query()->distinct()->count('region_code'));

        // The 60 are distinct records, with 60 distinct source ids.
        $shared = DeliveryRegion::query()->where('region_code', 's5')->get();

        $this->assertCount(60, $shared->pluck('source_region_id')->unique());
        $this->assertCount(60, $shared->pluck('id')->unique());

        // A code is not even unique *within* one city, so it can never be used
        // to find "the" region: all 60 of مصراتة's regions carry `s5`.
        $misrata = DeliveryCity::query()->where('source_city_id', 6)->sole();

        $this->assertSame(60, $misrata->regions()->where('region_code', 's5')->count());
    }

    public function test_two_regions_of_one_city_may_share_a_name(): void
    {
        $this->importSource();

        // «ضواحي الزاوية» lists البرناوي twice, under source ids 207 and 208, and
        // قرية ناصر twice under 222 and 223. Four real records the source file
        // chose to keep apart. Deduplicating by name here would silently discard
        // two of them, and no later import would bring them back.
        $city = DeliveryCity::query()->where('source_city_id', 98)->sole();

        $this->assertSame(
            [207, 208],
            $city->regions()->where('name', 'البرناوي')->orderBy('source_region_id')
                ->pluck('source_region_id')->map('intval')->all(),
        );
        $this->assertSame(
            [222, 223],
            $city->regions()->where('name', 'قرية ناصر')->orderBy('source_region_id')
                ->pluck('source_region_id')->map('intval')->all(),
        );
    }

    public function test_prices_keep_the_precision_the_file_gave_them(): void
    {
        $this->importSource();

        // Read back from the database as text. A price that travelled through a
        // float would be right to the hundredth here and wrong somewhere nobody
        // is looking.
        $prices = DeliveryCity::query()->whereNotNull('delivery_price_lyd')
            ->pluck('delivery_price_lyd', 'source_city_id');

        $this->assertSame('15.00', $prices[2]);
        $this->assertSame('25.00', $prices[4]);
        $this->assertSame('50.00', $prices[95]);
        $this->assertSame('0.00', $prices[1]);

        foreach ($prices as $price) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $price);
        }
    }

    public function test_a_second_import_of_the_same_file_changes_nothing(): void
    {
        $this->importSource();

        $cityIds = DeliveryCity::query()->orderBy('id')->pluck('id')->all();
        $regionIds = DeliveryRegion::query()->orderBy('id')->pluck('id')->all();

        $second = $this->importSource();

        $this->assertSame(95, $second->cities['unchanged']);
        $this->assertSame(221, $second->regions['unchanged']);
        $this->assertSame(0, $second->cities['inserted'] + $second->regions['inserted']);
        $this->assertSame(0, $second->cities['updated'] + $second->regions['updated']);
        $this->assertFalse($second->wroteAnything());

        // No duplicates, and the same rows: an import that deleted and recreated
        // would report the same counts while breaking every order's foreign key.
        $this->assertSame(95, DeliveryCity::query()->count());
        $this->assertSame(221, DeliveryRegion::query()->count());
        $this->assertSame($cityIds, DeliveryCity::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame($regionIds, DeliveryRegion::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_a_dry_run_reports_the_real_counts_and_writes_nothing(): void
    {
        $report = $this->importer()->import(base_path(self::SOURCE), dryRun: true);

        $this->assertSame(95, $report->cities['inserted']);
        $this->assertSame(221, $report->regions['inserted']);
        $this->assertFalse($report->committed);

        $this->assertSame(0, DeliveryCity::query()->count());
        $this->assertSame(0, DeliveryRegion::query()->count());
    }

    public function test_the_import_command_runs_the_whole_file_and_reports_success(): void
    {
        $this->artisan('delivery:import-city-catalog', ['file' => base_path(self::SOURCE)])
            ->assertExitCode(0);

        $this->assertSame(95, DeliveryCity::query()->count());
        $this->assertSame(221, DeliveryRegion::query()->count());
    }

    // ---------------------------------------------------------------
    // Updates, and what an import must not touch
    // ---------------------------------------------------------------

    public function test_a_changed_price_updates_the_city_and_counts_as_one_update(): void
    {
        $this->importRows([
            [2, 'طرابلس', 1, '15.00', 'زناتة، طرابلس', 27, 'السراج', 's24', 'السراج، طرابلس'],
        ]);

        $report = $this->importRows([
            [2, 'طرابلس', 1, '17.50', 'زناتة، طرابلس', 27, 'السراج', 's24', 'السراج، طرابلس'],
        ]);

        $this->assertSame(1, $report->cities['updated']);
        $this->assertSame(0, $report->cities['inserted']);
        // The region did not change, and so was not rewritten.
        $this->assertSame(1, $report->regions['unchanged']);

        $this->assertSame('17.50', DeliveryCity::query()->where('source_city_id', 2)->value('delivery_price_lyd'));
    }

    public function test_a_city_absent_from_a_later_file_is_neither_deleted_nor_deactivated(): void
    {
        $this->importRows([
            [2, 'طرابلس', 0, '15.00', '', '', '', '', ''],
            [4, 'بنغازي', 0, '25.00', '', '', '', '', ''],
        ]);

        // A filtered export, a partial sheet, a city temporarily omitted — none
        // of those is a decision to retire a destination, and orders reference
        // these rows.
        $report = $this->importRows([
            [2, 'طرابلس', 0, '15.00', '', '', '', '', ''],
        ]);

        $this->assertSame([], $report->rejections);
        $this->assertSame(2, DeliveryCity::query()->count());

        $benghazi = DeliveryCity::query()->where('source_city_id', 4)->sole();

        $this->assertTrue($benghazi->is_active);
        $this->assertSame('25.00', $benghazi->delivery_price_lyd);
    }

    public function test_an_operator_deactivation_survives_a_reimport(): void
    {
        $this->importRows([[2, 'طرابلس', 0, '15.00', '', '', '', '', '']]);

        DeliveryCity::query()->where('source_city_id', 2)->update(['is_active' => false]);

        // `is_active` is this company's column. The file has no opinion on it,
        // and an import that reset it would undo a deliberate withdrawal every
        // time the catalog was refreshed.
        $report = $this->importRows([[2, 'طرابلس', 0, '15.00', '', '', '', '', '']]);

        $this->assertSame(1, $report->cities['unchanged']);
        $this->assertFalse(DeliveryCity::query()->where('source_city_id', 2)->sole()->is_active);
    }

    public function test_a_region_is_never_silently_moved_to_another_city(): void
    {
        $this->importRows([
            [2, 'طرابلس', 1, '15.00', '', 27, 'السراج', 's24', ''],
            [4, 'بنغازي', 0, '25.00', '', '', '', '', ''],
        ]);

        $tripoli = DeliveryCity::query()->where('source_city_id', 2)->sole();

        // The file now claims region 27 belongs to بنغازي. Orders may already
        // point at that region having been told it was in طرابلس, so applying
        // the move would rewrite them. It is refused and reported instead.
        $report = $this->importRows([
            [4, 'بنغازي', 0, '25.00', '', 27, 'السراج', 's24', ''],
        ]);

        $this->assertCount(1, $report->rejections);
        $this->assertStringContainsString('moves this region to another city', $report->rejections[0]['reason']);
        $this->assertSame('region 27', $report->rejections[0]['record']);

        $region = DeliveryRegion::query()->where('source_region_id', 27)->sole();

        $this->assertSame($tripoli->id, (int) $region->city_id);
        $this->assertSame(0, $report->regions['updated']);
    }

    // ---------------------------------------------------------------
    // Refusals
    // ---------------------------------------------------------------

    public function test_a_city_the_file_contradicts_itself_about_is_rejected_whole(): void
    {
        // Two rows, one city, two prices. Neither row is more right than the
        // other, so choosing either would publish a price nobody decided.
        $report = $this->importRows([
            [6, 'مصراتة', 1, '20.00', '', 129, 'زاوية المحجوب', 's5', ''],
            [6, 'مصراتة', 1, '35.00', '', 142, 'زريق', 's5', ''],
        ]);

        $this->assertSame(0, $report->cities['inserted']);
        $this->assertSame(0, $report->regions['inserted']);
        $this->assertSame(0, DeliveryCity::query()->count());
        $this->assertSame(0, DeliveryRegion::query()->count());

        $reasons = array_column($report->rejections, 'reason');

        $this->assertStringContainsString('different values on different rows', $reasons[0]);
        $this->assertStringContainsString('lines 2, 3', $reasons[0]);

        // Both of its regions go with it: a region whose city was refused has
        // no owner to attach to, and inventing one would be worse than losing
        // the row.
        $records = array_column($report->rejections, 'record');

        $this->assertContains('city 6', $records);
        $this->assertContains('region 129', $records);
        $this->assertContains('region 142', $records);
    }

    public function test_a_region_id_repeated_with_different_contents_is_rejected(): void
    {
        $report = $this->importRows([
            [2, 'طرابلس', 1, '15.00', '', 27, 'السراج', 's24', ''],
            [2, 'طرابلس', 1, '15.00', '', 27, 'الفرناج', 's21', ''],
        ]);

        // The city agrees with itself and lands; the region does not and does
        // not.
        $this->assertSame(1, $report->cities['inserted']);
        $this->assertSame(0, $report->regions['inserted']);
        $this->assertSame(0, DeliveryRegion::query()->count());

        $this->assertCount(1, $report->rejections);
        $this->assertSame('region 27', $report->rejections[0]['record']);
    }

    public function test_a_region_row_repeated_identically_is_imported_once(): void
    {
        // The format invites repetition — the city's columns repeat on every
        // region row — so an exactly duplicated region row is not a
        // contradiction. It is the same record stated twice.
        $report = $this->importRows([
            [2, 'طرابلس', 1, '15.00', '', 27, 'السراج', 's24', 'السراج، طرابلس'],
            [2, 'طرابلس', 1, '15.00', '', 27, 'السراج', 's24', 'السراج، طرابلس'],
        ]);

        $this->assertSame([], $report->rejections);
        $this->assertSame(1, $report->cities['inserted']);
        $this->assertSame(1, $report->regions['inserted']);
        $this->assertSame(1, DeliveryRegion::query()->count());
    }

    public function test_malformed_identifiers_names_and_prices_are_rejected_row_by_row(): void
    {
        $report = $this->importRows([
            [2, 'طرابلس', 1, '15.00', '', 27, 'السراج', 's24', ''],   // good
            ['x', 'مدينة', 0, '10.00', '', '', '', '', ''],            // city_id not a number
            [0, 'مدينة', 0, '10.00', '', '', '', '', ''],              // zero is not an identifier
            [20, '', 0, '10.00', '', '', '', '', ''],                  // no city name
            [21, 'مدينة', 2, '10.00', '', '', '', '', ''],             // is_region_required neither 0 nor 1
            [22, 'مدينة', 0, '-5.00', '', '', '', '', ''],             // negative price
            [23, 'مدينة', 0, '10.001', '', '', '', '', ''],            // three decimals
            // The last three repeat طرابلس verbatim, so only their region
            // columns are at fault and the city stays consistent with itself.
            [2, 'طرابلس', 1, '15.00', '', 'abc', 'منطقة', 's1', ''],   // region_id not a number
            [2, 'طرابلس', 1, '15.00', '', 300, '', 's1', ''],          // region with no name
            [2, 'طرابلس', 1, '15.00', '', '', 'منطقة بلا معرف', '', ''], // named region, no id
        ]);

        // One good record in, nine refusals out, and the good one is unaffected
        // by its neighbours.
        $this->assertSame(1, $report->cities['inserted']);
        $this->assertSame(1, $report->regions['inserted']);
        $this->assertSame(9, $report->rejectedCount());

        $this->assertSame([2], DeliveryCity::query()->pluck('source_city_id')->map('intval')->all());
        $this->assertSame([27], DeliveryRegion::query()->pluck('source_region_id')->map('intval')->all());

        $reasons = implode("\n", array_column($report->rejections, 'reason'));

        $this->assertStringContainsString('city_id [x] is not a positive integer', $reasons);
        $this->assertStringContainsString('city_id [0] is not a positive integer', $reasons);
        $this->assertStringContainsString('city_name is empty', $reasons);
        $this->assertStringContainsString('is_region_required [2] is neither 0 nor 1', $reasons);
        $this->assertStringContainsString('delivery_price_lyd [-5.00]', $reasons);
        $this->assertStringContainsString('delivery_price_lyd [10.001]', $reasons);
        $this->assertStringContainsString('region_id [abc] is not a positive integer', $reasons);
        $this->assertStringContainsString('region_name is empty', $reasons);
        $this->assertStringContainsString('carries no region_id', $reasons);

        // Every rejection can be found in the file it came from.
        foreach ($report->rejections as $rejection) {
            $this->assertNotNull($rejection['line']);
            $this->assertGreaterThanOrEqual(2, $rejection['line']);
        }
    }

    public function test_cities_with_unusable_columns_do_not_import_their_regions_either(): void
    {
        // The region names its city on the same row. If those columns are
        // unusable the region has no owner that can be named, so it cannot be
        // imported — and must not be attached to a guess.
        $report = $this->importRows([
            ['', 'مدينة', 0, '10.00', '', 400, 'منطقة', 's1', ''],
        ]);

        $this->assertSame(0, DeliveryCity::query()->count());
        $this->assertSame(0, DeliveryRegion::query()->count());
        $this->assertSame(1, $report->rejectedCount());
    }

    public function test_strict_mode_rolls_the_whole_import_back_when_anything_is_rejected(): void
    {
        $report = $this->importRows([
            [2, 'طرابلس', 1, '15.00', '', 27, 'السراج', 's24', ''],
            [20, '', 0, '10.00', '', '', '', '', ''],
        ], strict: true);

        // The counts still describe what a lenient run would have written —
        // that is the point of reporting them — but nothing was kept.
        $this->assertSame(1, $report->cities['inserted']);
        $this->assertSame(1, $report->rejectedCount());
        $this->assertFalse($report->committed);

        $this->assertSame(0, DeliveryCity::query()->count());
        $this->assertSame(0, DeliveryRegion::query()->count());
    }

    public function test_without_strict_mode_the_accepted_records_are_committed(): void
    {
        // The counterpart of the case above, and the default: one bad row in a
        // 305-row export must not block a catalog refresh.
        $report = $this->importRows([
            [2, 'طرابلس', 1, '15.00', '', 27, 'السراج', 's24', ''],
            [20, '', 0, '10.00', '', '', '', '', ''],
        ]);

        $this->assertTrue($report->committed);
        $this->assertSame(1, $report->rejectedCount());
        $this->assertSame(1, DeliveryCity::query()->count());
    }

    public function test_a_file_missing_a_required_column_is_refused_whole(): void
    {
        $path = $this->writeCsv("city_id,city_name,delivery_price_lyd\n2,طرابلس,15.00\n");

        $this->expectException(CityCatalogImportFailure::class);
        $this->expectExceptionMessage('missing required column(s): is_region_required');

        $this->importer()->import($path);
    }

    public function test_an_empty_file_is_refused_whole(): void
    {
        $path = $this->writeCsv('');

        $this->expectException(CityCatalogImportFailure::class);
        $this->expectExceptionMessage('is empty');

        $this->importer()->import($path);
    }

    public function test_a_missing_file_is_refused_whole(): void
    {
        $this->expectException(CityCatalogImportFailure::class);

        $this->importer()->import(base_path('database/data/no-such-catalog.csv'));
    }

    public function test_a_column_order_the_export_changed_is_still_read_correctly(): void
    {
        // Columns are matched by name. Positional reading would put a price
        // where a name belongs and import it without complaint.
        $path = $this->writeCsv(
            "region_name,city_name,region_id,city_id,delivery_price_lyd,is_region_required,city_darb_branch,region_code,region_darb_branch\n"
            ."السراج,طرابلس,27,2,15.00,1,زناتة،طرابلس,s24,السراج،طرابلس\n",
        );

        $report = $this->importer()->import($path);

        $this->assertSame([], $report->rejections);
        $this->assertSame('طرابلس', DeliveryCity::query()->where('source_city_id', 2)->value('name'));
        $this->assertSame('السراج', DeliveryRegion::query()->where('source_region_id', 27)->value('name'));
    }

    public function test_a_row_that_is_not_valid_utf8_is_rejected_rather_than_stored_mangled(): void
    {
        // A wrong-encoding export: Arabic bytes in CP1256. Stored as-is they
        // become a row of replacement characters that matches no later import
        // and is noticed by nobody.
        $path = $this->writeCsv(
            implode(',', CityCatalogImporter::COLUMNS)."\n"
            ."2,\xD8\xB1\xC7\xC8,0,15.00,,,,,\n"
            ."4,بنغازي,0,25.00,,,,,\n",
        );

        $report = $this->importer()->import($path);

        $this->assertSame(1, $report->rejectedCount());
        $this->assertStringContainsString('not valid UTF-8', $report->rejections[0]['reason']);
        $this->assertSame([4], DeliveryCity::query()->pluck('source_city_id')->map('intval')->all());
    }

    public function test_blank_rows_are_skipped_without_being_rejected(): void
    {
        $path = $this->writeCsv(
            implode(',', CityCatalogImporter::COLUMNS)."\n"
            ."2,طرابلس,0,15.00,,,,,\n"
            .",,,,,,,,\n"
            ."\n",
        );

        $report = $this->importer()->import($path);

        $this->assertSame([], $report->rejections);
        $this->assertSame(1, $report->rowsRead);
        $this->assertSame(1, DeliveryCity::query()->count());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function importer(): CityCatalogImporter
    {
        return app(CityCatalogImporter::class);
    }

    private function importSource(): CityCatalogImportReport
    {
        return $this->importer()->import(base_path(self::SOURCE));
    }

    /**
     * Import a handful of rows written in the source file's own column order.
     *
     * @param  list<list<string|int>>  $rows
     */
    private function importRows(array $rows, bool $strict = false): CityCatalogImportReport
    {
        $csv = implode(',', CityCatalogImporter::COLUMNS)."\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map(
                static fn ($value) => str_contains((string) $value, ',')
                    ? '"'.(string) $value.'"'
                    : (string) $value,
                $row,
            ))."\n";
        }

        return $this->importer()->import($this->writeCsv($csv), strict: $strict);
    }

    private function writeCsv(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'catalog');

        file_put_contents($path, $contents);

        $this->temporaryFiles[] = $path;

        return $path;
    }
}
