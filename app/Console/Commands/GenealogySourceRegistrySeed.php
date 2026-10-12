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
 *   php artisan genealogy:source-registry --validate  # Validate provider/public automation posture
 */
class GenealogySourceRegistrySeed extends Command
{
    protected $signature = 'genealogy:source-registry
        {--seed : Seed from routing matrix}
        {--stats : Show statistics}
        {--list : List all entries}
        {--validate : Validate provider/public automation posture}
        {--json : Output machine-readable JSON for validate, stats, or list modes}
        {--compact : Omit detailed row payloads from JSON output where possible}';

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

        if ($this->option('json')) {
            $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
            $payload = [
                'version' => 1,
                'generated_at' => now()->toIso8601String(),
                'mode' => 'validate',
                'compact' => (bool) $this->option('compact'),
                'status' => ! empty($result['valid']) ? 'pass' : 'fail',
                'valid' => ! empty($result['valid']),
                'summary' => [
                    'checked' => (int) ($summary['checked'] ?? 0),
                    'errors' => (int) ($summary['errors'] ?? 0),
                ],
                'error_codes' => $this->countErrorCodes($errors),
            ];

            if (! $this->option('compact')) {
                $payload['errors'] = $errors;
            }

            $this->line($this->encodeJson($payload));

            return ! empty($result['valid']) ? 0 : 1;
        }

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

        if ($this->option('json')) {
            $this->line($this->encodeJson([
                'version' => 1,
                'generated_at' => now()->toIso8601String(),
                'mode' => 'stats',
                'status' => isset($stats['error']) ? 'error' : 'pass',
                'stats' => $stats,
            ]));

            return isset($stats['error']) ? 1 : 0;
        }

        $this->table(['Metric', 'Value'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->toArray());

        return 0;
    }

