<?php

namespace App\Console\Commands;

use App\Engine\MCPRouter;
use App\Services\Genealogy\GenealogyEvidenceAssetCaptureStorageService;
use App\Services\Genealogy\GenealogySemanticMemoryService;
use App\Services\Genealogy\NaraCatalogMediaCaptureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        {--mode=sources : Backfill mode: sources or proposals}
        {--since=7d : Window to scan (e.g. 24h, 7d, 30d, all). Source mode uses source created_at; proposal mode uses applied_at}
        {--tree=4 : Restrict to a specific tree_id, or all}
        {--person= : Restrict to a specific person_id}
        {--source-id=* : Restrict source mode to one or more genealogy_sources IDs}
        {--limit=50 : Max URLs to fetch per run (scheduler-friendly cap)}
        {--timeout=20 : Per-request timeout (seconds)}
        {--max-bytes= : Per-file download byte cap; defaults to genealogy.evidence_asset_capture.max_bytes}
        {--order=oldest : Source mode order: oldest or newest}
        {--confirm-download : Required for source-mode downloads unless --dry-run is used}
        {--confirm-storage-write : Required for source-mode FT storage writes unless --dry-run is used}
        {--nara-metadata-snapshot : For NARA URLs, save an API metadata snapshot when no digital object is downloadable}
        {--retry-blocked : Include sources already marked source_media_backfill_blocked}
        {--skip-link : Source mode: save/reuse media without creating the source citation link}
        {--json : Emit compact JSON summary}
        {--puppeteer : Enable Puppeteer MCP fallback for Cloudflare-blocked hosts (slower; manual-run only)}
        {--dry-run : List targets without fetching}';

    protected $description = 'Download/cache genealogy source URLs into tree-scoped FT storage so evidence survives link rot';

    public function handle(): int
    {
        $mode = strtolower(trim((string) $this->option('mode')));
        $since = (string) $this->option('since');
        $treeId = $this->treeIdOption();
        $personId = $this->option('person') ? (int) $this->option('person') : null;
        $limit = max(1, (int) $this->option('limit'));
        $timeout = max(5, (int) $this->option('timeout'));
        $usePuppeteer = (bool) $this->option('puppeteer');
        $dryRun = (bool) $this->option('dry-run');
        $json = (bool) $this->option('json');

        if ($mode === '' || $mode === 'source') {
            $mode = 'sources';
        }

        if ($mode === 'sources') {
            return $this->handleSourceRows(
                treeId: $treeId,
                personId: $personId,
                sourceIds: $this->sourceIdsOption(),
                since: $since,
                limit: $limit,
                dryRun: $dryRun,
                downloadConfirmed: (bool) $this->option('confirm-download'),
                storageConfirmed: (bool) $this->option('confirm-storage-write'),
                maxBytes: $this->maxBytesOption(),
                order: strtolower(trim((string) $this->option('order'))),
                linkConfirmed: ! (bool) $this->option('skip-link'),
                naraMetadataSnapshot: (bool) $this->option('nara-metadata-snapshot'),
                retryBlocked: (bool) $this->option('retry-blocked'),
                json: $json
            );
        }

        if ($mode !== 'proposals') {
            $this->error('Unsupported --mode. Use sources or proposals.');

            return self::FAILURE;
        }

        if ($treeId === null) {
            $this->error('Proposal mode requires --tree to be a positive tree_id.');

            return self::FAILURE;
        }

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
                'SELECT id FROM genealogy_media WHERE tree_id = ? AND original_path = ? LIMIT 1',
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
     * @param  list<int>  $sourceIds
     */
    private function handleSourceRows(
        ?int $treeId,
        ?int $personId,
        array $sourceIds,
        string $since,
        int $limit,
        bool $dryRun,
        bool $downloadConfirmed,
        bool $storageConfirmed,
        int $maxBytes,
        string $order,
        bool $linkConfirmed,
        bool $naraMetadataSnapshot,
        bool $retryBlocked,
        bool $json
    ): int {
        if (! $dryRun && (! $downloadConfirmed || ! $storageConfirmed)) {
            $payload = [
                'status' => 'blocked',
                'error' => '--confirm-download and --confirm-storage-write are required unless --dry-run is used',
                'items_processed' => 0,
            ];
            $this->emitSourcePayload($payload, $json);

            return self::FAILURE;
        }

        $order = $order === 'newest' ? 'newest' : 'oldest';
        $rows = $this->sourceBackfillRows($treeId, $personId, $sourceIds, $since, $limit, $order, $retryBlocked);

        $payload = [
            'schema' => 'genealogy_source_media_backfill.v1',
            'status' => $dryRun ? 'dry_run' : 'completed',
            'tree_id' => $treeId,
            'mode' => 'sources',
            'since' => $since,
            'limit' => $limit,
            'order' => $order,
            'dry_run' => $dryRun,
            'link_confirmed' => $linkConfirmed,
            'nara_metadata_snapshot' => $naraMetadataSnapshot,
            'retry_blocked' => $retryBlocked,
            'max_bytes' => $maxBytes,
            'summary' => [
                'candidates' => count($rows),
                'planned' => 0,
                'nara_api_attempts' => 0,
                'nara_downloads' => 0,
                'nara_metadata_snapshots' => 0,
                'download_attempts' => 0,
                'files_saved' => 0,
                'media_rows_created' => 0,
                'media_rows_reused' => 0,
                'citation_links_created' => 0,
                'failures' => 0,
                'skipped' => 0,
            ],
            'items' => [],
            'items_processed' => 0,
        ];

        if (! $json) {
            $this->info(sprintf(
                'Found %d URL-only source candidate(s)%s.',
                count($rows),
                $dryRun ? ' (dry-run)' : ''
            ));
        }

        $storage = app(GenealogyEvidenceAssetCaptureStorageService::class);
        foreach ($rows as $row) {
            $item = [
                'source_id' => (int) $row->id,
                'title' => mb_substr((string) $row->title, 0, 120),
                'url_host' => strtolower((string) parse_url((string) $row->url, PHP_URL_HOST)),
                'status' => 'planned',
            ];

            if ($dryRun) {
                $payload['summary']['planned']++;
                $payload['items'][] = $item;
                if (! $json) {
                    $this->line(sprintf(
                        '  [would capture] source=%d host=%s title=%s',
                        (int) $row->id,
                        $item['url_host'],
                        mb_substr((string) $row->title, 0, 70)
                    ));
                }

                continue;
            }

            $result = $this->isNaraCatalogUrl((string) $row->url)
                ? $this->captureNaraSourceRow($row, $maxBytes, $linkConfirmed, $naraMetadataSnapshot)
                : $this->captureSourceRow($storage, $row, $maxBytes, $linkConfirmed);
            $payload['summary']['nara_api_attempts'] += (int) ($result['summary']['nara_api_attempts'] ?? 0);
            $payload['summary']['nara_downloads'] += (int) ($result['summary']['nara_downloads'] ?? 0);
            $payload['summary']['nara_metadata_snapshots'] += (int) ($result['summary']['nara_metadata_snapshots'] ?? 0);
            $payload['summary']['download_attempts'] += (int) ($result['summary']['download_attempts'] ?? 0);
            $payload['summary']['files_saved'] += (int) ($result['summary']['files_saved'] ?? 0);
            $payload['summary']['media_rows_created'] += (int) ($result['summary']['media_rows_created'] ?? 0);
            $payload['summary']['media_rows_reused'] += (int) ($result['summary']['media_rows_reused'] ?? 0);
            $payload['summary']['citation_links_created'] += (int) ($result['summary']['citation_links_created'] ?? 0);

            $mediaIds = $this->mediaIdsFromCapturePayload($result);
            $failed = (int) ($result['summary']['failures'] ?? 0) > 0
                || ((int) ($result['summary']['files_saved'] ?? 0) + (int) ($result['summary']['media_rows_reused'] ?? 0)) === 0;

            if ($failed) {
                $payload['summary']['failures']++;
                $item['status'] = 'failed';
                $item['blockers'] = $this->blockersFromCapturePayload($result);
                $this->recordSourceBackfillFailure((int) $row->id, $item['blockers']);
                $this->recordSourceBackfillOutcomeMemory($row, $item, $result, 0.65);
            } else {
                $item['status'] = ((int) ($result['summary']['media_rows_reused'] ?? 0)) > 0 ? 'media_reused' : 'captured';
                $item['media_ids'] = $mediaIds;
                $this->normalizeSourceMediaCitationFactType((int) $row->id, $mediaIds);
                $this->markSourceCaptureRagStale((int) $row->id, $mediaIds);
                $this->recordSourceBackfillOutcomeMemory($row, $item, $result, 0.85);
            }

            $payload['items'][] = $item;
            $payload['items_processed']++;

            if (! $json) {
                $this->line(sprintf(
                    '  [%s] source=%d media=%s title=%s',
                    $item['status'],
                    (int) $row->id,
                    $mediaIds === [] ? '-' : implode(',', $mediaIds),
                    mb_substr((string) $row->title, 0, 60)
                ));
            }
        }

        if ($payload['summary']['failures'] > 0 && ($payload['summary']['files_saved'] + $payload['summary']['media_rows_reused']) === 0 && ! $dryRun) {
            $payload['status'] = 'failed';
        } elseif ($payload['summary']['failures'] > 0 && ! $dryRun) {
            $payload['status'] = 'partial';
        }

        $this->emitSourcePayload($payload, $json);

        return $payload['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<int>  $sourceIds
     * @return list<object>
     */
    private function sourceBackfillRows(?int $treeId, ?int $personId, array $sourceIds, string $since, int $limit, string $order, bool $retryBlocked): array
    {
        $params = [];
        $sql = "
            SELECT s.id, s.tree_id, s.title, s.url, s.created_at
            FROM genealogy_sources s
            WHERE s.url IS NOT NULL
              AND s.url <> ''
              AND s.url LIKE 'http%'
              {$this->resolveSourceWindowSql($since)}
              AND NOT EXISTS (
                  SELECT 1
                  FROM genealogy_citations c
                  JOIN genealogy_media gm ON gm.id = c.media_id
                  WHERE c.source_id = s.id
                    AND c.media_id IS NOT NULL
                    AND gm.tree_id = s.tree_id
              )
        ";

        if ($treeId !== null) {
            $sql .= ' AND s.tree_id = ?';
            $params[] = $treeId;
        }

        if (! $retryBlocked && Schema::hasColumn('genealogy_sources', 'quality_notes')) {
            $sql .= " AND (s.quality_notes IS NULL OR s.quality_notes NOT LIKE '%source_media_backfill_blocked:%')";
        }

        if ($personId !== null && $personId > 0) {
            $sql .= '
              AND (
                  EXISTS (SELECT 1 FROM genealogy_citations pc WHERE pc.source_id = s.id AND pc.person_id = ?)
                  OR EXISTS (SELECT 1 FROM genealogy_person_sources ps WHERE ps.source_id = s.id AND ps.person_id = ?)
              )
            ';
            $params[] = $personId;
            $params[] = $personId;
        }

        if ($sourceIds !== []) {
            $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
            $sql .= " AND s.id IN ({$placeholders})";
            array_push($params, ...$sourceIds);
        }

        $sql .= $order === 'newest' ? ' ORDER BY s.created_at DESC, s.id DESC' : ' ORDER BY s.created_at ASC, s.id ASC';
        $sql .= ' LIMIT '.max(1, $limit);

        return DB::select($sql, $params);
    }

    private function captureSourceRow(
        GenealogyEvidenceAssetCaptureStorageService $storage,
        object $row,
        int $maxBytes,
        bool $linkConfirmed
    ): array {
        $url = trim((string) $row->url);
        $locatorHash = substr(sha1($url), 0, 16);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $contentType = $this->contentTypeHintForExtension($extension);
        $assetType = $this->assetTypeHint($contentType, $extension, $url);
        $policy = $this->capturePolicyFor($contentType, $extension);
        $label = trim((string) $row->title) !== '' ? (string) $row->title : 'Genealogy source '.$row->id;

        $plan = [
            'schema' => 'genealogy_evidence_asset_capture_plan.v1',
            'label' => $label,
            'provider' => strtolower((string) parse_url($url, PHP_URL_HOST)),
            'asset_type' => $assetType,
            'capture_policy' => $policy,
            'locator_hash' => $locatorHash,
            'capture_ready' => true,
            'approval_ready' => true,
            'tree_id' => (int) $row->tree_id,
            'source_id' => (int) $row->id,
        ];

        $reviewDetails = [
            'schema' => 'genealogy_evidence_asset_capture_review.v1',
            'tree_id' => (int) $row->tree_id,
            'source_target_ref' => 'source:genealogy_sources:'.$row->id,
            'capture_plan_count' => 1,
            'target_storage' => 'ft_reference_area',
            'plans' => [$plan],
            'line_item_decisions' => [[
                'plan_index' => 0,
                'action' => 'attach',
                'reason_code' => 'scheduled_source_media_backfill',
            ]],
            'approved_for_executor' => true,
        ];

        $sourceDetails = [
            'tree_id' => (int) $row->tree_id,
            'source_id' => (int) $row->id,
            'evidence_assets' => [[
                'download_url' => $url,
                'label' => $label,
                'asset_type' => $assetType,
                'content_type' => $contentType,
                'tree_id' => (int) $row->tree_id,
                'source_id' => (int) $row->id,
            ]],
        ];

        return $storage->captureApprovedReview(
            (object) ['id' => 0],
            $reviewDetails,
            $sourceDetails,
            [
                'max_bytes' => $maxBytes,
                'link_confirmed' => $linkConfirmed,
            ]
        );
    }

    private function captureNaraSourceRow(
        object $row,
        int $maxBytes,
        bool $linkConfirmed,
        bool $metadataSnapshot
    ): array {
        $mediaId = $this->createNaraPlaceholderMedia($row);

        /** @var NaraCatalogMediaCaptureService $nara */
        $nara = app(NaraCatalogMediaCaptureService::class);
        $payload = $nara->collect(
            treeId: (int) $row->tree_id,
            limit: 1,
            mediaIds: [$mediaId],
            executeCapture: true,
            downloadConfirmed: true,
            storageConfirmed: true,
            metadataSnapshot: $metadataSnapshot,
            compact: true,
            maxBytes: $maxBytes
        );

        $captured = (int) ($payload['summary']['downloaded'] ?? 0) > 0
            || (int) ($payload['summary']['metadata_snapshots'] ?? 0) > 0
            || DB::table('genealogy_media')->where('id', $mediaId)->where('file_exists', 1)->exists();

        if ($captured && $linkConfirmed) {
            $this->linkSourceMediaCitation((int) $row->id, $mediaId);
        }

        if (! $captured) {
            $this->deleteUncapturedNaraPlaceholder($mediaId);
        }

        $failures = (int) ($payload['summary']['failed'] ?? 0)
            + (int) ($payload['summary']['blocked'] ?? 0)
            + ($captured ? 0 : 1);

        return [
            'summary' => [
                'nara_api_attempts' => 1,
                'nara_downloads' => (int) ($payload['summary']['downloaded'] ?? 0),
                'nara_metadata_snapshots' => (int) ($payload['summary']['metadata_snapshots'] ?? 0),
                'download_attempts' => 1,
                'files_saved' => $captured ? 1 : 0,
                'media_rows_created' => $captured ? 1 : 0,
                'media_rows_reused' => 0,
                'citation_links_created' => $captured && $linkConfirmed ? 1 : 0,
                'failures' => $failures > 0 ? 1 : 0,
            ],
            'items' => [[
                'media_id' => $captured ? $mediaId : null,
                'blockers' => $this->blockersFromNaraPayload($payload),
            ]],
        ];
    }

    private function createNaraPlaceholderMedia(object $row): int
    {
        return (int) DB::table('genealogy_media')->insertGetId([
            'tree_id' => (int) $row->tree_id,
            'uid' => substr('nara-source-'.$row->tree_id.'-'.$row->id.'-'.sha1((string) $row->url), 0, 100),
            'original_path' => (string) $row->url,
            'nextcloud_path' => null,
            'local_filename' => null,
            'file_format' => null,
            'mime_type' => null,
            'file_size' => 0,
            'title' => mb_substr(trim((string) $row->title) !== '' ? (string) $row->title : 'NARA Catalog source '.$row->id, 0, 500),
            'description' => 'Temporary NARA source-media placeholder created by genealogy source media backfill.',
            'analysis_status' => 'pending',
            'enrichment_status' => 'pending',
            'face_sync_status' => 'pending',
            'media_type' => 'document',
            'file_exists' => 0,
            'imported_at' => now(),
            'privacy' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function linkSourceMediaCitation(int $sourceId, int $mediaId): void
    {
        $exists = DB::table('genealogy_citations')
            ->where('source_id', $sourceId)
            ->where('media_id', $mediaId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('genealogy_citations')->insert([
            'source_id' => $sourceId,
            'media_id' => $mediaId,
            'fact_type' => 'source_media',
            'text' => 'NARA Catalog media captured from source URL by source-media backfill.',
            'created_at' => now(),
        ]);
    }

    /**
     * @param  list<int>  $mediaIds
     */
    private function normalizeSourceMediaCitationFactType(int $sourceId, array $mediaIds): void
    {
        if ($mediaIds === []) {
            return;
        }

        DB::table('genealogy_citations')
            ->where('source_id', $sourceId)
            ->whereIn('media_id', $mediaIds)
            ->where(function ($query): void {
                $query->whereNull('fact_type')->orWhere('fact_type', '');
            })
            ->update(['fact_type' => 'source_media']);
    }

    private function deleteUncapturedNaraPlaceholder(int $mediaId): void
    {
        $hasLinks = DB::table('genealogy_citations')->where('media_id', $mediaId)->exists()
            || DB::table('genealogy_person_media')->where('media_id', $mediaId)->exists()
            || DB::table('genealogy_family_media')->where('media_id', $mediaId)->exists();

        if (! $hasLinks) {
            DB::table('genealogy_media')->where('id', $mediaId)->where('file_exists', 0)->delete();
        }
    }

    /**
     * @return list<string>
     */
    private function blockersFromNaraPayload(array $payload): array
    {
        $blockers = [];
        foreach (($payload['blockers'] ?? []) as $blocker) {
            if (is_scalar($blocker) && (string) $blocker !== '') {
                $blockers[(string) $blocker] = true;
            }
        }
        foreach (($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (($item['blockers'] ?? []) as $blocker) {
                if (is_scalar($blocker) && (string) $blocker !== '') {
                    $blockers[(string) $blocker] = true;
                }
            }
        }

        return array_keys($blockers);
    }

    private function isNaraCatalogUrl(string $url): bool
    {
        return preg_match('~^https?://catalog\.archives\.gov/id/\d+~i', trim($url)) === 1;
    }

    private function resolveSourceWindowSql(string $since): string
    {
        if ($since === 'all' || $since === '' || $since === '0') {
            return '';
        }
        if (preg_match('/^(\d+)([hd])$/i', trim($since), $m)) {
            $n = (int) $m[1];
            $unit = strtoupper($m[2]) === 'D' ? 'DAY' : 'HOUR';

            return "AND s.created_at > DATE_SUB(NOW(), INTERVAL {$n} {$unit})";
        }

        $n = (int) $since;
        if ($n > 0) {
            return "AND s.created_at > DATE_SUB(NOW(), INTERVAL {$n} DAY)";
        }

        return '';
    }

    /**
     * @return list<int>
     */
    private function sourceIdsOption(): array
    {
        $values = (array) $this->option('source-id');
        $ids = [];
        foreach ($values as $value) {
            foreach (explode(',', (string) $value) as $part) {
                $id = (int) trim($part);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private function treeIdOption(): ?int
    {
        $value = strtolower(trim((string) $this->option('tree')));
        if ($value === '' || $value === 'all' || $value === '0') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function maxBytesOption(): int
    {
        $value = $this->option('max-bytes');
        if ($value !== null && (int) $value > 0) {
            return (int) $value;
        }

        return max(1, (int) config('genealogy.evidence_asset_capture.max_bytes', 26214400));
    }

    private function contentTypeHintForExtension(string $extension): string
    {
        return match (strtolower(trim($extension, '.'))) {
            'jpg', 'jpeg', 'jfif' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'tif', 'tiff' => 'image/tiff',
            'jp2', 'j2k', 'jpf', 'jpx' => 'image/jp2',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'rtf' => 'application/rtf',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            default => 'text/html',
        };
    }

    private function assetTypeHint(string $contentType, string $extension, string $url): string
    {
        if (str_starts_with($contentType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($contentType, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($contentType, 'video/')) {
            return 'video';
        }
        if ($contentType === 'application/pdf') {
            return 'pdf';
        }
        if (in_array(strtolower($extension), ['html', 'htm'], true) || parse_url($url, PHP_URL_SCHEME) !== null) {
            return 'html';
        }

        return 'document';
    }

    private function capturePolicyFor(string $contentType, string $extension): string
    {
        if ($contentType === 'text/html' || in_array(strtolower($extension), ['html', 'htm', ''], true)) {
            return 'html_snapshot_allowed';
        }

        return 'direct_download_allowed';
    }

    /**
     * @return list<int>
     */
    private function mediaIdsFromCapturePayload(array $payload): array
    {
        $ids = [];
        foreach (($payload['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['media_id']) && (int) $item['media_id'] > 0) {
                $ids[(int) $item['media_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return list<string>
     */
    private function blockersFromCapturePayload(array $payload): array
    {
        $blockers = [];
        foreach (($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (($item['blockers'] ?? []) as $blocker) {
                if (is_scalar($blocker) && (string) $blocker !== '') {
                    $blockers[(string) $blocker] = true;
                }
            }
        }

        return array_keys($blockers);
    }

    /**
     * @param  list<int>  $mediaIds
     */
    private function markSourceCaptureRagStale(int $sourceId, array $mediaIds): void
    {
        DB::table('genealogy_sources')
            ->where('id', $sourceId)
            ->update([
                'rag_indexed_at' => null,
                'updated_at' => now(),
            ]);

        if ($mediaIds !== []) {
            DB::table('genealogy_media')
                ->whereIn('id', $mediaIds)
                ->update([
                    'rag_indexed_at' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  list<string>  $blockers
     */
    private function recordSourceBackfillFailure(int $sourceId, array $blockers): void
    {
        if (! Schema::hasColumn('genealogy_sources', 'quality_notes')) {
            return;
        }

        $blockerText = implode(',', array_slice(array_filter($blockers), 0, 8));
        if ($blockerText === '') {
            $blockerText = 'unknown';
        }

        $note = sprintf(
            '[%s] source_media_backfill_blocked: %s',
            now()->toDateString(),
            $blockerText
        );

        $existing = (string) DB::table('genealogy_sources')->where('id', $sourceId)->value('quality_notes');
        if (str_contains($existing, 'source_media_backfill_blocked:')) {
            return;
        }

        DB::table('genealogy_sources')
            ->where('id', $sourceId)
            ->update([
                'quality_notes' => trim($existing."\n".$note),
                'rag_indexed_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $result
     */
    private function recordSourceBackfillOutcomeMemory(object $row, array $item, array $result, float $confidence): void
    {
        if (! Schema::hasTable('agent_semantic_memory') || ! Schema::hasTable('agent_semantic_fact_sources')) {
            return;
        }

        try {
            app(GenealogySemanticMemoryService::class)->recordSourceBackfillOutcome(
                (int) $row->tree_id,
                (int) $row->id,
                (string) ($item['status'] ?? 'unknown'),
                [
                    'source_id' => (int) $row->id,
                    'title' => mb_substr((string) $row->title, 0, 180),
                    'url_host' => strtolower((string) parse_url((string) $row->url, PHP_URL_HOST)),
                    'status' => (string) ($item['status'] ?? 'unknown'),
                    'media_ids' => array_values(array_filter(
                        array_map(static fn ($id): int => (int) $id, $item['media_ids'] ?? []),
                        static fn (int $id): bool => $id > 0
                    )),
                    'blockers' => array_values(array_slice(array_filter(
                        array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '', $item['blockers'] ?? [])
                    ), 0, 8)),
                    'summary' => array_intersect_key($result['summary'] ?? [], array_flip([
                        'nara_api_attempts',
                        'nara_downloads',
                        'nara_metadata_snapshots',
                        'download_attempts',
                        'files_saved',
                        'media_rows_created',
                        'media_rows_reused',
                        'citation_links_created',
                        'failures',
                    ])),
                ],
                'genealogy:backfill-source-media',
                $confidence
            );
        } catch (\Throwable $e) {
            Log::warning('GenealogyBackfillSourceMedia: source backfill memory write failed', [
                'source_id' => (int) $row->id,
                'status' => $item['status'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function emitSourcePayload(array $payload, bool $json): void
    {
        if ($json) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        if (isset($payload['error'])) {
            $this->error((string) $payload['error']);

            return;
        }

        $summary = $payload['summary'] ?? [];
        $this->info(sprintf(
            'Done. status=%s candidates=%d saved=%d reused=%d failures=%d',
            (string) ($payload['status'] ?? 'unknown'),
            (int) ($summary['candidates'] ?? 0),
            (int) ($summary['files_saved'] ?? 0),
            (int) ($summary['media_rows_reused'] ?? 0),
            (int) ($summary['failures'] ?? 0)
        ));
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
