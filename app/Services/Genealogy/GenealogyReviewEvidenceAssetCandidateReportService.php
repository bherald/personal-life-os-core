<?php

namespace App\Services\Genealogy;

use App\Services\Review\ReviewEvidenceAssetCandidateService;
use App\Services\Review\ReviewTargetReferenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenealogyReviewEvidenceAssetCandidateReportService
{
    public function __construct(
        private readonly ReviewEvidenceAssetCandidateService $candidateService,
        private readonly ReviewTargetReferenceService $targetReferenceService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(int $limit = 50, bool $dryRun = false, bool $compact = false): array
    {
        $limit = max(1, min($limit, 200));
        $capturedAt = now()->toIso8601String();

        $payload = [
            'version' => 1,
            'command' => 'genealogy:evidence-asset-candidates',
            'mode' => 'observe',
            'dry_run' => $dryRun,
            'read_only' => true,
            'download_attempted' => false,
            'mutation_allowed' => false,
            'captured_at' => $capturedAt,
            'limit' => $limit,
            'status' => 'observe_ok',
            'summary' => [
                'scanned_rows' => 0,
                'rows_with_candidates' => 0,
                'candidate_count' => 0,
                'direct_download_allowed' => 0,
                'html_snapshot_allowed' => 0,
                'manual_review_required' => 0,
                'already_local_reference' => 0,
                'review_required' => 0,
                'unsupported_scheme' => 0,
            ],
            'policy_counts' => [],
            'provider_counts' => [],
            'asset_type_counts' => [],
            'posture' => [
                'row_identifiers_included' => false,
                'tokens_included' => false,
                'target_ref_non_reversible' => true,
                'raw_details_included' => false,
                'raw_person_ids_included' => false,
                'downloads_enabled' => false,
                'storage_writes_enabled' => false,
                'genealogy_links_enabled' => false,
            ],
        ];

        if ($dryRun) {
            $payload['status'] = 'dry_run';
            $payload['summary']['query_would_run'] = true;

            return $payload;
        }

        if (! Schema::hasTable('agent_review_queue')) {
            $payload['status'] = 'observe_unavailable';
            $payload['summary']['unavailable_reason'] = 'agent_review_queue_missing';

            return $payload;
        }

        $rows = DB::table('agent_review_queue')
            ->select(['id', 'token', 'review_type', 'finding_type', 'details', 'priority', 'created_at', 'expires_at'])
            ->where('status', 'pending')
            ->where('review_type', 'genealogy_review_packet')
            ->where(function ($query): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $packetRows = [];

        foreach ($rows as $row) {
            $payload['summary']['scanned_rows']++;
            $details = $this->decodeDetails($row->details ?? null);
            $candidates = $this->candidateService->fromDetails($details);

            if ($candidates === []) {
                continue;
            }

            $payload['summary']['rows_with_candidates']++;
            $payload['summary']['candidate_count'] += count($candidates);

            $rowPolicies = [];
            $rowProviders = [];
            $rowAssetTypes = [];
            $safeCandidates = [];

            foreach ($candidates as $candidate) {
                $policy = $this->safeString($candidate['capture_policy'] ?? 'unknown');
                $provider = $this->safeString($candidate['provider'] ?? 'unknown');
                $assetType = $this->safeString($candidate['asset_type'] ?? 'unknown');

                $this->increment($payload['policy_counts'], $policy);
                $this->increment($payload['provider_counts'], $provider);
                $this->increment($payload['asset_type_counts'], $assetType);
                $this->increment($rowPolicies, $policy);
                $this->increment($rowProviders, $provider);
                $this->increment($rowAssetTypes, $assetType);

                if (array_key_exists($policy, $payload['summary'])) {
                    $payload['summary'][$policy]++;
                }

                if (! $compact) {
                    $safeCandidates[] = $this->redactCandidate($candidate);
                }
            }

            if (! $compact) {
                $packetRows[] = [
                    'target_ref' => $this->targetReferenceService->forReviewRow($row, 'genealogy_review_packet'),
                    'created_at' => $this->safeDate($row->created_at ?? null),
                    'priority' => is_numeric($row->priority ?? null) ? (int) $row->priority : null,
                    'candidate_count' => count($candidates),
                    'policy_counts' => $rowPolicies,
                    'provider_counts' => $rowProviders,
                    'asset_type_counts' => $rowAssetTypes,
                    'candidates' => $safeCandidates,
                ];
            }
        }

        if ($payload['summary']['candidate_count'] === 0) {
            $payload['status'] = 'observe_empty';
        }

        ksort($payload['policy_counts']);
        ksort($payload['provider_counts']);
        ksort($payload['asset_type_counts']);

        if (! $compact) {
            $payload['rows'] = $packetRows;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function compactPayload(array $payload): array
    {
        unset($payload['rows']);
        $payload['compact'] = true;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toText(array $payload): string
    {
        $summary = $payload['summary'] ?? [];

        return sprintf(
            'Review evidence assets: %s mode=%s read_only=true scanned=%s rows_with_candidates=%s candidates=%s direct=%s html_snapshot=%s manual=%s local=%s review_required=%s downloads=false mutations=false captured=%s',
            $payload['status'] ?? 'unknown',
            $payload['mode'] ?? 'observe',
            $summary['scanned_rows'] ?? 0,
            $summary['rows_with_candidates'] ?? 0,
            $summary['candidate_count'] ?? 0,
            $summary['direct_download_allowed'] ?? 0,
            $summary['html_snapshot_allowed'] ?? 0,
            $summary['manual_review_required'] ?? 0,
            $summary['already_local_reference'] ?? 0,
            $summary['review_required'] ?? 0,
            $payload['captured_at'] ?? '-',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toMarkdown(array $payload): string
    {
        $summary = $payload['summary'] ?? [];
        $lines = [
            '# Review Evidence Asset Candidates',
            '',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Mode: `'.($payload['mode'] ?? 'observe').'`',
            '- Read-only: `true`',
            '- Downloads enabled: `false`',
            '- Mutations enabled: `false`',
            '- Scanned rows: `'.($summary['scanned_rows'] ?? 0).'`',
            '- Rows with candidates: `'.($summary['rows_with_candidates'] ?? 0).'`',
            '- Candidate count: `'.($summary['candidate_count'] ?? 0).'`',
            '- Direct download allowed candidates: `'.($summary['direct_download_allowed'] ?? 0).'`',
            '- HTML snapshot allowed candidates: `'.($summary['html_snapshot_allowed'] ?? 0).'`',
            '- Manual review required candidates: `'.($summary['manual_review_required'] ?? 0).'`',
            '- Local reference candidates: `'.($summary['already_local_reference'] ?? 0).'`',
            '- Review required candidates: `'.($summary['review_required'] ?? 0).'`',
            '',
            '## Provider Counts',
            '',
        ];

        foreach (($payload['provider_counts'] ?? []) as $provider => $count) {
            $lines[] = '- `'.$provider.'`: `'.$count.'`';
        }

        if (($payload['provider_counts'] ?? []) === []) {
            $lines[] = '- None';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDetails(mixed $details): array
    {
        if (is_array($details)) {
            return $details;
        }

        if (! is_string($details) || trim($details) === '') {
            return [];
        }

        $decoded = json_decode($details, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function increment(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    private function safeString(mixed $value): string
    {
        if (! is_scalar($value)) {
            return 'unknown';
        }

        $value = strtolower(trim((string) $value));

        return $value !== '' && preg_match('/^[a-z0-9_.-]+$/', $value) === 1 ? $value : 'unknown';
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function redactCandidate(array $candidate): array
    {
        $personId = $candidate['person_id'] ?? null;

        return [
            'schema' => $this->safeCandidateScalar($candidate['schema'] ?? null, 'unknown'),
            'origin' => $this->safeCandidateScalar($candidate['origin'] ?? null, 'unknown'),
            'label' => mb_substr(trim((string) ($candidate['label'] ?? 'Evidence asset')), 0, 140),
            'provider' => $this->safeString($candidate['provider'] ?? null),
            'asset_type' => $this->safeString($candidate['asset_type'] ?? null),
            'capture_policy' => $this->safeString($candidate['capture_policy'] ?? null),
            'capture_actions' => $this->safeCandidateList($candidate['capture_actions'] ?? []),
            'locator' => $this->safeLocator($candidate['locator'] ?? null),
            'locator_hash' => $this->safeCandidateScalar($candidate['locator_hash'] ?? null, ''),
            'locator_redacted' => (bool) ($candidate['locator_redacted'] ?? false),
            'host' => $this->safeCandidateScalar($candidate['host'] ?? null, null),
            'extension' => $this->safeCandidateScalar($candidate['extension'] ?? null, null),
            'identity_fit' => $this->safeIdentityFit($candidate['identity_fit'] ?? null),
            'target_storage' => $this->safeCandidateScalar($candidate['target_storage'] ?? null, 'ft_reference_area'),
            'download_attempted' => false,
            'mutation_allowed' => false,
            'person_reference_present' => is_numeric($personId) && (int) $personId > 0,
        ];
    }

    private function safeDate(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 32);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeIdentityFit(mixed $identityFit): ?array
    {
        if (! is_array($identityFit)) {
            return null;
        }

        return [
            'schema' => 'review_evidence_asset_identity_fit.v1',
            'given_name_present' => (bool) ($identityFit['given_name_present'] ?? false),
            'surname_present' => (bool) ($identityFit['surname_present'] ?? false),
            'full_name_match' => (bool) ($identityFit['full_name_match'] ?? false),
            'partial_name_only' => (bool) ($identityFit['partial_name_only'] ?? false),
            'approval_ready' => ($identityFit['approval_ready'] ?? true) === true,
            'supporting_signal_count' => is_numeric($identityFit['supporting_signal_count'] ?? null)
                ? (int) $identityFit['supporting_signal_count']
                : 0,
            'blocker' => $this->safeCandidateScalar($identityFit['blocker'] ?? null, null),
        ];
    }

    private function safeCandidateScalar(mixed $value, ?string $fallback): ?string
    {
        if (! is_scalar($value)) {
            return $fallback;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }

        if (preg_match('/(?:token|secret|password|api[_-]?key|access[_-]?key|session)=/i', $value) === 1) {
            return $fallback;
        }

        return mb_substr($value, 0, 180);
    }

    /**
     * @return list<string>
     */
    private function safeCandidateList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach (array_values($value) as $item) {
            $safe = $this->safeCandidateScalar($item, null);
            if ($safe !== null && preg_match('/^[a-z0-9_.:-]+$/', $safe) === 1) {
                $items[] = $safe;
            }
        }

        return array_slice(array_values(array_unique($items)), 0, 10);
    }

    private function safeLocator(mixed $value): ?string
    {
        $safe = $this->safeCandidateScalar($value, null);
        if ($safe === null) {
            return null;
        }

        $query = parse_url($safe, PHP_URL_QUERY);
        $fragment = parse_url($safe, PHP_URL_FRAGMENT);

        return ($query === null && $fragment === null) ? $safe : null;
    }
}
