<?php

namespace App\Console\Commands;

use App\Services\Genealogy\Support\ProximityNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * 2.1f — retroactive proximity audit for existing person ↔ source links.
 *
 * Scans `genealogy_person_sources` joined to `genealogy_sources` +
 * `genealogy_persons`, and classifies each link into one of three
 * buckets by running ProximityNameMatcher over the source's DB-resident
 * text (title + notes) and optionally its fetched URL body:
 *
 *   - clean: target's full name is proximity-valid in the source content
 *   - suspect: content exists but proximity fails (possible token scatter)
 *   - no_content: no content available to verify
 *
 * Dry-run by default. No rows are mutated. Intended to surface the
 * historical contamination that shipped before the 2.1b–2.1e gates
 * landed, so the operator can plan a cleanup pass.
 */
class GenealogyAuditNameMatches extends Command
{
    protected $signature = 'genealogy:audit-name-matches
                            {--tree= : Restrict to a single tree_id (optional)}
                            {--limit=200 : Maximum rows to audit (default 200)}
                            {--output=table : Output format: table|json|csv}
                            {--fetch : Also fetch source URLs for verification (slow)}
                            {--dry-run : Enforce no-mutation mode (default; kept for UX consistency)}';

    protected $description = 'Audit existing person↔source links for name-match proximity contamination (2.1f)';

    public function handle(): int
    {
        $treeId = $this->option('tree');
        $limit = max(1, (int) $this->option('limit'));
        $output = (string) $this->option('output');
        $fetch = (bool) $this->option('fetch');

        if (! in_array($output, ['table', 'json', 'csv'], true)) {
            $this->error("Invalid --output: {$output}. Use table|json|csv.");

            return self::FAILURE;
        }

        $this->info('Auditing person↔source links for proximity contamination...');
        $this->line(sprintf('  tree=%s  limit=%d  fetch=%s',
            $treeId ?? '(all)',
            $limit,
            $fetch ? 'yes' : 'no'
        ));

        $rows = $this->loadRows($treeId, $limit);

        if (empty($rows)) {
            $this->warn('No person↔source links matched the query.');

            return self::SUCCESS;
        }

        $buckets = ['clean' => [], 'suspect' => [], 'no_content' => [], 'orphan_link' => []];
        $providerCounts = [];

        foreach ($rows as $row) {
            $given = trim((string) ($row->given_name ?? ''));
            $surname = trim((string) ($row->surname ?? ''));
            $provider = $this->classifyProvider((string) ($row->source_url ?? ''));

            // Orphan: either the person or the source row is missing
            // entirely (LEFT JOIN leaves NULLs). Distinct from
            // "person-has-no-name" — that's covered below.
            if ($row->person_id && ($row->given_name === null && $row->surname === null)) {
                $buckets['orphan_link'][] = $this->bucketRow($row, $provider, 'person row missing (person_id points nowhere)');
                $this->countBucket($providerCounts, $provider, 'orphan_link');
                continue;
            }
            if ($row->source_id && ($row->source_title === null && $row->source_url === null && $row->source_notes === null)) {
                $buckets['orphan_link'][] = $this->bucketRow($row, $provider, 'source row missing (source_id points nowhere)');
                $this->countBucket($providerCounts, $provider, 'orphan_link');
                continue;
            }

            if ($given === '' || $surname === '') {
                $buckets['no_content'][] = $this->bucketRow($row, $provider, 'person lacks given or surname');
                $this->countBucket($providerCounts, $provider, 'no_content');
                continue;
            }

            $content = $this->gatherContent($row, $fetch);
            if ($content === null || $content === '') {
                $buckets['no_content'][] = $this->bucketRow($row, $provider, 'no content available');
                $this->countBucket($providerCounts, $provider, 'no_content');
                continue;
            }

            $explanation = ProximityNameMatcher::explain($content, $given, $surname);
            if ($explanation['matched']) {
                $buckets['clean'][] = $this->bucketRow($row, $provider, $explanation['reason']);
                $this->countBucket($providerCounts, $provider, 'clean');
            } else {
                $buckets['suspect'][] = $this->bucketRow($row, $provider, $explanation['reason']);
                $this->countBucket($providerCounts, $provider, 'suspect');
            }
        }

        $this->renderOutput($output, $buckets, $providerCounts, count($rows));

        return self::SUCCESS;
    }

