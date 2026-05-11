<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class GenealogyEvidenceAssetCaptureStorageService
{
    private const SUPPORTED_POLICIES = [
        'direct_download_allowed',
        'html_snapshot_allowed',
        'already_local_reference',
    ];

    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff',
        'pdf', 'html', 'htm', 'txt',
        'mp3', 'wav', 'ogg', 'flac', 'm4a',
        'mp4', 'm4v', 'mov', 'webm',
    ];

    private const ALLOWED_CONTENT_TYPE_PREFIXES = [
        'image/',
        'audio/',
        'video/',
    ];

    private const ALLOWED_CONTENT_TYPES = [
        'application/pdf',
        'application/octet-stream',
        'text/html',
        'text/plain',
    ];

    /**
     * @param  array<string, mixed>  $reviewDetails
     * @param  array<string, mixed>  $sourceDetails
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function captureApprovedReview(object $row, array $reviewDetails, array $sourceDetails, array $options): array
    {
        $plans = is_array($reviewDetails['plans'] ?? null) ? array_values($reviewDetails['plans']) : [];
        $candidates = $this->rawCandidates($sourceDetails);
        $maxBytes = max(1, (int) ($options['max_bytes'] ?? config('genealogy.evidence_asset_capture.max_bytes', 26214400)));
        $linkConfirmed = (bool) ($options['link_confirmed'] ?? false);

        $result = [
            'schema' => 'genealogy_evidence_asset_capture_execution.v1',
            'review_type' => GenealogyEvidenceAssetCaptureReviewService::REVIEW_TYPE,
            'review_row_id' => (int) ($row->id ?? 0),
            'source_target_ref_present' => is_scalar($reviewDetails['source_target_ref'] ?? null),
            'capture_plan_count' => count($plans),
            'executed_at' => now()->toIso8601String(),
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'genealogy_link_attempted' => false,
            'canonical_write_attempted' => false,
            'summary' => [
                'plans_seen' => count($plans),
                'plans_matched' => 0,
                'plans_unmatched' => 0,
                'plans_skipped' => 0,
                'download_attempts' => 0,
                'storage_write_attempts' => 0,
                'files_saved' => 0,
                'media_rows_created' => 0,
                'media_rows_reused' => 0,
                'person_links_created' => 0,
                'family_links_created' => 0,
                'citation_links_created' => 0,
                'link_skipped_confirmation_required' => 0,
                'failures' => 0,
            ],
            'items' => [],
        ];

        foreach ($plans as $plan) {
            if (! is_array($plan)) {
                $result['summary']['plans_skipped']++;
                continue;
            }

            $item = $this->capturePlan($plan, $candidates, $maxBytes, $linkConfirmed);
            $this->mergeItemSummary($result, $item);
            $result['items'][] = $this->redactItem($item);
        }

        $result['download_attempted'] = $result['summary']['download_attempts'] > 0;
        $result['storage_write_attempted'] = $result['summary']['storage_write_attempts'] > 0;
        $result['genealogy_link_attempted'] = ($result['summary']['person_links_created']
            + $result['summary']['family_links_created']
            + $result['summary']['citation_links_created']) > 0;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function capturePlan(array $plan, array $candidates, int $maxBytes, bool $linkConfirmed): array
    {
        $policy = $this->safePolicy($plan['capture_policy'] ?? null);
        $hash = $this->safeHash($plan['locator_hash'] ?? null);
        $candidate = $this->matchCandidate($hash, $candidates);

        $item = [
            'schema' => 'genealogy_evidence_asset_capture_execution_item.v1',
            'locator_hash' => $hash,
            'capture_policy' => $policy,
            'status' => 'pending',
            'matched' => $candidate !== null,
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'media_registered' => false,
            'media_reused' => false,
            'media_id' => null,
            'genealogy_link_attempted' => false,
            'links' => [],
            'blockers' => [],
        ];

        if ($candidate === null) {
            $item['status'] = 'unmatched_locator_hash';
            $item['blockers'][] = 'source_candidate_not_found';

            return $item;
        }

        if (! in_array($policy, self::SUPPORTED_POLICIES, true)) {
            $item['status'] = 'skipped';
            $item['blockers'][] = 'unsupported_capture_policy';

            return $item;
        }

        $treeId = $this->resolveTreeId($candidate);
        if ($treeId === null) {
            $item['status'] = 'blocked';
            $item['blockers'][] = 'tree_id_unresolved';

            return $item;
        }

        if (! Schema::hasTable('genealogy_media')) {
            $item['status'] = 'blocked';
            $item['blockers'][] = 'genealogy_media_table_missing';

            return $item;
        }

        $candidate['tree_id'] = $treeId;
        $existing = $this->existingMedia($candidate);
        if ($existing !== null) {
            $item['status'] = 'media_reused';
            $item['media_registered'] = true;
            $item['media_reused'] = true;
            $item['media_id'] = (int) $existing->id;
            $item['links'] = $this->linkMedia($candidate, (int) $existing->id, $linkConfirmed);
            $item['genealogy_link_attempted'] = $linkConfirmed && $item['links'] !== [];

            return $item;
        }

        if ($policy === 'already_local_reference') {
            $stored = $this->captureLocalReference($candidate, $maxBytes, $item);
        } else {
            $stored = $this->captureRemoteReference($candidate, $policy, $maxBytes, $item);
        }

        if (($stored['ok'] ?? false) !== true) {
            $item['status'] = (string) ($stored['status'] ?? 'capture_failed');
            $item['blockers'] = array_values(array_unique(array_merge(
                $item['blockers'],
                is_array($stored['blockers'] ?? null) ? $stored['blockers'] : ['capture_failed'],
            )));

            return $item;
        }

        $item['storage_write_attempted'] = true;
        $mediaId = $this->registerMedia($candidate, $stored);
        $item['status'] = 'captured';
        $item['media_registered'] = true;
        $item['media_id'] = $mediaId;
        $item['links'] = $this->linkMedia($candidate, $mediaId, $linkConfirmed);
        $item['genealogy_link_attempted'] = $linkConfirmed && $item['links'] !== [];

        return $item;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function captureRemoteReference(array $candidate, string $policy, int $maxBytes, array &$item): array
    {
        $locator = (string) ($candidate['locator'] ?? '');
        $host = strtolower((string) parse_url($locator, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($locator, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['ok' => false, 'status' => 'blocked', 'blockers' => ['unsupported_remote_scheme']];
        }

        if ($this->isBlockedRemoteHost($host)) {
            return ['ok' => false, 'status' => 'blocked', 'blockers' => ['manual_or_paywalled_provider']];
        }

        if ($this->looksSensitive($locator)) {
            return ['ok' => false, 'status' => 'blocked', 'blockers' => ['sensitive_locator_rejected']];
        }

        $item['download_attempted'] = true;

        try {
            $response = Http::withHeaders([
                'Accept' => $policy === 'html_snapshot_allowed'
                    ? 'text/html,application/xhtml+xml,text/plain;q=0.8,*/*;q=0.2'
                    : 'image/*,application/pdf,audio/*,video/*,*/*;q=0.2',
                'User-Agent' => 'PLOS-GenealogyEvidenceCapture/1.0',
            ])
                ->connectTimeout(10)
                ->timeout(45)
                ->get($locator);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'status' => 'download_failed',
                'blockers' => ['http_request_failed'],
                'error_class' => $exception::class,
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'status' => 'download_failed',
                'blockers' => ['http_status_'.$response->status()],
            ];
        }

        $body = $response->body();
        $bytes = strlen($body);
        $contentType = $this->normalizeContentType($response->header('Content-Type') ?? $candidate['content_type'] ?? null);
        $extension = $this->extensionFor($contentType, $candidate['extension'] ?? null, $policy);

        if ($bytes <= 0) {
            return ['ok' => false, 'status' => 'download_failed', 'blockers' => ['empty_response_body']];
        }

        if ($bytes > $maxBytes) {
            return ['ok' => false, 'status' => 'blocked', 'blockers' => ['content_size_limit_exceeded']];
        }

        if (! $this->contentAllowed($policy, $contentType, $extension, $body)) {
            return ['ok' => false, 'status' => 'blocked', 'blockers' => ['content_type_not_allowed']];
        }

        $path = $this->targetPath($candidate, $extension);
        try {
            File::ensureDirectoryExists(dirname($path), 0755, true);
            if (file_put_contents($path, $body, LOCK_EX) === false) {
                return ['ok' => false, 'status' => 'storage_failed', 'blockers' => ['file_write_failed']];
            }
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'status' => 'storage_failed',
                'blockers' => ['file_write_failed'],
                'error_class' => $exception::class,
            ];
        }

        return [
            'ok' => true,
            'status' => 'captured',
            'path' => $path,
            'filename' => basename($path),
            'content_type' => $contentType,
            'extension' => $extension,
            'bytes' => $bytes,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function captureLocalReference(array $candidate, int $maxBytes, array &$item): array
    {
        $locator = (string) ($candidate['locator'] ?? '');
        $sourcePath = realpath($locator);
        if ($sourcePath === false || ! is_file($sourcePath)) {
            return ['ok' => false, 'status' => 'blocked', 'blockers' => ['local_reference_missing']];
        }

        if (! $this->isAllowedLocalPath($sourcePath)) {
            return ['ok' => false, 'status' => 'blocked', 'blockers' => ['local_reference_outside_allowed_roots']];
        }

        $bytes = filesize($sourcePath);
        if ($bytes === false || $bytes <= 0) {
            return ['ok' => false, 'status' => 'blocked', 'blockers' => ['local_reference_empty']];
        }
        if ($bytes > $maxBytes) {
            return ['ok' => false, 'status' => 'blocked', 'blockers' => ['content_size_limit_exceeded']];
        }

        $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        $contentType = $this->normalizeContentType(function_exists('mime_content_type') ? mime_content_type($sourcePath) : null);
        if (! $this->extensionAllowed($extension)) {
            return ['ok' => false, 'status' => 'blocked', 'blockers' => ['extension_not_allowed']];
        }

        $path = $this->targetPath($candidate, $extension !== '' ? $extension : $this->extensionFor($contentType, null, 'direct_download_allowed'));
        try {
            File::ensureDirectoryExists(dirname($path), 0755, true);
            if (! File::copy($sourcePath, $path)) {
                return ['ok' => false, 'status' => 'storage_failed', 'blockers' => ['file_copy_failed']];
            }
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'status' => 'storage_failed',
                'blockers' => ['file_copy_failed'],
                'error_class' => $exception::class,
            ];
        }
        $item['storage_write_attempted'] = true;

        return [
            'ok' => true,
            'status' => 'captured',
            'path' => $path,
            'filename' => basename($path),
            'content_type' => $contentType,
            'extension' => strtolower((string) pathinfo($path, PATHINFO_EXTENSION)),
            'bytes' => (int) $bytes,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $stored
     */
    private function registerMedia(array $candidate, array $stored): int
    {
        $now = now();
        $mediaType = $this->mediaType($candidate, (string) ($stored['content_type'] ?? ''));

        return (int) DB::table('genealogy_media')->insertGetId([
            'tree_id' => (int) $candidate['tree_id'],
            'uid' => $this->mediaUid($candidate),
            'original_path' => (string) ($candidate['locator'] ?? ''),
            'nextcloud_path' => (string) ($stored['path'] ?? ''),
            'local_filename' => (string) ($stored['filename'] ?? ''),
            'file_format' => substr((string) ($stored['extension'] ?? ''), 0, 20),
            'mime_type' => substr((string) ($stored['content_type'] ?? 'application/octet-stream'), 0, 100),
            'file_size' => (int) ($stored['bytes'] ?? 0),
            'title' => $this->safeLabel($candidate['label'] ?? 'Evidence asset'),
            'description' => 'Captured from approved genealogy evidence asset capture review.',
            'analysis_status' => 'pending',
            'enrichment_status' => 'pending',
            'source_folder' => dirname((string) ($stored['path'] ?? '')),
            'media_type' => $mediaType,
            'file_exists' => 1,
            'imported_at' => $now,
            'privacy' => 'private',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<array<string, mixed>>
     */
    private function linkMedia(array $candidate, int $mediaId, bool $linkConfirmed): array
    {
        $links = [];
        $personId = $this->positiveInt($candidate['person_id'] ?? null);
        $familyId = $this->positiveInt($candidate['family_id'] ?? null);
        $sourceId = $this->positiveInt($candidate['source_id'] ?? null);

        if (! $linkConfirmed) {
            if ($personId !== null || $familyId !== null || $sourceId !== null) {
                $links[] = ['scope' => 'confirmation_required', 'created' => false];
            }

            return $links;
        }

        if ($personId !== null && Schema::hasTable('genealogy_person_media') && $this->rowExists('genealogy_persons', $personId)) {
            $exists = DB::table('genealogy_person_media')
                ->where('person_id', $personId)
                ->where('media_id', $mediaId)
                ->exists();
            DB::table('genealogy_person_media')->insertOrIgnore([
                'person_id' => $personId,
                'media_id' => $mediaId,
                'is_primary' => 0,
                'face_confirmed' => 0,
                'notes' => 'Approved evidence asset capture',
                'created_at' => now(),
            ]);
            $links[] = ['scope' => 'person', 'created' => ! $exists];
        }

        if ($familyId !== null && Schema::hasTable('genealogy_family_media') && $this->rowExists('genealogy_families', $familyId)) {
            $exists = DB::table('genealogy_family_media')
                ->where('family_id', $familyId)
                ->where('media_id', $mediaId)
                ->exists();
            DB::table('genealogy_family_media')->insertOrIgnore([
                'family_id' => $familyId,
                'media_id' => $mediaId,
                'created_at' => now(),
            ]);
            $links[] = ['scope' => 'family', 'created' => ! $exists];
        }

        if ($sourceId !== null && Schema::hasTable('genealogy_citations') && $this->rowExists('genealogy_sources', $sourceId)) {
            $exists = DB::table('genealogy_citations')
                ->where('source_id', $sourceId)
                ->where('media_id', $mediaId)
                ->when($personId !== null, fn ($query) => $query->where('person_id', $personId))
                ->when($familyId !== null, fn ($query) => $query->where('family_id', $familyId))
                ->exists();

            if (! $exists) {
                DB::table('genealogy_citations')->insert([
                    'source_id' => $sourceId,
                    'person_id' => $personId,
                    'family_id' => $familyId,
                    'media_id' => $mediaId,
                    'text' => 'Approved evidence asset capture media link.',
                    'created_at' => now(),
                ]);
                $links[] = ['scope' => 'source_citation', 'created' => true];
            }
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $item
     */
    private function mergeItemSummary(array &$result, array $item): void
    {
        if (($item['matched'] ?? false) === true) {
            $result['summary']['plans_matched']++;
        } else {
            $result['summary']['plans_unmatched']++;
        }

        if (($item['download_attempted'] ?? false) === true) {
            $result['summary']['download_attempts']++;
        }
        if (($item['storage_write_attempted'] ?? false) === true) {
            $result['summary']['storage_write_attempts']++;
        }
        if (($item['status'] ?? null) === 'captured') {
            $result['summary']['files_saved']++;
            $result['summary']['media_rows_created']++;
        }
        if (($item['media_reused'] ?? false) === true) {
            $result['summary']['media_rows_reused']++;
        }
        if (in_array($item['status'] ?? '', ['skipped'], true)) {
            $result['summary']['plans_skipped']++;
        }
        if (($item['blockers'] ?? []) !== []) {
            $result['summary']['failures']++;
        }

        foreach (($item['links'] ?? []) as $link) {
            if (! is_array($link)) {
                continue;
            }

            $scope = $link['scope'] ?? null;
            if ($scope === 'person' && ($link['created'] ?? false) === true) {
                $result['summary']['person_links_created']++;
            } elseif ($scope === 'family' && ($link['created'] ?? false) === true) {
                $result['summary']['family_links_created']++;
            } elseif ($scope === 'source_citation' && ($link['created'] ?? false) === true) {
                $result['summary']['citation_links_created']++;
            } elseif ($scope === 'confirmation_required') {
                $result['summary']['link_skipped_confirmation_required']++;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<array<string, mixed>>
     */
    private function rawCandidates(array $details): array
    {
        $base = [
            'tree_id' => $this->positiveInt($details['tree_id'] ?? null) ?? $this->positiveInt($details['identity']['tree_id'] ?? null),
            'person_id' => $this->positiveInt($details['person_id'] ?? null)
                ?? $this->positiveInt($details['target_person_id'] ?? null)
                ?? $this->positiveInt($details['identity']['person_id'] ?? null)
                ?? $this->positiveInt($details['identity']['target_person_id'] ?? null),
            'family_id' => $this->positiveInt($details['family_id'] ?? null)
                ?? $this->positiveInt($details['target_family_id'] ?? null)
                ?? $this->positiveInt($details['identity']['family_id'] ?? null),
            'source_id' => $this->positiveInt($details['source_id'] ?? null) ?? $this->positiveInt($details['genealogy_source_id'] ?? null),
        ];

        $candidates = [];
        foreach (['evidence_assets', 'source_assets', 'media_assets', 'assets'] as $key) {
            foreach ($this->arrayItems($details[$key] ?? null) as $asset) {
                $candidate = $this->candidateFromValue($asset, $key, $base);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        foreach ($this->arrayItems($details['sources'] ?? null) as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach ($this->arrayItems($source['assets'] ?? null) as $asset) {
                $candidate = $this->candidateFromValue($asset, 'sources.assets', $base, $source);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }

            $candidate = $this->candidateFromValue($source, 'sources', $base, $source);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        $sourceLocator = $this->candidateFromValue($details['source_locator'] ?? null, 'source_locator', $base);
        if ($sourceLocator !== null) {
            $candidates[] = $sourceLocator;
        }

        foreach ($this->arrayItems($details['source_locators'] ?? null) as $locator) {
            $candidate = $this->candidateFromValue($locator, 'source_locators', $base);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $this->dedupeCandidates($candidates);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>|null  $sourceContext
     * @return array<string, mixed>|null
     */
    private function candidateFromValue(mixed $value, string $origin, array $base, ?array $sourceContext = null): ?array
    {
        $asset = is_array($value) ? $value : ['url' => $value];
        $locator = $this->firstScalar($asset, [
            'download_url',
            'source_url',
            'url',
            'uri',
            'locator',
            'source_locator',
            'path',
        ]);

        if ($locator === null) {
            return null;
        }

        $locator = trim($locator);
        if ($locator === '' || $this->looksSensitive($locator)) {
            return null;
        }

        $sourceContext ??= [];
        $sha1 = sha1($locator);
        $path = (string) (parse_url($locator, PHP_URL_PATH) ?? $locator);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $contentType = $this->normalizeContentType($this->firstScalar($asset, ['content_type', 'mime_type']));

        return [
            'origin' => $origin,
            'locator' => $locator,
            'locator_hash' => substr($sha1, 0, 16),
            'locator_sha1' => $sha1,
            'label' => $this->firstScalar($asset, ['label', 'title', 'name', 'filename'])
                ?? $this->firstScalar($sourceContext, ['label', 'title', 'name'])
                ?? basename($path)
                ?: 'Evidence asset',
            'content_type' => $contentType,
            'extension' => $extension,
            'asset_type' => $this->assetType($contentType, $extension, $locator),
            'tree_id' => $this->positiveInt($asset['tree_id'] ?? null)
                ?? $this->positiveInt($sourceContext['tree_id'] ?? null)
                ?? $base['tree_id'],
            'person_id' => $this->positiveInt($asset['person_id'] ?? null)
                ?? $this->positiveInt($asset['target_person_id'] ?? null)
                ?? $this->positiveInt($sourceContext['person_id'] ?? null)
                ?? $this->positiveInt($sourceContext['target_person_id'] ?? null)
                ?? $base['person_id'],
            'family_id' => $this->positiveInt($asset['family_id'] ?? null)
                ?? $this->positiveInt($asset['target_family_id'] ?? null)
                ?? $this->positiveInt($sourceContext['family_id'] ?? null)
                ?? $this->positiveInt($sourceContext['target_family_id'] ?? null)
                ?? $base['family_id'],
            'source_id' => $this->positiveInt($asset['source_id'] ?? null)
                ?? $this->positiveInt($asset['genealogy_source_id'] ?? null)
                ?? $this->positiveInt($sourceContext['source_id'] ?? null)
                ?? $this->positiveInt($sourceContext['genealogy_source_id'] ?? null)
                ?? $base['source_id'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function dedupeCandidates(array $candidates): array
    {
        $seen = [];
        $deduped = [];
        foreach ($candidates as $candidate) {
            $hash = (string) ($candidate['locator_hash'] ?? '');
            if ($hash === '' || isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $deduped[] = $candidate;
        }

        return $deduped;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function matchCandidate(?string $planHash, array $candidates): ?array
    {
        if ($planHash === null) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $hash16 = strtolower((string) ($candidate['locator_hash'] ?? ''));
            $hash40 = strtolower((string) ($candidate['locator_sha1'] ?? ''));
            if ($hash16 === $planHash || $hash40 === $planHash) {
                return $candidate;
            }
            if (($hash16 !== '' && str_starts_with($planHash, $hash16))
                || ($hash40 !== '' && str_starts_with($hash40, $planHash))) {
                return $candidate;
            }
        }

        return null;
    }

    private function existingMedia(array $candidate): ?object
    {
        return DB::table('genealogy_media')
            ->select(['id'])
            ->where('tree_id', (int) $candidate['tree_id'])
            ->where('uid', $this->mediaUid($candidate))
            ->orderBy('id')
            ->first();
    }

    private function mediaUid(array $candidate): string
    {
        return substr('gea-'.$candidate['tree_id'].'-'.$candidate['locator_hash'], 0, 100);
    }

    private function targetPath(array $candidate, string $extension): string
    {
        $root = rtrim((string) config('genealogy.ft_reference_root', storage_path('app/genealogy/ft-reference')), '/');
        $subdir = now()->format('Y/m/d');
        $slug = Str::slug($this->safeLabel($candidate['label'] ?? 'Evidence asset'));
        if ($slug === '') {
            $slug = 'evidence-asset';
        }

        $extension = strtolower(trim($extension, '.'));
        if (! $this->extensionAllowed($extension)) {
            $extension = 'bin';
        }

        return $root.'/evidence-assets/'.$subdir.'/'.$slug.'-'.$candidate['locator_hash'].'.'.$extension;
    }

    private function resolveTreeId(array $candidate): ?int
    {
        $treeId = $this->positiveInt($candidate['tree_id'] ?? null);
        if ($treeId !== null) {
            return $treeId;
        }

        $personId = $this->positiveInt($candidate['person_id'] ?? null);
        if ($personId !== null && Schema::hasTable('genealogy_persons')) {
            $value = DB::table('genealogy_persons')->where('id', $personId)->value('tree_id');
            $treeId = $this->positiveInt($value);
            if ($treeId !== null) {
                return $treeId;
            }
        }

        $familyId = $this->positiveInt($candidate['family_id'] ?? null);
        if ($familyId !== null && Schema::hasTable('genealogy_families')) {
            $value = DB::table('genealogy_families')->where('id', $familyId)->value('tree_id');
            $treeId = $this->positiveInt($value);
            if ($treeId !== null) {
                return $treeId;
            }
        }

        $sourceId = $this->positiveInt($candidate['source_id'] ?? null);
        if ($sourceId !== null && Schema::hasTable('genealogy_sources')) {
            $value = DB::table('genealogy_sources')->where('id', $sourceId)->value('tree_id');

            return $this->positiveInt($value);
        }

        return null;
    }

    private function mediaType(array $candidate, string $contentType): string
    {
        $label = strtolower((string) ($candidate['label'] ?? ''));
        $assetType = strtolower((string) ($candidate['asset_type'] ?? ''));
        if (str_contains($label, 'headstone') || str_contains($label, 'tombstone') || str_contains($label, 'grave')) {
            return 'headstone';
        }
        if (str_contains($label, 'certificate')) {
            return 'certificate';
        }
        if (str_contains($label, 'census')) {
            return 'census';
        }
        if (str_contains($label, 'obituary')) {
            return 'obituary';
        }
        if ($assetType === 'audio' || str_starts_with($contentType, 'audio/')) {
            return 'audio';
        }
        if ($assetType === 'video' || str_starts_with($contentType, 'video/')) {
            return 'video';
        }
        if (in_array($assetType, ['pdf', 'html'], true) || str_contains($contentType, 'pdf') || str_contains($contentType, 'html')) {
            return 'document';
        }

        return 'photo';
    }

    private function contentAllowed(string $policy, string $contentType, string $extension, string $body): bool
    {
        if (! $this->extensionAllowed($extension)) {
            return false;
        }

        if ($policy === 'html_snapshot_allowed') {
            return $contentType === 'text/html'
                || $contentType === 'application/xhtml+xml'
                || str_starts_with(ltrim(strtolower(substr($body, 0, 200))), '<!doctype html')
                || str_starts_with(ltrim(strtolower(substr($body, 0, 200))), '<html');
        }

        if (in_array($contentType, self::ALLOWED_CONTENT_TYPES, true)) {
            return true;
        }

        foreach (self::ALLOWED_CONTENT_TYPE_PREFIXES as $prefix) {
            if (str_starts_with($contentType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function extensionFor(string $contentType, mixed $candidateExtension, string $policy): string
    {
        $extension = strtolower(trim((string) $candidateExtension, '.'));
        if ($this->extensionAllowed($extension)) {
            return $extension === 'htm' ? 'html' : $extension;
        }

        return match ($contentType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/tiff' => 'tif',
            'application/pdf' => 'pdf',
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/flac' => 'flac',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'text/plain' => 'txt',
            default => $policy === 'html_snapshot_allowed' ? 'html' : 'bin',
        };
    }

    private function extensionAllowed(string $extension): bool
    {
        return in_array(strtolower(trim($extension, '.')), self::ALLOWED_EXTENSIONS, true);
    }

    private function normalizeContentType(mixed $value): string
    {
        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return 'application/octet-stream';
        }

        return trim(explode(';', $text, 2)[0]);
    }

    private function assetType(string $contentType, string $extension, string $locator): string
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
        if ($contentType === 'text/html') {
            return 'html';
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff'], true)) {
            return 'image';
        }
        if (in_array($extension, ['mp3', 'wav', 'ogg', 'flac', 'm4a'], true)) {
            return 'audio';
        }
        if (in_array($extension, ['mp4', 'm4v', 'mov', 'webm'], true)) {
            return 'video';
        }
        if ($extension === 'pdf') {
            return 'pdf';
        }
        if (in_array($extension, ['html', 'htm'], true) || parse_url($locator, PHP_URL_SCHEME) !== null) {
            return 'html';
        }

        return 'local_reference';
    }

    private function isBlockedRemoteHost(string $host): bool
    {
        foreach ($this->blockedRemoteHostSuffixes() as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function blockedRemoteHostSuffixes(): array
    {
        $suffixes = array_merge(
            (array) config('scraping.manual_only_domains', []),
            (array) config('genealogy.evidence_asset_capture.blocked_remote_host_suffixes', []),
        );

        $normalized = [];
        foreach ($suffixes as $suffix) {
            $suffix = strtolower(trim((string) $suffix));
            if ($suffix !== '') {
                $normalized[$suffix] = true;
            }
        }

        return array_keys($normalized);
    }

    private function isAllowedLocalPath(string $path): bool
    {
        $roots = array_filter(array_map(static fn ($root): string => rtrim((string) $root, '/'), [
            config('genealogy.ft_reference_root'),
            config('genealogy.nextcloud_root'),
            config('genealogy.legacy_media_root'),
            config('genealogy.face_sync_root'),
        ]));

        foreach ($roots as $root) {
            $realRoot = realpath($root);
            if ($realRoot !== false && str_starts_with($path, rtrim($realRoot, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function rowExists(string $table, int $id): bool
    {
        return Schema::hasTable($table) && DB::table($table)->where('id', $id)->exists();
    }

    /**
     * @return list<mixed>
     */
    private function arrayItems(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private function firstScalar(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function safePolicy(mixed $value): string
    {
        $policy = strtolower(trim((string) $value));

        return preg_match('/^[a-z0-9_]+$/', $policy) === 1 ? $policy : 'unknown';
    }

    private function safeHash(mixed $value): ?string
    {
        $hash = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{8,40}$/', $hash) === 1 ? $hash : null;
    }

    private function safeLabel(mixed $value): string
    {
        $label = trim(strip_tags((string) $value));
        $label = preg_replace('/[[:cntrl:]]+/', ' ', $label) ?? '';
        $label = preg_replace('/\\s+/', ' ', $label) ?? '';

        return $label !== '' ? substr($label, 0, 180) : 'Evidence asset';
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function looksSensitive(string $value): bool
    {
        return preg_match('/(?:token|secret|password|api[_-]?key|access[_-]?key|session)=/i', $value) === 1
            || preg_match('/^javascript:/i', trim($value)) === 1;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function redactItem(array $item): array
    {
        return [
            'schema' => $item['schema'] ?? 'genealogy_evidence_asset_capture_execution_item.v1',
            'locator_hash' => $item['locator_hash'] ?? null,
            'capture_policy' => $item['capture_policy'] ?? 'unknown',
            'status' => $item['status'] ?? 'unknown',
            'matched' => (bool) ($item['matched'] ?? false),
            'download_attempted' => (bool) ($item['download_attempted'] ?? false),
            'storage_write_attempted' => (bool) ($item['storage_write_attempted'] ?? false),
            'media_registered' => (bool) ($item['media_registered'] ?? false),
            'media_reused' => (bool) ($item['media_reused'] ?? false),
            'media_id' => $this->positiveInt($item['media_id'] ?? null),
            'genealogy_link_attempted' => (bool) ($item['genealogy_link_attempted'] ?? false),
            'link_scopes' => array_values(array_unique(array_map(
                static fn (array $link): string => (string) ($link['scope'] ?? 'unknown'),
                array_filter($item['links'] ?? [], 'is_array'),
            ))),
            'blockers' => array_values(array_unique(array_map('strval', $item['blockers'] ?? []))),
        ];
    }
}
