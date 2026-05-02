<?php

namespace App\Console\Commands;

use App\Engine\MCPRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fetch and cache the remote HTML / document for every `source_add` proposal
 * that's been applied but doesn't yet have a local snapshot.
 *
 * When an operator approves a source_add proposal, `PersonService::applyProposedChange`
 * appends the URL to the person's notes — but never actually downloads the
 * referenced document. Over time the remote page may change, move, or 404, and
 * the FT loses the evidence. This command pulls each URL, saves an HTML snapshot
 * into `storage/app/genealogy_sources/{tree_id}/{person_id}/{hash}.html`, and
 * links it via `genealogy_media` so the page is viewable offline and survives
 * link rot.
 *
 * Safe to run daily on cron — idempotent (skips URLs already cached).
 */
class GenealogyBackfillSourceMedia extends Command
{
    protected $signature = 'genealogy:backfill-source-media
        {--since=7d : Only backfill proposals approved within this window (e.g. 24h, 7d, 30d, all)}
        {--tree=4 : Restrict to a specific tree_id}
        {--person= : Restrict to a specific person_id}
        {--limit=50 : Max URLs to fetch per run (scheduler-friendly cap)}
        {--timeout=20 : Per-request timeout (seconds)}
        {--puppeteer : Enable Puppeteer MCP fallback for Cloudflare-blocked hosts (slower; manual-run only)}
        {--dry-run : List targets without fetching}';

    protected $description = 'Download + cache HTML snapshots for approved source_add URLs so the evidence survives link rot';

    public function handle(): int
    {
        $since = (string) $this->option('since');
        $treeId = (int) $this->option('tree');
        $personId = $this->option('person') ? (int) $this->option('person') : null;
        $limit = max(1, (int) $this->option('limit'));
        $timeout = max(5, (int) $this->option('timeout'));
        $usePuppeteer = (bool) $this->option('puppeteer');
        $dryRun = (bool) $this->option('dry-run');

        $windowSql = $this->resolveWindowSql($since);

        $sql = "SELECT pc.id, pc.person_id, pc.tree_id, pc.proposed_value AS url,
                       pc.evidence_summary, pc.applied_at,
                       CONCAT(COALESCE(p.given_name,''), ' ', COALESCE(p.surname,'')) AS person_name
                FROM genealogy_proposed_changes pc
                INNER JOIN genealogy_persons p ON p.id = pc.person_id
                WHERE pc.change_type = 'source_add'
                  AND pc.status = 'applied'
                  AND pc.proposed_value LIKE 'http%'
                  {$windowSql}
                  AND pc.tree_id = ?
        ";

        $params = [$treeId];
        if ($personId) {
            $sql .= ' AND pc.person_id = ?';
            $params[] = $personId;
        }
        $sql .= ' ORDER BY pc.applied_at DESC LIMIT '.$limit;

        $rows = DB::select($sql, $params);
        if (empty($rows)) {
            $this->info('No applied source_add proposals matched the filter.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d candidate proposal(s)%s.', count($rows), $dryRun ? ' (dry-run)' : ''));

        $fetched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $url = trim((string) $row->url);
            if ($url === '') {
                continue;
            }

            // Some provider URLs (notably www.loc.gov/resource/...) are JS-rendered
            // viewer shells that return 403 to any non-browser request. Rewrite them
            // to the matching scrapable data endpoint so we actually capture content.
            [$fetchUrl, $ext] = $this->rewriteFetchUrl($url);

            $hash = substr(sha1($url), 0, 16);
            $relPath = sprintf('genealogy_sources/%d/%d/%s.%s', (int) $row->tree_id, (int) $row->person_id, $hash, $ext);
            $absPath = storage_path('app/'.$relPath);

            $existingMedia = DB::selectOne(
                "SELECT id FROM genealogy_media WHERE tree_id = ? AND original_path = ? LIMIT 1",
                [(int) $row->tree_id, $url]
            );

            if ($existingMedia) {
                $skipped++;
                $this->line(sprintf('  [skip] already cached: %s → media_id=%d', mb_substr($url, 0, 80), $existingMedia->id));
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('  [would fetch] %s → %s', mb_substr($url, 0, 80), $relPath));
                continue;
            }