    /**
     * Load audit rows from the database. Extracted so tests can override
     * via a subclass and inject synthetic rows without mocking the DB
     * facade (which would also intercept Laravel's bootstrap queries).
     *
     * @return array<int, object>
     */
    protected function loadRows(?string $treeId, int $limit): array
    {
        // LEFT JOIN so rows with missing person or missing source rows
        // still surface — they are a data-quality signal, not a thing
        // to hide. `given_name` / `surname` / `source_title` / `source_url`
        // will be NULL for those rows; classifyRow() routes them to the
        // orphan_link bucket.
        $sql = "SELECT
                    gps.id AS link_id,
                    gps.person_id,
                    gps.source_id,
                    gp.given_name,
                    gp.surname,
                    gs.title AS source_title,
                    gs.url AS source_url,
                    gs.notes AS source_notes
                FROM genealogy_person_sources gps
                LEFT JOIN genealogy_persons gp ON gp.id = gps.person_id
                LEFT JOIN genealogy_sources gs ON gs.id = gps.source_id ";

        $params = [];
        if ($treeId) {
            // tree_id applies only to the joined person; orphan rows
            // (null person) are excluded from a tree-scoped run by
            // definition because we cannot know which tree they belong
            // to. Run without --tree to see orphans.
            $sql .= ' WHERE gp.tree_id = ? ';
            $params[] = (int) $treeId;
        }

        $sql .= ' ORDER BY gps.created_at DESC LIMIT '.$limit;

        return DB::select($sql, $params);
    }

    /**
     * Build the text buffer the proximity matcher inspects for one row.
     * Always includes DB-resident title + notes. When --fetch is on and
     * a URL exists, also fetches and appends the page body (strip_tags'd,
     * 2MB cap, 24h cache).
     */
    private function gatherContent(object $row, bool $fetch): ?string
    {
        $parts = [];
        $title = trim((string) ($row->source_title ?? ''));
        $notes = trim((string) ($row->source_notes ?? ''));

        if ($title !== '') {
            $parts[] = $title;
        }
        if ($notes !== '') {
            $parts[] = $notes;
        }

        if ($fetch) {
            $url = trim((string) ($row->source_url ?? ''));
            if ($url !== '' && preg_match('/^https?:\/\//i', $url)) {
                $fetched = $this->fetchUrlContent($url);
                if ($fetched !== null && $fetched !== '') {
                    $parts[] = $fetched;
                }
            }
        }

        $text = trim(implode(' ', $parts));

        return $text === '' ? null : $text;
    }