    private function listEntries(): int
    {
        $entries = DB::select('SELECT archive_name, tool_name, priority, access_type, search_count, hit_count FROM genealogy_source_registry WHERE is_active = 1 ORDER BY priority');

        if ($this->option('json')) {
            $this->line($this->encodeJson([
                'version' => 1,
                'generated_at' => now()->toIso8601String(),
                'mode' => 'list',
                'compact' => (bool) $this->option('compact'),
                'status' => 'pass',
                'count' => count($entries),
                'entries' => array_map(
                    fn ($entry): array => [
                        'archive_name' => $entry->archive_name,
                        'tool_name' => $entry->tool_name,
                        'priority' => (int) $entry->priority,
                        'access_type' => $entry->access_type,
                        'search_count' => (int) $entry->search_count,
                        'hit_count' => (int) $entry->hit_count,
                    ],
                    $entries
                ),
            ]));

            return 0;
        }

        $this->table(['Archive', 'Tool', 'Pri', 'Access', 'Searches', 'Hits'],
            array_map(fn ($e) => [$e->archive_name, $e->tool_name ?? '-', $e->priority, $e->access_type, $e->search_count, $e->hit_count], $entries));

        return 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @return array<string, int>
     */
    private function countErrorCodes(array $errors): array
    {
        $counts = [];
        foreach ($errors as $error) {
            $code = (string) ($error['code'] ?? 'unknown');
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Build the seed entries from the RepositoryRoutingService knowledge.
     */
    private function buildEntries(): array
    {
        return [
            // === ALWAYS (global) ===
            ['archive_name' => 'FamilySearch', 'archive_url' => 'https://www.familysearch.org/search', 'record_types' => ['vital', 'census', 'church', 'military', 'immigration'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => null, 'priority' => 1, 'coverage_start_year' => 1500, 'coverage_end_year' => 2020, 'access_type' => 'free', 'notes' => 'Provider-specific source; use configured API/private adapter/operator-opened capture path'],
            ['archive_name' => 'Ancestry.com', 'archive_url' => 'https://www.ancestry.com/search', 'record_types' => ['census', 'vital', 'military', 'immigration', 'newspaper'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => null, 'priority' => 2, 'coverage_start_year' => 1500, 'coverage_end_year' => 2020, 'access_type' => 'subscription', 'notes' => 'Largest paid database; check library free access'],
            ['archive_name' => 'WikiTree', 'archive_url' => 'https://www.wikitree.com', 'record_types' => ['family_tree'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => 'wikitree_search', 'priority' => 7, 'coverage_start_year' => null, 'coverage_end_year' => null, 'access_type' => 'free', 'notes' => 'Collaborative tree; cross-reference with sources'],
            ['archive_name' => 'FindAGrave', 'archive_url' => 'https://www.findagrave.com', 'record_types' => ['cemetery', 'death'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => null, 'priority' => 8, 'coverage_start_year' => null, 'coverage_end_year' => null, 'access_type' => 'free', 'notes' => 'Cemetery records, death dates, photos'],
            ['archive_name' => 'NARA Catalog', 'archive_url' => 'https://catalog.archives.gov', 'record_types' => ['census', 'military', 'immigration', 'naturalization', 'land', 'probate', 'vital'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => 'nara_search', 'priority' => 2, 'coverage_start_year' => 1775, 'coverage_end_year' => 2020, 'access_type' => 'free', 'notes' => 'Federal catalog search for service, pension, immigration, court, land, and agency records'],
            ['archive_name' => 'Library of Congress Chronicling America', 'archive_url' => 'https://chroniclingamerica.loc.gov', 'record_types' => ['newspaper', 'obituary', 'marriage', 'birth'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => 'newspaper_search', 'priority' => 3, 'coverage_start_year' => 1690, 'coverage_end_year' => 1963, 'access_type' => 'free', 'notes' => 'Free OCR newspaper search for notices, obituaries, probate notices, social items, and migration clues'],
            ['archive_name' => 'Newspapers.com', 'archive_url' => 'https://www.newspapers.com', 'record_types' => ['newspaper', 'obituary', 'marriage', 'birth'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => 'newspaper_search', 'priority' => 3, 'coverage_start_year' => 1700, 'coverage_end_year' => 2025, 'access_type' => 'subscription_private_adapter', 'notes' => 'Private configured adapter/headed-browser capture target for OCR newspapers and clipped media'],
            ['archive_name' => 'Internet Archive Genealogy', 'archive_url' => 'https://archive.org/details/genealogy', 'record_types' => ['book', 'family_history', 'church', 'probate', 'land', 'newspaper'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => 'internet_archive_search', 'priority' => 4, 'coverage_start_year' => 1500, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'Digitized county histories, family books, city directories, church registers, and local-history volumes'],
            ['archive_name' => 'Europeana', 'archive_url' => 'https://www.europeana.eu', 'record_types' => ['newspaper', 'book', 'photo', 'military', 'vital'], 'eras' => ['all'], 'regions' => ['europe', 'german_origin', 'eastern_europe', 'france', 'italy', 'uk_ireland', 'scandinavia'], 'ethnicities' => ['all'], 'tool_name' => 'europeana_search', 'priority' => 4, 'coverage_start_year' => 1500, 'coverage_end_year' => 2025, 'access_type' => 'free_api', 'notes' => 'European cultural heritage API for newspapers, books, photos, and archive records'],
            ['archive_name' => 'USGenWeb Archives', 'archive_url' => 'https://www.usgenweb.org', 'record_types' => ['vital', 'cemetery', 'church', 'probate', 'land', 'newspaper'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => null, 'priority' => 5, 'coverage_start_year' => 1600, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'Volunteer county-level transcriptions; high-yield for cemetery, Bible, probate, and local records'],
            ['archive_name' => 'HathiTrust Digital Library', 'archive_url' => 'https://www.hathitrust.org', 'record_types' => ['book', 'family_history', 'city_directory', 'local_history'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => null, 'priority' => 6, 'coverage_start_year' => 1500, 'coverage_end_year' => 2025, 'access_type' => 'free_limited', 'notes' => 'Full-text discovery for county histories, directories, compiled genealogies, and proceedings'],
            ['archive_name' => 'Google Books', 'archive_url' => 'https://books.google.com', 'record_types' => ['book', 'family_history', 'city_directory', 'local_history'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => null, 'priority' => 6, 'coverage_start_year' => 1500, 'coverage_end_year' => 2025, 'access_type' => 'free_limited', 'notes' => 'Fast full-text discovery for local histories, directories, published genealogies, and court books'],
            ['archive_name' => 'BillionGraves', 'archive_url' => 'https://billiongraves.com', 'record_types' => ['cemetery', 'death'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => null, 'priority' => 8, 'coverage_start_year' => null, 'coverage_end_year' => null, 'access_type' => 'free_account', 'notes' => 'Cemetery GPS/photo index; useful cross-check when FindAGrave is incomplete'],
            ['archive_name' => 'CyndisList', 'archive_url' => 'https://www.cyndislist.com', 'record_types' => ['directory', 'repository'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['all'], 'tool_name' => null, 'priority' => 9, 'coverage_start_year' => null, 'coverage_end_year' => null, 'access_type' => 'free', 'notes' => 'Curated directory for locality-specific repositories, societies, and niche record sets'],

            // === ERA: Colonial ===
            ['archive_name' => 'NEHGS (Colonial)', 'archive_url' => 'https://www.americanancestors.org', 'record_types' => ['vital', 'church', 'land'], 'eras' => ['colonial'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1600, 'coverage_end_year' => 1776, 'access_type' => 'subscription', 'notes' => 'Provider-specific membership lookup; best for pre-1776 New England vital records, church records, probate'],
            ['archive_name' => 'Internet Archive (Colonial)', 'archive_url' => 'https://archive.org', 'record_types' => ['church', 'land', 'probate'], 'eras' => ['colonial'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1600, 'coverage_end_year' => 1776, 'access_type' => 'free', 'notes' => 'Digitized colonial church registers, land records'],
            ['archive_name' => 'Chronicling America (Colonial)', 'archive_url' => 'https://chroniclingamerica.loc.gov', 'record_types' => ['newspaper'], 'eras' => ['colonial'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'newspaper_search', 'priority' => 7, 'coverage_start_year' => 1690, 'coverage_end_year' => 1776, 'access_type' => 'free', 'notes' => 'Colonial newspapers; limited but available for some regions'],

            // === ERA: Revolutionary ===
            ['archive_name' => 'DAR Genealogical Records', 'archive_url' => 'https://www.dar.org/library/genealogy', 'record_types' => ['military', 'vital'], 'eras' => ['revolutionary'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'dar_search', 'priority' => 3, 'coverage_start_year' => 1775, 'coverage_end_year' => 1800, 'access_type' => 'free', 'notes' => 'Revolutionary War service records and lineage papers'],
            ['archive_name' => 'NARA Revolutionary War', 'archive_url' => 'https://www.archives.gov/research/military/american-revolution', 'record_types' => ['military'], 'eras' => ['revolutionary'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'nara_search', 'priority' => 4, 'coverage_start_year' => 1775, 'coverage_end_year' => 1800, 'access_type' => 'free', 'notes' => 'Pension files, service records (M804, M805, M246)'],
            ['archive_name' => 'Fold3 (Revolutionary)', 'archive_url' => 'https://www.fold3.com', 'record_types' => ['military'], 'eras' => ['revolutionary'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 5, 'coverage_start_year' => 1775, 'coverage_end_year' => 1800, 'access_type' => 'subscription', 'notes' => 'Provider-specific subscription lookup; extensive Revolutionary War coverage'],

            // === ERA: Antebellum ===
            ['archive_name' => 'NARA Census 1790-1860', 'archive_url' => 'https://www.archives.gov/research/census', 'record_types' => ['census'], 'eras' => ['antebellum'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'nara_search', 'priority' => 3, 'coverage_start_year' => 1790, 'coverage_end_year' => 1860, 'access_type' => 'free', 'notes' => 'No name index before 1850'],
            ['archive_name' => 'Chronicling America (Antebellum)', 'archive_url' => 'https://chroniclingamerica.loc.gov', 'record_types' => ['newspaper'], 'eras' => ['antebellum'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'newspaper_search', 'priority' => 4, 'coverage_start_year' => 1770, 'coverage_end_year' => 1860, 'access_type' => 'free', 'notes' => 'Strong 1840-1860 coverage'],

            // === ERA: Civil War ===
            ['archive_name' => 'NARA Civil War Records', 'archive_url' => 'https://www.archives.gov/research/military/civil-war', 'record_types' => ['military'], 'eras' => ['civil_war'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => 'nara_search', 'priority' => 3, 'coverage_start_year' => 1861, 'coverage_end_year' => 1875, 'access_type' => 'free', 'notes' => 'Service records, pension files (37M+ records)'],
            ['archive_name' => 'Fold3 (Civil War)', 'archive_url' => 'https://www.fold3.com', 'record_types' => ['military', 'pension'], 'eras' => ['civil_war'], 'regions' => ['all'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1861, 'coverage_end_year' => 1875, 'access_type' => 'subscription', 'notes' => 'Provider-specific subscription lookup for Civil War pension files'],
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
            ['archive_name' => 'NEHGS (New England)', 'archive_url' => 'https://www.americanancestors.org', 'record_types' => ['vital', 'church', 'probate'], 'eras' => ['all'], 'regions' => ['new_england'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1600, 'coverage_end_year' => 1900, 'access_type' => 'subscription', 'notes' => 'Provider-specific membership lookup; best resource for New England vital records from the 1600s'],

            // === REGION: Mid-Atlantic ===
            ['archive_name' => 'PA German Church Records', 'archive_url' => 'https://www.familysearch.org/en/wiki/Pennsylvania_Genealogy', 'record_types' => ['church', 'vital', 'land'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1700, 'coverage_end_year' => 1900, 'access_type' => 'free', 'notes' => 'Provider-specific Reformed, Lutheran, Mennonite, Brethren record lead'],
            ['archive_name' => 'NY State Archives', 'archive_url' => 'https://www.archives.nysed.gov', 'record_types' => ['vital', 'land', 'census'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 5, 'coverage_start_year' => 1855, 'coverage_end_year' => 1925, 'access_type' => 'free', 'notes' => 'NY state censuses (1855-1925)'],

            // === FT4 TARGETED: Pennsylvania core counties ===
            ['archive_name' => 'Pennsylvania State Archives / PHMC', 'archive_url' => 'https://www.phmc.pa.gov/Archives', 'record_types' => ['vital', 'military', 'land', 'probate', 'tax', 'naturalization'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 2, 'coverage_start_year' => 1681, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'FT4 priority for Pennsylvania veterans, land, tax, naturalization, death certificate, and county records'],
            ['archive_name' => 'Genealogical Society of Pennsylvania', 'archive_url' => 'https://genpa.org', 'record_types' => ['church', 'vital', 'probate', 'land', 'tax', 'family_history'], 'eras' => ['colonial', 'revolutionary', 'antebellum', 'civil_war', 'gilded_age'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1681, 'coverage_end_year' => 1920, 'access_type' => 'mixed', 'notes' => 'FT4 priority for Pennsylvania church abstracts, family folders, and migration patterns'],
            ['archive_name' => 'Pennsylvania State Library Digital Collections', 'archive_url' => 'https://digitalcollections.powerlibrary.org', 'record_types' => ['newspaper', 'book', 'local_history', 'city_directory', 'photo'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1700, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'FT4 priority for Pennsylvania local histories, directories, newspapers, and digitized manuscripts'],
            ['archive_name' => 'Union County PAGenWeb', 'archive_url' => 'https://www.pa-roots.com/pagenweb/union', 'record_types' => ['vital', 'cemetery', 'church', 'probate', 'land', 'local_history'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1770, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'FT4 priority for White Deer, Lewisburg, and Union County cemetery/church/local-history leads'],
            ['archive_name' => 'Northumberland County PAGenWeb', 'archive_url' => 'https://www.pa-roots.com/pagenweb/northumberland', 'record_types' => ['vital', 'cemetery', 'church', 'probate', 'land', 'local_history'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1770, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'FT4 priority for Watsontown, Milton, Northumberland, and regional cemetery/church leads'],
            ['archive_name' => 'Snyder County PAGenWeb', 'archive_url' => 'https://www.pa-roots.com/pagenweb/snyder', 'record_types' => ['vital', 'cemetery', 'church', 'probate', 'land', 'local_history'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1770, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'FT4 priority for Port Trevorton and Snyder County family groups'],
            ['archive_name' => 'Montour County PAGenWeb', 'archive_url' => 'https://www.pa-roots.com/pagenweb/montour', 'record_types' => ['vital', 'cemetery', 'church', 'probate', 'land', 'local_history'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1770, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'FT4 priority for Danville and Montour County death, cemetery, and local-history leads'],
            ['archive_name' => 'Berks County PAGenWeb', 'archive_url' => 'https://www.pa-roots.com/pagenweb/berks', 'record_types' => ['vital', 'cemetery', 'church', 'probate', 'land', 'local_history'], 'eras' => ['colonial', 'revolutionary', 'antebellum'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1700, 'coverage_end_year' => 1900, 'access_type' => 'free', 'notes' => 'FT4 priority for Rockland Township, Berks County, and Pennsylvania German lines'],
            ['archive_name' => 'Montgomery County PAGenWeb', 'archive_url' => 'https://www.pa-roots.com/pagenweb/montgomery', 'record_types' => ['vital', 'cemetery', 'church', 'probate', 'land', 'local_history'], 'eras' => ['colonial', 'revolutionary', 'antebellum'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1700, 'coverage_end_year' => 1900, 'access_type' => 'free', 'notes' => 'FT4 priority for Franconia and Montgomery County German/Reformed/Lutheran lines'],
            ['archive_name' => 'Lycoming County PAGenWeb', 'archive_url' => 'https://www.pa-roots.com/pagenweb/lycoming', 'record_types' => ['vital', 'cemetery', 'church', 'probate', 'land', 'local_history'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1770, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'FT4 priority for Washington Township, Lycoming County family groups'],
            ['archive_name' => 'MyWatsontown', 'archive_url' => 'https://mywatsontown.com', 'record_types' => ['obituary', 'newspaper', 'cemetery', 'local_history', 'photo'], 'eras' => ['gilded_age', 'progressive', 'interwar', 'modern'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 2, 'coverage_start_year' => 1800, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'FT4 priority for Watsontown/Northumberland local history, obituaries, cemetery, and photo context'],
            ['archive_name' => 'The Daily Item Obituaries', 'archive_url' => 'https://www.dailyitem.com/obituaries', 'record_types' => ['obituary', 'death', 'newspaper'], 'eras' => ['modern'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => 'newspaper_search_obituaries', 'priority' => 3, 'coverage_start_year' => 1930, 'coverage_end_year' => 2025, 'access_type' => 'free_limited', 'notes' => 'FT4 priority for Susquehanna Valley modern obituaries around Watsontown, Lewisburg, Danville, Milton, and Sunbury'],
            ['archive_name' => 'Hagenbuch Family Association', 'archive_url' => 'https://www.hagenbuch.org', 'record_types' => ['family_history', 'photo', 'obituary', 'local_history'], 'eras' => ['all'], 'regions' => ['mid_atlantic', 'german_origin'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1700, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'FT4 priority for Hagenbuch-connected Pennsylvania German family context; verify against primary records before writeback'],
            ['archive_name' => 'PA-Roots', 'archive_url' => 'https://www.pa-roots.com', 'record_types' => ['vital', 'cemetery', 'church', 'probate', 'land', 'local_history'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1681, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'FT4 priority for Pennsylvania county transcription indexes and locality pages'],
            ['archive_name' => 'Power Library Pennsylvania Newspapers', 'archive_url' => 'https://panewsarchive.psu.edu', 'record_types' => ['newspaper', 'obituary', 'marriage', 'birth'], 'eras' => ['all'], 'regions' => ['mid_atlantic'], 'ethnicities' => ['default'], 'tool_name' => 'newspaper_search', 'priority' => 4, 'coverage_start_year' => 1780, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'FT4 priority for Pennsylvania newspapers not covered by Chronicling America or Newspapers.com'],

            // === FT4 TARGETED: early New England / Plymouth lines ===
            ['archive_name' => 'Mayflower Society Silver Books', 'archive_url' => 'https://themayflowersociety.org', 'record_types' => ['family_history', 'vital', 'probate'], 'eras' => ['colonial'], 'regions' => ['new_england'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1620, 'coverage_end_year' => 1800, 'access_type' => 'published', 'notes' => 'FT4 priority for Plymouth/Rehoboth lines; authored lineage clue source requiring primary-record corroboration'],
            ['archive_name' => 'Massachusetts Vital Records 1620-1850', 'archive_url' => 'https://ma-vitalrecords.org', 'record_types' => ['vital'], 'eras' => ['colonial', 'revolutionary', 'antebellum'], 'regions' => ['new_england'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1620, 'coverage_end_year' => 1850, 'access_type' => 'free', 'notes' => 'FT4 priority for Rehoboth, Plymouth, and Bristol/Plymouth County birth, marriage, and death abstracts'],
            ['archive_name' => 'Plymouth Colony Archive Project', 'archive_url' => 'https://history.hanover.edu/texts/plymouth', 'record_types' => ['court', 'land', 'probate', 'vital'], 'eras' => ['colonial'], 'regions' => ['new_england'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => 1620, 'coverage_end_year' => 1691, 'access_type' => 'free', 'notes' => 'FT4 priority for Plymouth Colony court, land, and family-context leads'],

            // === FT4 TARGETED: German / Wuerttemberg origin lines ===
            ['archive_name' => 'Landesarchiv Baden-Wuerttemberg', 'archive_url' => 'https://www.landesarchiv-bw.de', 'record_types' => ['church', 'military', 'emigration', 'land', 'court'], 'eras' => ['colonial', 'revolutionary', 'antebellum'], 'regions' => ['german_origin'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1500, 'coverage_end_year' => 1900, 'access_type' => 'free', 'notes' => 'FT4 priority for Steinheim, Ludwigsburg, Murr, and Baden-Wuerttemberg archival leads'],
            ['archive_name' => 'Leo-BW Regional History', 'archive_url' => 'https://www.leo-bw.de', 'record_types' => ['local_history', 'place', 'book'], 'eras' => ['all'], 'regions' => ['german_origin'], 'ethnicities' => ['default'], 'tool_name' => null, 'priority' => 5, 'coverage_start_year' => 1500, 'coverage_end_year' => 2025, 'access_type' => 'free', 'notes' => 'FT4 locality context for Baden-Wuerttemberg places before selecting church or civil repositories'],

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
            ['archive_name' => 'Fold3 USCT Records', 'archive_url' => 'https://www.fold3.com/title/318/civil-war-usct', 'record_types' => ['military'], 'eras' => ['civil_war'], 'regions' => ['all'], 'ethnicities' => ['african_american'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1863, 'coverage_end_year' => 1866, 'access_type' => 'subscription', 'notes' => 'Provider-specific subscription lookup for USCT service records and pension files'],
            ['archive_name' => 'Fold3 Slave Schedules', 'archive_url' => 'https://www.fold3.com', 'record_types' => ['census'], 'eras' => ['antebellum'], 'regions' => ['all'], 'ethnicities' => ['african_american'], 'tool_name' => null, 'priority' => 6, 'coverage_start_year' => 1850, 'coverage_end_year' => 1860, 'access_type' => 'subscription', 'notes' => 'Provider-specific subscription lookup for 1850 and 1860 slave schedules'],

            // === ETHNICITY: Jewish ===
            ['archive_name' => 'JRI-Poland', 'archive_url' => 'https://www.jri-poland.org', 'record_types' => ['vital', 'church'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['jewish'], 'tool_name' => null, 'priority' => 2, 'coverage_start_year' => 1600, 'coverage_end_year' => 1945, 'access_type' => 'free', 'notes' => '6M+ vital records from Polish Jewish communities'],
            ['archive_name' => 'Yad Vashem', 'archive_url' => 'https://yvng.yadvashem.org', 'record_types' => ['death', 'memorial'], 'eras' => ['interwar'], 'regions' => ['all'], 'ethnicities' => ['jewish'], 'tool_name' => null, 'priority' => 3, 'coverage_start_year' => 1933, 'coverage_end_year' => 1945, 'access_type' => 'free', 'notes' => 'Holocaust victim names + pre-war community records'],
            ['archive_name' => 'JewishGen.org', 'archive_url' => 'https://www.jewishgen.org', 'record_types' => ['vital', 'various'], 'eras' => ['all'], 'regions' => ['all'], 'ethnicities' => ['jewish'], 'tool_name' => null, 'priority' => 4, 'coverage_start_year' => null, 'coverage_end_year' => null, 'access_type' => 'free', 'notes' => 'Largest Jewish genealogy portal; town finder, databases'],
        ];
    }
}
