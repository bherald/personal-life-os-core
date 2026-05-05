<?php

namespace App\Console\Commands;

use App\Services\Genealogy\SourceRegistryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * GEN-1: Seed genealogy_source_registry from RepositoryRoutingService data.
 *
 * Usage:
 *   php artisan genealogy:source-registry --seed     # Populate from routing matrix
 *   php artisan genealogy:source-registry --stats     # Show registry statistics
 *   php artisan genealogy:source-registry --list      # List all entries
 *   php artisan genealogy:source-registry --validate  # Validate manual/public automation posture
 */
class GenealogySourceRegistrySeed extends Command
{
    protected $signature = 'genealogy:source-registry {--seed : Seed from routing matrix} {--stats : Show statistics} {--list : List all entries} {--validate : Validate manual/public automation posture}';

    protected $description = 'GEN-1: Manage genealogy source registry';

    public function handle(): int
    {
        if ($this->option('validate')) {
            return $this->validatePosture();
        }

        if ($this->option('stats')) {
            return $this->showStats();
        }

        if ($this->option('list')) {
            return $this->listEntries();
        }

        if ($this->option('seed')) {
            return $this->seed();
        }

        $this->info('Usage: --seed, --stats, --list, or --validate');

        return 0;
    }

    private function validatePosture(): int
    {
        $result = app(SourceRegistryService::class)->validatePublicSourcePosture();
        $summary = $result['summary'] ?? ['checked' => 0, 'errors' => 0];
        $this->line(sprintf(
            'Genealogy source registry posture: checked=%d errors=%d valid=%s',
            (int) ($summary['checked'] ?? 0),
            (int) ($summary['errors'] ?? 0),
            ! empty($result['valid']) ? 'yes' : 'no',
        ));

        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        if ($errors !== []) {
            $this->table(
                ['Archive', 'Domain', 'Tool', 'Code'],
                array_map(
                    fn (array $error): array => [
                        $error['archive_name'] ?? '',
                        $error['domain'] ?? '',
                        $error['tool_name'] ?? '',
                        $error['code'] ?? '',
                    ],
                    $errors
                )
            );
        }

        return ! empty($result['valid']) ? 0 : 1;
    }

    private function seed(): int
    {
        $this->info('Seeding genealogy_source_registry...');

        $entries = $this->buildEntries();
        $inserted = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $exists = DB::selectOne(
                'SELECT id FROM genealogy_source_registry WHERE archive_name = ? AND JSON_CONTAINS(eras, ?) AND JSON_CONTAINS(regions, ?)',
                [$entry['archive_name'], json_encode($entry['eras'][0] ?? 'all'), json_encode($entry['regions'][0] ?? 'all')]
            );

            if ($exists) {
                $skipped++;

                continue;
            }

            DB::insert('
                INSERT INTO genealogy_source_registry
                (archive_name, archive_url, record_types, eras, regions, ethnicities, tool_name, priority, coverage_start_year, coverage_end_year, access_type, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ', [
                $entry['archive_name'],
                $entry['archive_url'],
                json_encode($entry['record_types']),
                json_encode($entry['eras']),
                json_encode($entry['regions']),
                json_encode($entry['ethnicities']),
                $entry['tool_name'],
                $entry['priority'],
                $entry['coverage_start_year'],
                $entry['coverage_end_year'],
                $entry['access_type'],
                $entry['notes'],
            ]);
            $inserted++;
        }

        $this->info("Done: {$inserted} inserted, {$skipped} skipped (already exist)");