            try {
                // LoC and several genealogy sites return 403 for bot-style UAs.
                // Use a current browser UA + Accept headers so we look like a
                // normal reader fetching the page for offline reference.
                $response = Http::connectTimeout(5)
                    ->timeout($timeout)
                    ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                    ->withHeaders([
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                        'Accept-Language' => 'en-US,en;q=0.9',
                        'Accept-Encoding' => 'identity',
                        'Cache-Control' => 'no-cache',
                    ])
                    ->get($fetchUrl);

                if (! $response->successful()) {
                    // LoC + chroniclingamerica sit behind Cloudflare which 403s all
                    // non-browser traffic. Distinguish Cloudflare-blocked hosts so
                    // the operator doesn't mistake it for a broken command.
                    $isCloudflareBlocked = $response->status() === 403
                        && $this->isCloudflareBlockedHost($fetchUrl);

                    // Opt-in Puppeteer fallback: only when the operator passed
                    // --puppeteer (slower + heavier, not safe for cron) AND the
                    // host is on the configured Cloudflare block list.
                    if ($isCloudflareBlocked && $usePuppeteer) {
                        // Puppeteer hits the ORIGINAL URL, not the rewritten OCR endpoint.
                        // A headless browser handles JS-rendered viewer shells fine, so
                        // we capture the real page content (LoC viewer, FamilySearch
                        // profile) instead of chasing the redirect-blocked OCR path.
                        $puppeteerHtml = $this->fetchViaPuppeteer($url);
                        if ($puppeteerHtml !== null && $puppeteerHtml !== '') {
                            $persisted = $this->persistSnapshot(
                                treeId: (int) $row->tree_id,
                                personId: (int) $row->person_id,
                                url: $url,
                                body: $puppeteerHtml,
                                relPath: $relPath,
                                ext: $ext,
                                hash: $hash,
                                mime: 'text/html',
                                evidenceSummary: (string) $row->evidence_summary,
                                titlePrefix: '[Puppeteer] ',
                                transcriptionSource: 'ocr'
                            );
                            if ($persisted !== null) {
                                $fetched++;
                                $this->line(sprintf('  [ok-pup] person=%d id=%d size=%s title=%s',
                                    (int) $row->person_id,
                                    $persisted['media_id'],
                                    number_format($persisted['size']),
                                    mb_substr($persisted['title'], 0, 60)
                                ));
                                continue;
                            }
                        }
                    }

                    $failed++;
                    $label = $isCloudflareBlocked ? 'cf-block' : 'fail';
                    $hint = '';
                    if ($isCloudflareBlocked) {
                        $hint = $usePuppeteer
                            ? ' (Cloudflare — Puppeteer fallback failed)'
                            : ' (Cloudflare — rerun with --puppeteer)';
                    }
                    $this->warn(sprintf(
                        '  [%s] HTTP %d%s: %s',
                        $label,
                        $response->status(),
                        $hint,
                        mb_substr($url, 0, 80)
                    ));
                    continue;
                }

                $body = (string) $response->body();
                if ($body === '') {
                    $failed++;
                    $this->warn(sprintf('  [fail] empty body: %s', mb_substr($url, 0, 80)));
                    continue;
                }

                Storage::disk('local')->put($relPath, $body);
                $size = strlen($body);
                $mime = $response->header('content-type') ?: 'text/html';
                $mime = strtok($mime, ';');

                $title = $this->extractHtmlTitle($body) ?: ('Source '.$hash);
                $mediaType = $this->classifyMediaType($url, $body);

                DB::insert(
                    'INSERT INTO genealogy_media (
                        tree_id, gedcom_id, uid, original_path, local_filename,
                        file_format, mime_type, file_size, title, description,
                        analysis_status, enrichment_status, face_sync_status,
                        media_type, file_exists, imported_at, privacy, created_at, updated_at
                     ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW())',
                    [
                        (int) $row->tree_id,
                        'backfill:'.$hash,
                        $url,
                        basename($relPath),
                        $ext,
                        $mime,
                        $size,
                        mb_substr($title, 0, 500),
                        mb_substr((string) $row->evidence_summary, 0, 2000),
                        'pending',
                        'pending',
                        'pending',
                        $mediaType,
                        1,
                        'private',
                    ]
                );
                $mediaId = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS id')->id;

                DB::insert(
                    'INSERT INTO genealogy_person_media (person_id, media_id, created_at) VALUES (?, ?, NOW())',
                    [(int) $row->person_id, $mediaId]
                );