    private function fetchUrlContent(string $url): ?string
    {
        $cacheKey = 'source_content:audit:'.hash('sha256', $url);
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        try {
            $response = Http::connectTimeout(10)->timeout(10)->get($url);
            if (! $response->successful()) {
                return null;
            }

            $body = substr((string) $response->body(), 0, 2 * 1024 * 1024);
            $stripped = trim(strip_tags($body));

            Cache::put($cacheKey, $stripped, 60 * 60 * 24);

            return $stripped;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function classifyProvider(string $url): string
    {
        if ($url === '') {
            return 'unknown';
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $map = [
            'www.loc.gov' => 'chronicling_america',
            'chroniclingamerica.loc.gov' => 'chronicling_america',
            'catalog.archives.gov' => 'nara',
            'www.findagrave.com' => 'findagrave',
            'findagrave.com' => 'findagrave',
            'billiongraves.com' => 'billiongraves',
            'www.familysearch.org' => 'familysearch',
            'familysearch.org' => 'familysearch',
            'www.ancestry.com' => 'ancestry',
            'www.newspapers.com' => 'newspapers_com',
            'api.europeana.eu' => 'europeana',
            'heritage.statueofliberty.org' => 'ellis_island',
            'www.wikitree.com' => 'wikitree',
            'www.fold3.com' => 'fold3',
            // 2.1f ChatGPT audit finding — DAR was in config but missing here.
            'services.dar.org' => 'dar',
            'www.dar.org' => 'dar',
        ];

        return $map[$host] ?? ($host ?: 'unknown');
    }

    private function bucketRow(object $row, string $provider, string $reason): array
    {
        return [
            'link_id' => (int) $row->link_id,
            'person_id' => (int) $row->person_id,
            'source_id' => (int) $row->source_id,
            'person_name' => trim(($row->given_name ?? '').' '.($row->surname ?? '')),
            'source_title' => mb_substr((string) ($row->source_title ?? ''), 0, 80),
            'source_url' => (string) ($row->source_url ?? ''),
            'provider' => $provider,
            'reason' => $reason,
        ];
    }

    private function countBucket(array &$counts, string $provider, string $bucket): void
    {
        if (! isset($counts[$provider])) {
            $counts[$provider] = ['clean' => 0, 'suspect' => 0, 'no_content' => 0, 'orphan_link' => 0];
        }
        $counts[$provider][$bucket]++;
    }

    private function renderOutput(string $format, array $buckets, array $providerCounts, int $total): void
    {
        $summary = [
            'total' => $total,
            'clean' => count($buckets['clean']),
            'suspect' => count($buckets['suspect']),
            'no_content' => count($buckets['no_content']),
            'orphan_link' => count($buckets['orphan_link']),
            'by_provider' => $providerCounts,
        ];

        if ($format === 'json') {
            $this->line(json_encode([
                'summary' => $summary,
                'suspect_rows' => $buckets['suspect'],
                'no_content_rows' => $buckets['no_content'],
            ], JSON_PRETTY_PRINT));

            return;
        }

        if ($format === 'csv') {
            $this->line('bucket,link_id,person_id,source_id,person_name,provider,source_title,source_url,reason');
            foreach (['orphan_link', 'suspect', 'no_content', 'clean'] as $bucket) {
                foreach ($buckets[$bucket] as $r) {
                    $this->line(implode(',', array_map(
                        fn ($v) => '"'.str_replace('"', '""', (string) $v).'"',
                        [$bucket, $r['link_id'], $r['person_id'], $r['source_id'], $r['person_name'], $r['provider'], $r['source_title'], $r['source_url'], $r['reason']]
                    )));
                }
            }

            return;
        }

        $this->newLine();
        $this->info(sprintf('Audit summary: %d total | %d clean | %d suspect | %d no_content | %d orphan_link',
            $summary['total'], $summary['clean'], $summary['suspect'], $summary['no_content'], $summary['orphan_link']
        ));

        $this->newLine();
        $this->line('By provider:');
        foreach ($providerCounts as $provider => $counts) {
            $this->line(sprintf('  %-24s clean=%-4d suspect=%-4d no_content=%-4d orphan=%-4d',
                $provider, $counts['clean'], $counts['suspect'], $counts['no_content'], $counts['orphan_link']
            ));
        }

        if (! empty($buckets['orphan_link'])) {
            $this->newLine();
            $this->warn(sprintf('Top %d orphan rows (missing person or source):', min(15, count($buckets['orphan_link']))));
            foreach (array_slice($buckets['orphan_link'], 0, 15) as $r) {
                $this->line(sprintf('  link=%d person_id=%d source_id=%d reason=%s',
                    $r['link_id'], $r['person_id'], $r['source_id'], $r['reason']
                ));
            }
        }

        if (! empty($buckets['suspect'])) {
            $this->newLine();
            $this->warn(sprintf('Top %d suspect rows (proximity fail):', min(30, count($buckets['suspect']))));
            foreach (array_slice($buckets['suspect'], 0, 30) as $r) {
                $this->line(sprintf('  link=%d person=%s provider=%s reason=%s',
                    $r['link_id'], $r['person_name'], $r['provider'], $r['reason']
                ));
                if ($r['source_url'] !== '') {
                    $this->line('    '.$r['source_url']);
                }
            }
        }

        $this->newLine();
        $this->comment('No mutations performed (audit is read-only). Review suspect rows manually.');
    }
}
