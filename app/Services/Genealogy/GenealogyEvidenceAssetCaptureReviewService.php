<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GenealogyEvidenceAssetCaptureReviewService
{
    public const AGENT_ID = 'genealogy-media-intake';

    public const REVIEW_TYPE = 'genealogy_evidence_asset_capture';

    public function __construct(
        private readonly GenealogyEvidenceAssetCapturePlanService $planner,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(int $limit = 50, bool $execute = false, bool $confirmed = false, bool $compact = false, bool $eligibleOnly = false, ?int $treeId = null): array
    {
        $limit = max(1, min($limit, 200));
        $payload = $this->basePayload($limit, $execute, $confirmed, $compact, $eligibleOnly, $treeId);

        if ($execute && ! $confirmed) {
            $payload['status'] = 'blocked';
            $payload['blockers'][] = 'noncanonical_write_confirmation_required';
            $payload['next_action'] = 'Re-run with --execute --confirm-noncanonical-write to create capture-approval review rows only.';

            return $payload;
        }

        if (! Schema::hasTable('agent_review_queue')) {
            $payload['status'] = 'observe_unavailable';
            $payload['summary']['unavailable_reason'] = 'agent_review_queue_missing';

            return $payload;
        }

        $plan = $this->planner->collect(
            limit: $limit,
            dryRun: false,
            compact: false,
            eligibleOnly: $eligibleOnly,
            treeId: $treeId,
        );

        $payload['source_plan_status'] = $plan['status'] ?? 'unknown';
        $payload['summary']['source_scanned_rows'] = (int) ($plan['summary']['scanned_rows'] ?? 0);
        $payload['summary']['source_candidate_count'] = (int) ($plan['summary']['candidate_count'] ?? 0);
        $payload['summary']['planned_capture_count'] = (int) ($plan['summary']['planned_capture_count'] ?? 0);

        foreach (($plan['policy_counts'] ?? []) as $policy => $count) {
            $payload['policy_counts'][$policy] = (int) $count;
        }
        foreach (($plan['provider_counts'] ?? []) as $provider => $count) {
            $payload['provider_counts'][$provider] = (int) $count;
        }
        foreach (($plan['asset_type_counts'] ?? []) as $assetType => $count) {
            $payload['asset_type_counts'][$assetType] = (int) $count;
        }

        foreach (($plan['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $plans = $this->eligiblePlans($row['plans'] ?? []);
            if ($plans === []) {
                continue;
            }

            $payload['summary']['review_items_planned']++;
            $reviewPayload = $this->reviewPayload($row, $plans, $treeId);
            $existing = $this->findExistingPending($reviewPayload['title']);

            if ($existing !== null) {
                $payload['summary']['review_items_reused']++;
                if (! $compact) {
                    $payload['items'][] = $this->itemPayload($reviewPayload, 'would_reuse_existing_review', $existing);
                }

                continue;
            }

            if (! $execute) {
                $payload['summary']['review_items_would_create']++;
                if (! $compact) {
                    $payload['items'][] = $this->itemPayload($reviewPayload, 'would_create_review', null);
                }

                continue;
            }

            $created = $this->insertReview($reviewPayload);
            $payload['summary']['review_items_created']++;
            if (! $compact) {
                $payload['items'][] = $this->itemPayload($reviewPayload, 'created_review', $created);
            }
        }

        if ($payload['summary']['review_items_planned'] === 0) {
            $payload['status'] = 'observe_empty';
        } elseif ($execute) {
            $payload['status'] = 'materialized';
        } else {
            $payload['status'] = 'dry_run';
        }

        ksort($payload['policy_counts']);
        ksort($payload['provider_counts']);
        ksort($payload['asset_type_counts']);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function compactPayload(array $payload): array
    {
        unset($payload['items']);
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
            'Evidence asset capture review: %s mode=%s review_items=%s would_create=%s created=%s reused=%s planned_captures=%s downloads=false storage_writes=false canonical_writes=false',
            $payload['status'] ?? 'unknown',
            $payload['mode'] ?? 'dry_run',
            $summary['review_items_planned'] ?? 0,
            $summary['review_items_would_create'] ?? 0,
            $summary['review_items_created'] ?? 0,
            $summary['review_items_reused'] ?? 0,
            $summary['planned_capture_count'] ?? 0,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toMarkdown(array $payload): string
    {
        $summary = $payload['summary'] ?? [];

        return implode("\n", [
            '# Genealogy Evidence Asset Capture Review',
            '',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Mode: `'.($payload['mode'] ?? 'dry_run').'`',
            '- Downloads enabled: `false`',
            '- Storage writes enabled: `false`',
            '- Canonical writes enabled: `false`',
            '- Review items planned: `'.($summary['review_items_planned'] ?? 0).'`',
            '- Review items would create: `'.($summary['review_items_would_create'] ?? 0).'`',
            '- Review items created: `'.($summary['review_items_created'] ?? 0).'`',
            '- Review items reused: `'.($summary['review_items_reused'] ?? 0).'`',
            '- Planned capture count: `'.($summary['planned_capture_count'] ?? 0).'`',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(int $limit, bool $execute, bool $confirmed, bool $compact, bool $eligibleOnly, ?int $treeId): array
    {
        return [
            'version' => 1,
            'command' => 'genealogy:evidence-asset-capture-review',
            'mode' => $execute ? 'execute' : 'dry_run',
            'execute' => $execute,
            'dry_run' => ! $execute,
            'compact_requested' => $compact,
            'eligible_only' => $eligibleOnly,
            'read_only' => ! $execute,
            'mutation_allowed' => $execute && $confirmed,
            'canonical_write_allowed' => false,
            'noncanonical_write_allowed' => $execute && $confirmed,
            'confirm_noncanonical_write' => $confirmed,
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'review_decision_attempted' => false,
            'genealogy_link_attempted' => false,
            'captured_at' => now()->toIso8601String(),
            'tree_id' => $treeId,
            'limit' => $limit,
            'status' => 'dry_run',
            'blockers' => [],
            'summary' => [
                'source_scanned_rows' => 0,
                'source_candidate_count' => 0,
                'planned_capture_count' => 0,
                'review_items_planned' => 0,
                'review_items_would_create' => 0,
                'review_items_created' => 0,
                'review_items_reused' => 0,
            ],
            'policy_counts' => [],
            'provider_counts' => [],
            'asset_type_counts' => [],
            'posture' => [
                'row_identifiers_included' => false,
                'tokens_included' => false,
                'raw_details_included' => false,
                'raw_locators_included' => false,
                'downloads_enabled' => false,
                'storage_writes_enabled' => false,
                'review_decisions_enabled' => false,
                'genealogy_links_enabled' => false,
                'canonical_writes_enabled' => false,
                'noncanonical_review_queue_write_enabled' => $execute && $confirmed,
            ],
            'items' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eligiblePlans(mixed $plans): array
    {
        if (! is_array($plans)) {
            return [];
        }

        $eligible = [];
        foreach ($plans as $plan) {
            if (! is_array($plan) || ($plan['capture_ready'] ?? false) !== true) {
                continue;
            }
            $eligible[] = $plan;
        }

        return $eligible;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $plans
     * @return array<string, mixed>
     */
    private function reviewPayload(array $row, array $plans, ?int $treeId): array
    {
        $targetRef = $this->safeText($row['target_ref'] ?? 'unknown-target', 120);
        $hash = substr(sha1(json_encode([$targetRef, $plans])), 0, 16);

        return [
            'agent_id' => self::AGENT_ID,
            'review_type' => self::REVIEW_TYPE,
            'title' => 'Approve genealogy evidence media capture '.$hash,
            'summary' => sprintf(
                'Review %d capture-ready evidence media item(s) for %s. Approval is required before any download, FT storage write, or genealogy link.',
                count($plans),
                $targetRef,
            ),
            'confidence' => 0.75,
            'priority' => is_numeric($row['priority'] ?? null) ? (int) $row['priority'] : 5,
            'dedup_key' => 'genealogy-evidence-asset-capture-'.$hash,
            'details' => [
                'schema' => 'genealogy_evidence_asset_capture_review.v1',
                'dedup_key' => 'genealogy-evidence-asset-capture-'.$hash,
                'tree_id' => $treeId,
                'source_target_ref' => $targetRef,
                'capture_plan_count' => count($plans),
                'target_storage' => 'ft_reference_area',
                'plans' => $plans,
                'approval_required_before' => [
                    'download',
                    'ft_storage_write',
                    'review_decision',
                    'person_family_source_media_link',
                    'canonical_genealogy_write',
                ],
                'execution_posture' => [
                    'download_attempted' => false,
                    'storage_write_attempted' => false,
                    'review_decision_attempted' => false,
                    'genealogy_link_attempted' => false,
                    'canonical_write_allowed' => false,
                ],
            ],
        ];
    }

    private function findExistingPending(string $title): ?object
    {
        return DB::table('agent_review_queue')
            ->select(['id', 'token'])
            ->where('agent_id', self::AGENT_ID)
            ->where('review_type', self::REVIEW_TYPE)
            ->where('status', 'pending')
            ->where('title', $title)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insertReview(array $payload): object
    {
        $row = [
            'agent_id' => $payload['agent_id'],
            'review_type' => $payload['review_type'],
            'finding_type' => 'media_capture_approval',
            'title' => $payload['title'],
            'summary' => $payload['summary'],
            'details' => json_encode($payload['details'], JSON_THROW_ON_ERROR),
            'confidence' => $payload['confidence'],
            'priority' => $payload['priority'],
            'status' => 'pending',
            'token' => Str::random(40),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $id = DB::table('agent_review_queue')->insertGetId($row);

        return (object) [
            'id' => $id,
            'token' => $row['token'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function itemPayload(array $payload, string $action, ?object $row): array
    {
        return [
            'action' => $action,
            'review_type' => self::REVIEW_TYPE,
            'title_hash' => substr(sha1((string) $payload['title']), 0, 12),
            'capture_plan_count' => (int) ($payload['details']['capture_plan_count'] ?? 0),
            'target_storage' => 'ft_reference_area',
            'review_queue_reference_present' => $row !== null,
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'genealogy_link_attempted' => false,
            'canonical_write_allowed' => false,
        ];
    }

    private function safeText(mixed $value, int $max): string
    {
        $text = trim((string) $value);
        $text = preg_replace('/[[:cntrl:]]+/', ' ', $text) ?? '';
        $text = preg_replace('/\\s+/', ' ', $text) ?? '';

        return $text !== '' ? substr($text, 0, $max) : 'unknown';
    }
}