                $fetched++;
                $this->line(sprintf('  [ok]   person=%d id=%d size=%s title=%s',
                    (int) $row->person_id,
                    $mediaId,
                    number_format($size),
                    mb_substr($title, 0, 60)
                ));
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('GenealogyBackfillSourceMedia: fetch threw', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                $this->warn(sprintf('  [err] %s: %s', mb_substr($url, 0, 80), $e->getMessage()));
            }
        }

        $this->info(sprintf('Done. fetched=%d skipped=%d failed=%d', $fetched, $skipped, $failed));

        return $failed > 0 && $fetched === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Map a viewer-shell URL to a scrapable data endpoint when possible.
     * Returns [$urlToFetch, $fileExtension].
     *
     * Library of Congress:
     *   www.loc.gov/resource/{lccn}/{date}/ed-{n}/?sp={seq}&q=...  (viewer, 403)
     *   → chroniclingamerica.loc.gov/lccn/{lccn}/{date}/ed-{n}/seq-{seq}/ocr.txt  (OCR text, open)
     *
     * Other providers pass through unchanged (fetch original HTML).
     */
    private function rewriteFetchUrl(string $url): array
    {
        if (preg_match(
            '~^https?://www\.loc\.gov/resource/([^/]+)/(\d{4}-\d{2}-\d{2})/ed-(\d+)/.*\bsp=(\d+)~i',
            $url,
            $m
        )) {
            $lccn = $m[1];
            $date = $m[2];
            $edition = $m[3];
            $seq = (int) $m[4];
            $rewritten = sprintf(
                'https://chroniclingamerica.loc.gov/lccn/%s/%s/ed-%d/seq-%d/ocr.txt',
                $lccn,
                $date,
                $edition,
                $seq
            );

            return [$rewritten, 'txt'];
        }

        return [$url, 'html'];
    }

    /**
     * Check whether the given URL's host is on the configured Cloudflare
     * block list. Hosts are matched exactly against `parse_url(...,PHP_URL_HOST)`
     * so the operator can add subdomain-specific entries.
     */
    private function isCloudflareBlockedHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }
        $hosts = (array) config('genealogy.cloudflare_blocked_hosts', []);

        return in_array(strtolower($host), array_map('strtolower', $hosts), true);
    }

    /**
     * Fetch a URL via the Puppeteer MCP server and return the rendered outer HTML.
     * Returns null if Puppeteer is unavailable, navigation fails, evaluation fails,
     * or the response body is empty. Never throws — logs a warning on any failure
     * so the calling loop continues to the next candidate.
     *
     * Uses the same MCPRouter pattern as SafeScrapingService::scrapeWithPuppeteer
     * and ResearchService::searchGroundNews: puppeteer_navigate then
     * puppeteer_evaluate, with headless launch options.
     */
    private function fetchViaPuppeteer(string $url): ?string
    {
        try {
            /** @var MCPRouter $router */
            $router = app(MCPRouter::class);
        } catch (\Throwable $e) {
            Log::warning('GenealogyBackfillSourceMedia: MCPRouter unavailable', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        try {
            $navResult = $router->callTool('puppeteer', 'puppeteer_navigate', [
                'url' => $url,
                'allowDangerous' => true,
                'launchOptions' => [
                    'headless' => true,
                    'args' => ['--no-sandbox', '--disable-setuid-sandbox'],
                ],
            ], 60);

            if (! $navResult
                || (isset($navResult['isError']) && $navResult['isError'])
                || (isset($navResult['error']) && $navResult['error'])) {
                Log::warning('GenealogyBackfillSourceMedia: puppeteer_navigate failed', [
                    'url' => $url,
                    'result' => $navResult,
                ]);

                return null;
            }

            // Small settle delay — lets the CF challenge / JS shell finish
            // rendering before we grab outerHTML. Matches SafeScrapingService.
            usleep(3_000_000);

            $evalResult = $router->callTool('puppeteer', 'puppeteer_evaluate', [
                'script' => 'document.documentElement.outerHTML',
            ], 45);

            $html = null;
            if (is_array($evalResult) && isset($evalResult['content']) && is_array($evalResult['content'])) {
                foreach ($evalResult['content'] as $item) {
                    if (is_array($item) && isset($item['text']) && is_string($item['text']) && $item['text'] !== '') {
                        $html = $item['text'];
                        break;
                    }
                }
            }

            if (! is_string($html) || $html === '') {
                Log::warning('GenealogyBackfillSourceMedia: puppeteer returned empty HTML', [
                    'url' => $url,
                ]);

                return null;
            }

            return $html;
        } catch (\Throwable $e) {
            Log::warning('GenealogyBackfillSourceMedia: puppeteer fallback threw', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Persist a fetched snapshot (direct HTTP or Puppeteer) to disk and link
     * it to the person via genealogy_media + genealogy_person_media. Returns
     * the new media_id + size + resolved title on success, or null on failure.
     *
     * Kept separate from the direct-fetch branch so the Puppeteer fallback
     * can reuse it without duplicating INSERTs or diverging schema behavior.
     */
    private function persistSnapshot(
        int $treeId,
        int $personId,
        string $url,
        string $body,
        string $relPath,
        string $ext,
        string $hash,
        string $mime,
        string $evidenceSummary,
        string $titlePrefix = '',
        ?string $transcriptionSource = null
    ): ?array {
        try {
            Storage::disk('local')->put($relPath, $body);
            $size = strlen($body);
            $mime = strtok($mime, ';') ?: 'text/html';

            $rawTitle = $this->extractHtmlTitle($body) ?: ('Source '.$hash);
            $title = $titlePrefix.$rawTitle;
            $mediaType = $this->classifyMediaType($url, $body);

            DB::insert(
                'INSERT INTO genealogy_media (
                    tree_id, gedcom_id, uid, original_path, local_filename,
                    file_format, mime_type, file_size, title, description,
                    analysis_status, enrichment_status, face_sync_status,
                    media_type, file_exists, imported_at, privacy, created_at, updated_at
                 ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW())',
                [
                    $treeId,
                    'backfill:'.$hash,
                    $url,
                    basename($relPath),
                    $ext,
                    $mime,
                    $size,
                    mb_substr($title, 0, 500),
                    mb_substr($evidenceSummary, 0, 2000),
                    'pending',
                    'pending',
                    'pending',
                    $mediaType,
                    1,
                    'private',
                ]
            );
            $mediaId = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS id')->id;

            if ($transcriptionSource !== null) {
                // Stamp transcription_source only when the column exists so
                // this helper stays safe across minor schema drifts.
                try {
                    DB::update(
                        'UPDATE genealogy_media SET transcription_source = ? WHERE id = ?',
                        [$transcriptionSource, $mediaId]
                    );
                } catch (\Throwable $e) {
                    Log::debug('GenealogyBackfillSourceMedia: transcription_source stamp skipped', [
                        'media_id' => $mediaId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            DB::insert(
                'INSERT INTO genealogy_person_media (person_id, media_id, created_at) VALUES (?, ?, NOW())',
                [$personId, $mediaId]
            );

            return [
                'media_id' => $mediaId,
                'size' => $size,
                'title' => $title,
            ];
        } catch (\Throwable $e) {
            Log::warning('GenealogyBackfillSourceMedia: persistSnapshot failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveWindowSql(string $since): string
    {
        if ($since === 'all' || $since === '' || $since === '0') {
            return '';
        }
        if (preg_match('/^(\d+)([hd])$/i', trim($since), $m)) {
            $n = (int) $m[1];
            $unit = strtoupper($m[2]) === 'D' ? 'DAY' : 'HOUR';

            return "AND pc.applied_at > DATE_SUB(NOW(), INTERVAL {$n} {$unit})";
        }

        // Fallback: assume days.
        $n = (int) $since;
        if ($n > 0) {
            return "AND pc.applied_at > DATE_SUB(NOW(), INTERVAL {$n} DAY)";
        }

        return '';
    }

    private function extractHtmlTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $title = (string) preg_replace('/\s+/', ' ', $title);

            return $title !== '' ? $title : null;
        }

        return null;
    }

    private function classifyMediaType(string $url, string $body): string
    {
        $lower = strtolower($url);
        if (str_contains($lower, 'findagrave.com')) {
            return 'headstone';
        }
        if (str_contains($lower, 'chroniclingamerica.loc.gov') || str_contains($lower, 'loc.gov/resource')) {
            return 'document';
        }
        if (str_contains($lower, 'catalog.archives.gov')) {
            return 'document';
        }
        if (str_contains($lower, 'obituary') || stripos($body, 'obituary') !== false) {
            return 'obituary';
        }

        return 'document';
    }
}