        return 0;
    }

    private function showStats(): int
    {
        $stats = app(\App\Services\Genealogy\SourceRegistryService::class)->getStatistics();
        $this->table(['Metric', 'Value'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->toArray());

        return 0;
    }

    private function listEntries(): int
    {
        $entries = DB::select('SELECT archive_name, tool_name, priority, access_type, search_count, hit_count FROM genealogy_source_registry WHERE is_active = 1 ORDER BY priority');
        $this->table(['Archive', 'Tool', 'Pri', 'Access', 'Searches', 'Hits'],
            array_map(fn ($e) => [$e->archive_name, $e->tool_name ?? '-', $e->priority, $e->access_type, $e->search_count, $e->hit_count], $entries));

        return 0;
    }

    /**
     * Build the seed entries from the RepositoryRoutingService knowledge.
     */
    private function buildEntries(): array
    {
        return [
            // === ALWAYS (global) ===
            ['archive_name' => 'FamilySearch', 'archive_url' => 'https://www.familysearch.org/search', 'record_types' => ['vital', 'census', 'church', 'military', 'immigration'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => null, 'priority' => 1, 'coverage_start_year' => 1500, 'coverage_end_year' => 2020, 'access_type' => 'free', 'notes' => 'Manual/browser-only source; no supported PLOS API integration'],
            ['archive_name' => 'Ancestry.com', 'archive_url' => 'https://www.ancestry.com/search', 'record_types' => ['census', 'vital', 'military', 'immigration', 'newspaper'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => null, 'priority' => 2, 'coverage_start_year' => 1500, 'coverage_end_year' => 2020, 'access_type' => 'subscription', 'notes' => 'Largest paid database; check library free access'],
            ['archive_name' => 'WikiTree', 'archive_url' => 'https://www.wikitree.com', 'record_types' => ['family_tree'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => 'wikitree_search', 'priority' => 7, 'coverage_start_year' => null, 'coverage_end_year' => null, 'access_type' => 'free', 'notes' => 'Collaborative tree; cross-reference with sources'],
            ['archive_name' => 'FindAGrave', 'archive_url' => 'https://www.findagrave.com', 'record_types' => ['cemetery', 'death'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => null, 'priority' => 8, 'coverage_start_year' => null, 'coverage_end_year' => null, 'access_type' => 'free', 'notes' => 'Cemetery records, death dates, photos'],

            // === ERA: Colonial ===
            ['archive_name' => 'NEHGS (Colonial)', 'archive_url' => 'https://www.americanancestors.org', 'record_types' => ['vital', 'church', 'land'], 'eras' => ['colonial'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1600, 'coverage_end_year' => 1776, 'access_type' => 'subscription', 'notes' => 'Manual membership lookup; best for pre-1776 New England vital records, church records, probate'],
            ['archive_name' => 'Internet Archive (Colonial)', 'archive_url' => 'https://archive.org', 'record_types' => ['church', 'land', 'probate'], 'eras' => ['colonial'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1600, 'coverage_end_year' => 1776, 'access_type' => 'free', 'notes' => 'Digitized colonial church registers, land records'],
            ['archive_name' => 'Chronicling America (Colonial)', 'archive_url' => 'https://chroniclingamerica.loc.gov', 'record_types' => ['newspaper'], 'eras' => ['colonial'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'newspaper_search', 'priority' => 7, 'coverage_start_year' => 1690, 'coverage_end_year' => 1776, 'access_type' => 'free', 'notes' => 'Colonial newspapers; limited but available for some regions'],

            // === ERA: Revolutionary ===
            ['archive_name' => 'DAR Genealogical Records', 'archive_url' => 'https://www.dar.org/library/genealogy', 'record_types' => ['military', 'vital'], 'eras' => ['revolutionary'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'dar_search', 'priority' => 3, 'coverage_start_year' => 1775, 'coverage_end_year' => 1800, 'access_type' => 'free', 'notes' => 'Revolutionary War service records and lineage papers'],
            ['archive_name' => 'NARA Revolutionary War', 'archive_url' => 'https://www.archives.gov/research/military/american-revolution', 'record_types' => ['military'], 'eras' => ['revolutionary'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'nara_search', 'priority' => 4, 'coverage_start_year' => 1775, 'coverage_end_year' => 1800, 'access_type' => 'free', 'notes' => 'Pension files, service records (M804, M805, M246)'],
            ['archive_name' => 'Fold3 (Revolutionary)', 'archive_url' => 'https://www.fold3.com', 'record_types' => ['military'], 'eras' => ['revolutionary'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 5, 'coverage_start_year' => 1775, 'coverage_end_year' => 1800, 'access_type' => 'subscription', 'notes' => 'Manual subscription lookup; extensive Revolutionary War coverage'],

            // === ERA: Antebellum ===
            ['archive_name' => 'NARA Census 1790-1860', 'archive_url' => 'https://www.archives.gov/research/census', 'record_types' => ['census'], 'eras' => ['antebellum'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'nara_search', 'priority' => 3, 'coverage_start_year' => 1790, 'coverage_end_year' => 1860, 'access_type' => 'free', 'notes' => 'No name index before 1850'],
            ['archive_name' => 'Chronicling America (Antebellum)', 'archive_url' => 'https://chroniclingamerica.loc.gov', 'record_types' => ['newspaper'], 'eras' => ['antebellum'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'newspaper_search', 'priority' => 4, 'coverage_start_year' => 1770, 'coverage_end_year' => 1860, 'access_type' => 'free', 'notes' => 'Strong 1840-1860 coverage'],

            // === ERA: Civil War ===
            ['archive_name' => 'NARA Civil War Records', 'archive_url' => 'https://www.archives.gov/research/military/civil-war', 'record_types' => ['military'], 'eras' => ['civil_war'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'nara_search', 'priority' => 3, 'coverage_start_year' => 1861, 'coverage_end_year' => 1875, 'access_type' => 'free', 'notes' => 'Service records, pension files (37M+ records)'],
            ['archive_name' => 'Fold3 (Civil War)', 'archive_url' => 'https://www.fold3.com', 'record_types' => ['military', 'pension'], 'eras' => ['civil_war'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1861, 'coverage_end_year' => 1875, 'access_type' => 'subscription', 'notes' => 'Manual subscription lookup for Civil War pension files'],
            ['archive_name' => 'Civil War Soldiers & Sailors', 'archive_url' => 'https://www.nps.gov/civilwar/soldiers-and-sailors-database.htm', 'record_types' => ['military'], 'eras' => ['civil_war'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 5, 'coverage_start_year' => 1861, 'coverage_end_year' => 1865, 'access_type' => 'free', 'notes' => 'Free NPS database; quick name lookup'],

            // === ERA: Gilded Age ===
            ['archive_name' => 'NARA Census 1870-1900', 'archive_url' => 'https://www.archives.gov/research/census', 'record_types' => ['census'], 'eras' => ['gilded_age'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'nara_search', 'priority' => 3, 'coverage_start_year' => 1870, 'coverage_end_year' => 1900, 'access_type' => 'free', 'notes' => '1880 census is best indexed'],
            ['archive_name' => 'NARA Naturalization', 'archive_url' => 'https://www.archives.gov/research/immigration/naturalization', 'record_types' => ['immigration', 'naturalization'], 'eras' => ['gilded_age'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'nara_search', 'priority' => 4, 'coverage_start_year' => 1870, 'coverage_end_year' => 1900, 'access_type' => 'free', 'notes' => 'Heavy immigration 1870-1900'],
            ['archive_name' => 'Ellis Island Records', 'archive_url' => 'https://www.statueofliberty.org/ellis-island', 'record_types' => ['immigration'], 'eras' => ['gilded_age', 'progressive'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'ellis_island_search', 'priority' => 5, 'coverage_start_year' => 1892, 'coverage_end_year' => 1954, 'access_type' => 'free', 'notes' => 'Peak immigration 1892-1924'],

            // === ERA: Progressive ===
            ['archive_name' => 'NARA Census 1900-1920', 'archive_url' => 'https://www.archives.gov/research/census', 'record_types' => ['census'], 'eras' => ['progressive'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'nara_search', 'priority' => 3, 'coverage_start_year' => 1900, 'coverage_end_year' => 1920, 'access_type' => 'free', 'notes' => '1900, 1910, 1920 censuses fully indexed'],
            ['archive_name' => 'WWI Draft Registration', 'archive_url' => 'https://www.ancestry.com/search/collections/6482', 'record_types' => ['military'], 'eras' => ['progressive'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1917, 'coverage_end_year' => 1918, 'access_type' => 'subscription', 'notes' => '24M men registered 1917-1918'],

            // === ERA: Interwar ===
            ['archive_name' => 'NARA 1930 Census', 'archive_url' => 'https://www.archives.gov/research/census', 'record_types' => ['census'], 'eras' => ['interwar'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'nara_search', 'priority' => 3, 'coverage_start_year' => 1930, 'coverage_end_year' => 1930, 'access_type' => 'free', 'notes' => 'Last fully released census'],
            ['archive_name' => 'NARA 1940 Census', 'archive_url' => 'https://1940census.archives.gov', 'record_types' => ['census'], 'eras' => ['interwar'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'nara_search', 'priority' => 4, 'coverage_start_year' => 1940, 'coverage_end_year' => 1940, 'access_type' => 'free', 'notes' => '1940 census fully indexed'],
            ['archive_name' => 'Social Security Death Index', 'archive_url' => 'https://www.ssa.gov/foia/request.html', 'record_types' => ['death'], 'eras' => ['interwar', 'modern'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 6, 'coverage_start_year' => 1936, 'coverage_end_year' => 2020, 'access_type' => 'foia', 'notes' => 'SS-5 application reveals birth date, parents, address'],

            // === ERA: Modern ===
            ['archive_name' => 'State Vital Records', 'archive_url' => 'https://www.cdc.gov/nchs/w2w.htm', 'record_types' => ['vital'], 'eras' => ['modern'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1900, 'coverage_end_year' => 2020, 'access_type' => 'mixed', 'notes' => 'State vital records offices; online ordering'],
            ['archive_name' => 'Legacy.com Obituaries', 'archive_url' => 'https://www.legacy.com', 'record_types' => ['death', 'obituary'], 'eras' => ['modern'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'newspaper_search_obituaries', 'priority' => 5, 'coverage_start_year' => 1950, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'Legacy.com and funeral home websites'],

            // === REGION: New England ===
            ['archive_name' => 'NEHGS (New England)', 'archive_url' => 'https://www.americanancestors.org', 'record_types' => ['vital', 'church', 'probate'], 'eras' => ['all'], 'regions' => ['new_england'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1600, 'coverage_end_year' => 1900, 'access_type' => 'subscription', 'notes' => 'Manual membership lookup; best resource for New England vital records from the 1600s'],

            // === REGION: Mid-Atlantic ===
            ['archive_name' => 'PA German Church Records', 'archive_url' => 'https://www.familysearch.org/en/wiki/Pennsylvania_Genealogy', 'record_types' => ['church', 'vital', 'land'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1700, 'coverage_end_year' => 1900, 'access_type' => 'free', 'notes' => 'Manual/browser-only Reformed, Lutheran, Mennonite, Brethren records'],
            ['archive_name' => 'NY State Archives', 'archive_url' => 'https://www.archives.nysed.gov', 'record_types' => ['vital', 'land', 'census'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 5, 'coverage_start_year' => 1855, 'coverage_end_year' => 1925, 'access_type' => 'free', 'notes' => 'NY state censuses (1855-1925)'],

            // === REGION: South ===
            ['archive_name' => "Freedmen's Bureau (South)", 'archive_url' => 'https://www.freedmensbureau.com', 'record_types' => ['vital', 'labor'], 'eras' => ['civil_war', 'gilded_age'], 'regions' => ['south'], 'ethnicities' => ['default', 'african_american'], 'tool_name' => 'freedmens_bureau_search', 'priority' => 4, 'coverage_start_year' => 1865, 'coverage_end_year' => 1872, 'access_type' => 'free', 'notes' => 'Post-1865 African-American families in the South'],

            // === REGION: International ===
            ['archive_name' => 'Archion (German Protestant)', 'archive_url' => 'https://www.archion.de', 'record_types' => ['church'], 'eras' => ['all'], 'regions' => ['german_origin'], 'ethnicities' => ['default'], 'tool_name' => 'german_church_records_search', 'priority' => 3, 'coverage_start_year' => 1580, 'coverage_end_year' => 1950, 'access_type' => 'subscription', 'notes' => 'German Protestant church records digitized'],
            ['archive_name' => 'Matricula Online (Catholic)', 'archive_url' => 'https://www.matricula-online.eu', 'record_types' => ['church'], 'eras' => ['all'], 'regions' => ['german_origin', 'eastern_europe', 'italy', 'france'], 'ethnicities' => ['default'], 'tool_name' => 'german_church_records_search', 'priority' => 4, 'coverage_start_year' => 1550, 'coverage_end_year' => 1950, 'access_type' => 'free', 'notes' => 'Catholic parish registers for Germany, Austria, Poland, Italy'],
            ['archive_name' => 'FreeBMD (UK)', 'archive_url' => 'https://www.freebmd.org.uk', 'record_types' => ['vital'], 'eras' => ['all'], 'regions' => ['uk_ireland'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1837, 'coverage_end_year' => 1984, 'access_type' => 'free', 'notes' => 'England & Wales BMD index'],
            ['archive_name' => 'ScotlandsPeople', 'archive_url' => 'https://www.scotlandspeople.gov.uk', 'record_types' => ['vital', 'church', 'census'], 'eras' => ['all'], 'regions' => ['uk_ireland'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1553, 'coverage_end_year' => 2020, 'access_type' => 'mixed', 'notes' => 'Official Scottish records'],
            ['archive_name' => 'Digitalarkivet (Norway)', 'archive_url' => 'https://www.digitalarkivet.no', 'record_types' => ['church', 'census'], 'eras' => ['all'], 'regions' => ['scandinavia'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1600, 'coverage_end_year' => 1920, 'access_type' => 'free', 'notes' => 'Norwegian National Archives — church books, census, emigration'],
            ['archive_name' => 'Antenati (Italy)', 'archive_url' => 'https://antenati.cultura.gov.it', 'record_types' => ['vital'], 'eras' => ['all'], 'regions' => ['italy'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1809, 'coverage_end_year' => 1910, 'access_type' => 'free', 'notes' => 'Italian civil registration; state archives'],
            ['archive_name' => 'Geneteka (Poland)', 'archive_url' => 'https://geneteka.genealodzy.pl', 'record_types' => ['vital', 'church'], 'eras' => ['all'], 'regions' => ['eastern_europe'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1600, 'coverage_end_year' => 1940, 'access_type' => 'free', 'notes' => '12M+ indexed Polish vital records'],
            ['archive_name' => 'Library and Archives Canada', 'archive_url' => 'https://www.bac-lac.gc.ca', 'record_types' => ['census', 'immigration', 'military'], 'eras' => ['all'], 'regions' => ['canada'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1851, 'coverage_end_year' => 1926, 'access_type' => 'free', 'notes' => 'Canadian federal records; censuses, passenger lists, WWI/WWII'],
            ['archive_name' => 'FranceArchives', 'archive_url' => 'https://francearchives.gouv.fr', 'record_types' => ['vital', 'church', 'military'], 'eras' => ['all'], 'regions' => ['france'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1792, 'coverage_end_year' => 2020, 'access_type' => 'free', 'notes' => 'Portal to French departmental archives; civil registration from 1792'],

            // === ETHNICITY: African American ===
            ['archive_name' => "Freedmen's Bureau Records", 'archive_url' => 'https://www.freedmensbureau.com', 'record_types' => ['vital', 'labor', 'marriage'], 'eras' => ['civil_war', 'gilded_age'], 'regions' => ['all'], 'ethnicities' => ['african_american'], 'tool_name' => 'freedmens_bureau_search', 'priority' => 2, 'coverage_start_year' => 1865, 'coverage_end_year' => 1872, 'access_type' => 'free', 'notes' => 'Labor contracts, marriage registers, ration records'],
            ['archive_name' => 'Fold3 USCT Records', 'archive_url' => 'https://www.fold3.com/title/318/civil-war-usct', 'record_types' => ['military'], 'eras' => ['civil_war'], 'regions' => ['all'], 'ethnicities' => ['african_american'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1863, 'coverage_end_year' => 1866, 'access_type' => 'subscription', 'notes' => 'Manual subscription lookup for USCT service records and pension files'],
            ['archive_name' => 'Fold3 Slave Schedules', 'archive_url' => 'https://www.fold3.com', 'record_types' => ['census'], 'eras' => ['antebellum'], 'regions' => ['all'], 'ethnicities' => ['african_american'], 'tool_name' => null, 'priority' => 6, 'coverage_start_year' => 1850, 'coverage_end_year' => 1860, 'access_type' => 'subscription', 'notes' => 'Manual subscription lookup for 1850 and 1860 slave schedules'],

            // === ETHNICITY: Jewish ===
            ['archive_name' => 'JRI-Poland', 'archive_url' => 'https://www.jri-poland.org', 'record_types' => ['vital', 'church'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['jewish'], 'tool_name' => null, 'priority' => 2, 'coverage_start_year' => 1600, 'coverage_end_year' => 1945, 'access_type' => 'free', 'notes' => '6M+ vital records from Polish Jewish communities'],
            ['archive_name' => 'Yad Vashem', 'archive_url' => 'https://yvng.yadvashem.org', 'record_types' => ['death', 'memorial'], 'eras' => ['interwar'], 'regions' => ['all'], 'ethnicities' => ['jewish'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1933, 'coverage_end_year' => 1945, 'access_type' => 'free', 'notes' => 'Holocaust victim names + pre-war community records'],
            ['archive_name' => 'JewishGen.org', 'archive_url' => 'https://www.jewishgen.org', 'record_types' => ['vital', 'various'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['jewish'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => null, 'coverage_end_year' => null, 'access_type' => 'free', 'notes' => 'Largest Jewish genealogy portal; town finder, databases'],
        ];
    }
}
