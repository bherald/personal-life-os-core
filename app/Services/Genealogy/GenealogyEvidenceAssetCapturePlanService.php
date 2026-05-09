<?php

namespace App\Services\Genealogy;

use App\Services\Review\ReviewEvidenceAssetCandidateService;
use App\Services\Review\ReviewTargetReferenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenealogyEvidenceAssetCapturePlanService
{
    private const CAPTURE_READY_POLICIES = [
        'direct_download_allowed',
        'html_snapshot_allowed',
        'already_local_reference',
    ];

    public function __construct(
        private readonly ReviewEvidenceAssetCandidateService $candidateService,
        private readonly ReviewTargetReferenceService $targetReferenceService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(int $limit = 50, bool $dryRun = false, bool $compact = false, bool $eligibleOnly = false): array
    {
        $limit = max(1, min($limit, 200));
        $payload = [
            'version' => 1,
            'command' => 'genealogy:evidence-asset-capture-plan',
            'mode' => 'observe',
            'dry_run' => $dryRun,
            'compact_requested' => $compact,
            'eligible_only' => $eligibleOnly,
            'read_only' => true,
            'download_attempted' => false,
            'mutation_allowed' => false,
            'canonical_write_allowed' => false,
            'noncanonical_write_allowed' => false,
            'review_decision_required' => true,
            'captured_at' => now()->toIso8601String(),
            'limit' => $limit,
            'status' => 'observe_ok',
            'summary' => [
                'scanned_rows' => 0,
                'rows_with_candidates' => 0,
                'candidate_count' => 0,
                'planned_capture_count' => 0,
                'direct_download_ready' => 0,
                'html_snapshot_ready' => 0,
                'already_local_reference' => 0,
                'manual_review_required' => 0,
                'review_required' => 0,
                'unsupported_scheme' => 0,
                'provider_review_required' => 0,
                'person_link_ready' => 0,
                'person_link_missing' => 0,
                'family_link_ready' => 0,
                'storage_area' => 'ft_reference_area',
            ],
            'policy_counts' => [],
            'provider_counts' => [],
            'asset_type_counts' => [],
            'posture' => [
                'row_identifiers_included' => false,
                'tokens_included' => false,
                'raw_details_included' => false,
                'raw_locators_included' => false,
                'target_ref_non_reversible' => true,
                'downloads_enabled' => false,
                'storage_writes_enabled' => false,
                'review_decisions_enabled' => false,
                'genealogy_links_enabled' => false,
                'canonical_writes_enabled' => false,
            ],
            'next_action' => 'Operator approval is required before any media download, FT storage write, or person/family/source/media link.',
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

        $planRows = [];

        foreach ($rows as $row) {
            $payload['summary']['scanned_rows']++;
            $details = $this->decodeDetails($row->details ?? null);
            $familyReady = $this->positiveInt($details['family_id'] ?? null) !== null
                || $this->positiveInt($details['target_family_id'] ?? null) !== null
                || $this->positiveInt($details['identity']['family_id'] ?? null) !== null
                || $this->positiveInt($details['identity']['target_family_id'] ?? null) !== null;
            $candidates = $this->candidateService->fromDetails($details);

            if ($candidates === []) {
                continue;
            }

            $rowPlans = [];
            $payload['summary']['rows_with_candidates']++;
            $payload['summary']['candidate_count'] += count($candidates);

            foreach ($candidates as $candidate) {
                $policy = $this->safeString($candidate['capture_policy'] ?? 'unknown');
                $provider = $this->safeString($candidate['provider'] ?? 'unknown');
                $assetType = $this->safeString($candidate['asset_type'] ?? 'unknown');
                $eligible = in_array($policy, self::CAPTURE_READY_POLICIES, true);

                $this->increment($payload['policy_counts'], $policy);
                $this->increment($payload['provider_counts'], $provider);
                $this->increment($payload['asset_type_counts'], $assetType);
                $this->incrementPolicySummary($payload, $policy);

                if (! $eligible) {
                    $payload['summary']['provider_review_required']++;
                    if ($eligibleOnly) {
                        continue;
                    }
                }

                if ($eligible) {
                    $payload['summary']['planned_capture_count']++;
                    if ($policy === 'direct_download_allowed') {
                        $payload['summary']['direct_download_ready']++;
                    } elseif ($policy === 'html_snapshot_allowed') {
                        $payload['summary']['html_snapshot_ready']++;
                    }
                }

                $personReady = is_int($candidate['person_id'] ?? null) && ($candidate['person_id'] ?? 0) > 0;
                if ($personReady) {
                    $payload['summary']['person_link_ready']++;
                } else {
                    $payload['summary']['person_link_missing']++;
                }
                if ($familyReady) {
                    $payload['summary']['family_link_ready']++;
                }

                if (! $compact) {
                    $rowPlans[] = $this->redactPlan($candidate, $policy, $eligible, $personReady, $familyReady);
                }
            }

            if (! $compact && $rowPlans !== []) {
                $planRows[] = [
                    'target_ref' => $this->targetReferenceService->forReviewRow($row, 'genealogy_review_packet'),
                    'created_at' => $this->safeDate($row->created_at ?? null),
                    'priority' => is_numeric($row->priority ?? null) ? (int) $row->priority : null,
                    'plan_count' => count($rowPlans),
                    'plans' => $rowPlans,
                ];
            }
        }

        if ($payload['summary']['candidate_count'] === 0) {
            $payload['status'] = 'observe_empty';
        } elseif ($payload['summary']['planned_capture_count'] === 0) {
            $payload['status'] = 'observe_blocked';
        }

        ksort($payload['policy_counts']);
        ksort($payload['provider_counts']);
        ksort($payload['asset_type_counts']);

        if (! $compact) {
            $payload['rows'] = $planRows;
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
            'Evidence asset capture plan: %s mode=%s read_only=true candidates=%s planned=%s direct=%s html=%s local=%s manual=%s person_ready=%s person_missing=%s downloads=false storage_writes=false links=false captured=%s',
            $payload['status'] ?? 'unknown',
            $payload['mode'] ?? 'observe',
            $summary['candidate_count'] ?? 0,
            $summary['planned_capture_count'] ?? 0,
            $summary['direct_download_ready'] ?? 0,
            $summary['html_snapshot_ready'] ?? 0,
            $summary['already_local_reference'] ?? 0,
            $summary['manual_review_required'] ?? 0,
            $summary['person_link_ready'] ?? 0,
            $summary['person_link_missing'] ?? 0,
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
            '# Genealogy Evidence Asset Capture Plan',
            '',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Mode: `'.($payload['mode'] ?? 'observe').'`',
            '- Read-only: `true`',
            '- Downloads enabled: `false`',
            '- Storage writes enabled: `false`',
            '- Genealogy links enabled: `false`',
            '- Review decision required: `true`',
            '- Candidate count: `'.($summary['candidate_count'] ?? 0).'`',
            '- Planned capture count: `'.($summary['planned_capture_count'] ?? 0).'`',
            '- Direct download ready: `'.($summary['direct_download_ready'] ?? 0).'`',
            '- HTML snapshot ready: `'.($summary['html_snapshot_ready'] ?? 0).'`',
            '- Already local references: `'.($summary['already_local_reference'] ?? 0).'`',
            '- Manual/provider review required: `'.($summary['provider_review_required'] ?? 0).'`',
            '- Person link ready: `'.($summary['person_link_ready'] ?? 0).'`',
            '- Person link missing: `'.($summary['person_link_missing'] ?? 0).'`',
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function incrementPolicySummary(array &$payload, string $policy): void
    {
        if (array_key_exists($policy, $payload['summary'])) {
            $payload['summary'][$policy]++;
        }
    }

    private function safeString(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9_.-]+/', '_', $value) ?? '';

        return $value !== '' ? substr($value, 0, 80) : 'unknown';
    }

    private function safeDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return substr((string) $value, 0, 32);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function redactPlan(array $candidate, string $policy, bool $eligible, bool $personReady, bool $familyReady): array
    {
        return [
            'schema' => 'genealogy_evidence_asset_capture_plan.v1',
            'label' => $this->safeLabel($candidate['label'] ?? 'Evidence asset'),
            'provider' => $this->safeString($candidate['provider'] ?? 'unknown'),
            'asset_type' => $this->safeString($candidate['asset_type'] ?? 'unknown'),
            'capture_policy' => $policy,
            'capture_ready' => $eligible,
            'capture_actions' => $this->safeActions($candidate['capture_actions'] ?? []),
            'locator_hash' => $this->safeHash($candidate['locator_hash'] ?? null),
            'host' => $this->safeHost($candidate['host'] ?? null),
            'extension' => $this->safeString($candidate['extension'] ?? 'unknown'),
            'target_storage' => 'ft_reference_area',
            'proposed_connection_scope' => $personReady ? 'person' : ($familyReady ? 'family' : 'review_packet'),
            'person_reference_present' => $personReady,
            'family_reference_present' => $familyReady,
            'requires_operator_approval' => true,
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'genealogy_link_attempted' => false,
            'canonical_write_allowed' => false,
        ];
    }

    private function safeLabel(mixed $value): string
    {
        $label = trim((string) $value);
        $label = preg_replace('/[[:cntrl:]]+/', ' ', $label) ?? '';
        $label = preg_replace('/\\s+/', ' ', $label) ?? '';

        return $label !== '' ? substr($label, 0, 120) : 'Evidence asset';
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function safeActions(mixed $actions): array
    {
        if (! is_array($actions)) {
            return [];
        }

        $safe = [];
        foreach ($actions as $action) {
            $value = $this->safeString($action);
            if ($value !== 'unknown') {
                $safe[] = $value;
            }
        }

        return array_values(array_unique($safe));
    }

    private function safeHash(mixed $value): ?string
    {
        $hash = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{8,40}$/', $hash) === 1 ? $hash : null;
    }

    private function safeHost(mixed $value): ?string
    {
        $host = strtolower(trim((string) $value));
        if ($host === '') {
            return null;
        }

        $host = preg_replace('/[^a-z0-9.-]+/', '', $host) ?? '';

        return $host !== '' ? substr($host, 0, 120) : null;
    }
}
