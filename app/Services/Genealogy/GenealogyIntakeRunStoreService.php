<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

class GenealogyIntakeRunStoreService
{
    public function listRuns(?int $treeId = null, int $limit = 25): array
    {
        $limit = max(1, min($limit, 200));

        if ($treeId !== null) {
            $rows = DB::select(
                'SELECT run_key, tree_id, root_path, packet_label, status, updated_at, staged_snapshot
                 FROM genealogy_intake_runs
                 WHERE tree_id = ?
                 ORDER BY updated_at DESC
                 LIMIT ?',
                [$treeId, $limit]
            );
        } else {
            $rows = DB::select(
                'SELECT run_key, tree_id, root_path, packet_label, status, updated_at, staged_snapshot
                 FROM genealogy_intake_runs
                 ORDER BY updated_at DESC
                 LIMIT ?',
                [$limit]
            );
        }

        return [
            'success' => true,
            'runs' => array_map(static fn ($row): array => [
                'run_key' => $row->run_key,
                'tree_id' => (int) $row->tree_id,
                'root_path' => $row->root_path,
                'packet_label' => $row->packet_label,
                'status' => $row->status,
                'updated_at' => $row->updated_at,
                'copy_progress' => self::extractCopyProgressFromSnapshot((string) ($row->staged_snapshot ?? '')),
            ], $rows),
        ];
    }

    public function saveStagedRun(array $snapshot): array
    {
        $runKey = (string) ($snapshot['run_key'] ?? '');
        if ($runKey === '') {
            return ['success' => false, 'error' => 'missing_run_key'];
        }

        $existing = DB::selectOne(
            'SELECT id FROM genealogy_intake_runs WHERE run_key = ? LIMIT 1',
            [$runKey]
        );

        $payload = json_encode($snapshot, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return ['success' => false, 'error' => 'snapshot_encode_failed'];
        }

        if ($existing) {
            DB::update(
                'UPDATE genealogy_intake_runs
                 SET tree_id = ?, root_path = ?, packet_label = ?, status = ?, staged_snapshot = ?, updated_at = NOW()
                 WHERE id = ?',
                [
                    (int) ($snapshot['tree_id'] ?? 0),
                    (string) ($snapshot['root_path'] ?? ''),
                    $snapshot['packet_label'] ?? null,
                    (string) ($snapshot['status'] ?? 'staged'),
                    $payload,
                    (int) $existing->id,
                ]
            );
        } else {
            DB::insert(
                'INSERT INTO genealogy_intake_runs
                 (run_key, tree_id, root_path, packet_label, status, staged_snapshot, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $runKey,
                    (int) ($snapshot['tree_id'] ?? 0),
                    (string) ($snapshot['root_path'] ?? ''),
                    $snapshot['packet_label'] ?? null,
                    (string) ($snapshot['status'] ?? 'staged'),
                    $payload,
                ]
            );
        }

        return ['success' => true, 'run_key' => $runKey];
    }

    public function getRun(string $runKey): array
    {
        $row = DB::selectOne(
            'SELECT run_key, tree_id, root_path, packet_label, status, updated_at, staged_snapshot
             FROM genealogy_intake_runs
             WHERE run_key = ?
             LIMIT 1',
            [$runKey]
        );

        if (! $row) {
            return ['success' => false, 'error' => 'run_not_found'];
        }

        $snapshot = json_decode((string) $row->staged_snapshot, true);
        if (! is_array($snapshot)) {
            return ['success' => false, 'error' => 'invalid_snapshot'];
        }

        return [
            'success' => true,
            'run' => $snapshot + [
                'run_key' => $row->run_key,
                'tree_id' => $row->tree_id,
                'root_path' => $row->root_path,
                'packet_label' => $row->packet_label,
                'status' => $row->status,
                'updated_at' => $row->updated_at ?? ($snapshot['updated_at'] ?? null),
            ],
        ];
    }

    public function recordCopyExecution(string $runKey, array $registration, array $execution): array
    {
        $loaded = $this->getRun($runKey);
        if (! ($loaded['success'] ?? false)) {
            return $loaded;
        }

        $run = (array) ($loaded['run'] ?? []);
        $packetKey = (string) ($registration['packet_key'] ?? '');
        $packetLabel = (string) ($registration['packet_label'] ?? '');
        $packets = array_values((array) ($run['packets'] ?? []));

        foreach ($packets as $index => $packet) {
            $existingPacketKey = (string) ($packet['packet_key'] ?? '');
            $existingPacketLabel = (string) ($packet['packet_label'] ?? '');

            if (($packetKey !== '' && $existingPacketKey === $packetKey) || ($packetLabel !== '' && $existingPacketLabel === $packetLabel)) {
                $packets[$index]['reference_copy_execution'] = [
                    'updated_at' => now()->toDateTimeString(),
                    'registration' => $registration,
                    'execution' => $execution,
                ];
                break;
            }
        }

        $run['packets'] = $packets;
        $run['status'] = $this->resolveRunStatus($run);

        return $this->saveStagedRun($run);
    }

    /**
     * Persist a compact preview_state into the matching packet of a saved run snapshot.
     * Skips the write if the preview state hash has not changed.
     */
    public function recordPacketPreviewState(string $runKey, array $packetPreview): array
    {
        $loaded = $this->getRun($runKey);
        if (! ($loaded['success'] ?? false)) {
            return $loaded;
        }

        $run = (array) ($loaded['run'] ?? []);
        $packets = array_values((array) ($run['packets'] ?? []));

        $previewLabel = (string) ($packetPreview['packet_label'] ?? '');
        $previewKey = (string) ($packetPreview['packet_key'] ?? '');
        if ($previewLabel === '' && $previewKey === '') {
            return ['success' => false, 'error' => 'no_packet_identifier'];
        }

        $state = self::extractPreviewState($packetPreview);
        // Hash excludes updated_at so that re-reads with identical content skip the write
        $hashable = $state;
        unset($hashable['updated_at']);
        $stateHash = md5((string) json_encode($hashable));

        $matched = false;
        foreach ($packets as $index => $packet) {
            $existingLabel = (string) ($packet['packet_label'] ?? '');
            $existingKey = (string) ($packet['packet_key'] ?? '');

            if (($previewKey !== '' && $existingKey === $previewKey) || ($previewLabel !== '' && $existingLabel === $previewLabel)) {
                $existingHash = (string) ($packet['preview_state']['_hash'] ?? '');
                if ($existingHash === $stateHash) {
                    return ['success' => true, 'skipped' => true, 'reason' => 'unchanged'];
                }

                $state['_hash'] = $stateHash;
                $packets[$index]['preview_state'] = $state;
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            return ['success' => false, 'error' => 'packet_not_found'];
        }

        $run['packets'] = $packets;

        return $this->saveStagedRun($run);
    }

    /**
     * Extract a compact preview_state from a packet preview result.
     */
    private static function extractPreviewState(array $packetPreview): array
    {
        $preview = (array) ($packetPreview['preview'] ?? []);

        $state = [
            'updated_at' => now()->toDateTimeString(),
            'status' => (string) ($preview['status'] ?? ''),
            'proposal_ready' => ! empty($preview['proposal_ready']),
            'packet_summary' => (string) ($preview['packet_summary'] ?? ''),
        ];

        if (! empty($preview['questions'])) {
            $state['questions'] = array_values((array) $preview['questions']);
        }

        if (! empty($preview['page_anchors'])) {
            $state['page_anchors'] = array_values((array) $preview['page_anchors']);
        }

        if (! empty($preview['structured_facts'])) {
            $state['structured_facts'] = array_values(array_filter(
                array_map(static function ($fact): ?array {
                    if (! is_array($fact)) {
                        return null;
                    }

                    $field = trim((string) ($fact['field'] ?? ''));
                    $value = trim((string) ($fact['value'] ?? ''));
                    if ($field === '' || $value === '') {
                        return null;
                    }

                    $normalized = [
                        'field' => $field,
                        'value' => $value,
                    ];

                    $pageAnchors = array_values(array_filter(array_map(
                        static fn ($anchor) => trim((string) $anchor),
                        (array) ($fact['page_anchors'] ?? [])
                    )));
                    if ($pageAnchors !== []) {
                        $normalized['page_anchors'] = $pageAnchors;
                    }

                    if (isset($fact['confidence']) && is_numeric($fact['confidence'])) {
                        $normalized['confidence'] = (float) $fact['confidence'];
                    }

                    return $normalized;
                },
                    (array) $preview['structured_facts']
                )));
        }

        return $state;
    }

    public const ALLOWED_DECISIONS = ['approved', 'deferred', 'rejected', 'needs_followup'];

    /**
     * Persist compact packet-level apply state for an approval-draft apply run.
     */
    public function recordPacketApprovalApplyState(
        string $runKey,
        array $packetIdentity,
        array $approvalDraftPreview,
        array $applyResult,
        array $applySummary
    ): array {
        $packetKey = (string) ($packetIdentity['packet_key'] ?? '');
        $packetLabel = (string) ($packetIdentity['packet_label'] ?? '');
        if ($packetKey === '' && $packetLabel === '') {
            return ['success' => false, 'error' => 'no_packet_identifier'];
        }

        $loaded = $this->getRun($runKey);
        if (! ($loaded['success'] ?? false)) {
            return $loaded;
        }

        $run = (array) ($loaded['run'] ?? []);
        $packets = array_values((array) ($run['packets'] ?? []));
        $state = self::extractApprovalApplyState($approvalDraftPreview, $applyResult, $applySummary);

        $matched = false;
        foreach ($packets as $index => $packet) {
            $existingKey = (string) ($packet['packet_key'] ?? '');
            $existingLabel = (string) ($packet['packet_label'] ?? '');

            if (($packetKey !== '' && $existingKey === $packetKey) || ($packetLabel !== '' && $existingLabel === $packetLabel)) {
                $existingHash = (string) ($packet['approval_apply_state']['_hash'] ?? '');
                if ($existingHash === ($state['_hash'] ?? '')) {
                    return ['success' => true, 'skipped' => true, 'reason' => 'unchanged'];
                }

                $packets[$index]['approval_apply_state'] = $state;
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            return ['success' => false, 'error' => 'packet_not_found'];
        }

        $run['packets'] = $packets;
        $saveResult = $this->saveStagedRun($run);
        if (! ($saveResult['success'] ?? false)) {
            return $saveResult;
        }

        return ['success' => true, 'approval_apply_state' => $state];
    }

    /**
     * Persist compact packet-level generation state for a proposal-generation run.
     */
    public function recordPacketProposalGenerationState(
        string $runKey,
        array $packetIdentity,
        array $approvalDraftPreview,
        array $generationResult,
        array $generationSummary
    ): array {
        $packetKey = (string) ($packetIdentity['packet_key'] ?? '');
        $packetLabel = (string) ($packetIdentity['packet_label'] ?? '');
        if ($packetKey === '' && $packetLabel === '') {
            return ['success' => false, 'error' => 'no_packet_identifier'];
        }

        $loaded = $this->getRun($runKey);
        if (! ($loaded['success'] ?? false)) {
            return $loaded;
        }

        $run = (array) ($loaded['run'] ?? []);
        $packets = array_values((array) ($run['packets'] ?? []));
        $state = self::extractProposalGenerationState($approvalDraftPreview, $generationResult, $generationSummary);

        $matched = false;
        foreach ($packets as $index => $packet) {
            $existingKey = (string) ($packet['packet_key'] ?? '');
            $existingLabel = (string) ($packet['packet_label'] ?? '');

            if (($packetKey !== '' && $existingKey === $packetKey) || ($packetLabel !== '' && $existingLabel === $packetLabel)) {
                $existingHash = (string) ($packet['proposal_generation_state']['_hash'] ?? '');
                if ($existingHash === ($state['_hash'] ?? '')) {
                    return ['success' => true, 'skipped' => true, 'reason' => 'unchanged'];
                }

                $packets[$index]['proposal_generation_state'] = $state;
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            return ['success' => false, 'error' => 'packet_not_found'];
        }

        $run['packets'] = $packets;
        $saveResult = $this->saveStagedRun($run);
        if (! ($saveResult['success'] ?? false)) {
            return $saveResult;
        }

        return ['success' => true, 'proposal_generation_state' => $state];
    }

    public static function computeApprovalApplyPlanHash(array $approvalDraftPreview): string
    {
        $hashable = [
            'packet' => (array) ($approvalDraftPreview['packet'] ?? []),
            'context' => (array) ($approvalDraftPreview['context'] ?? []),
            'plan' => (array) ($approvalDraftPreview['plan'] ?? []),
        ];

        return md5((string) json_encode($hashable));
    }

    /**
     * Persist a packet-level human review decision into the matching packet of a saved run snapshot.
     */
    public function recordPacketReviewDecision(string $runKey, array $decision): array
    {
        $decisionValue = (string) ($decision['decision'] ?? '');
        if (! in_array($decisionValue, self::ALLOWED_DECISIONS, true)) {
            return ['success' => false, 'error' => 'invalid_decision'];
        }

        $packetKey = (string) ($decision['packet_key'] ?? '');
        $packetLabel = (string) ($decision['packet_label'] ?? '');
        if ($packetKey === '' && $packetLabel === '') {
            return ['success' => false, 'error' => 'no_packet_identifier'];
        }

        $loaded = $this->getRun($runKey);
        if (! ($loaded['success'] ?? false)) {
            return $loaded;
        }

        $run = (array) ($loaded['run'] ?? []);
        $packets = array_values((array) ($run['packets'] ?? []));

        $matched = false;
        $matchedIndex = -1;
        foreach ($packets as $index => $packet) {
            $existingKey = (string) ($packet['packet_key'] ?? '');
            $existingLabel = (string) ($packet['packet_label'] ?? '');

            if (($packetKey !== '' && $existingKey === $packetKey) || ($packetLabel !== '' && $existingLabel === $packetLabel)) {
                $packets[$index]['review_decision'] = [
                    'updated_at' => now()->toDateTimeString(),
                    'decision' => $decisionValue,
                    'notes' => ($decision['notes'] ?? null) !== null ? (string) $decision['notes'] : null,
                    'reviewed_by' => ($decision['reviewed_by'] ?? null) !== null ? (string) $decision['reviewed_by'] : null,
                ];
                $matched = true;
                $matchedIndex = $index;
                break;
            }
        }

        if (! $matched) {
            return ['success' => false, 'error' => 'packet_not_found'];
        }

        $run['packets'] = $packets;
        $saveResult = $this->saveStagedRun($run);
        if (! ($saveResult['success'] ?? false)) {
            return $saveResult;
        }

        return [
            'success' => true,
            'review_decision' => $packets[$matchedIndex]['review_decision'],
        ];
    }

    private static function extractApprovalApplyState(array $approvalDraftPreview, array $applyResult, array $applySummary): array
    {
        $state = [
            'updated_at' => now()->toDateTimeString(),
            'success' => (bool) ($applyResult['success'] ?? false),
            'status' => (string) ($applySummary['status'] ?? ''),
            'summary' => (string) ($applySummary['summary'] ?? ''),
            'next_action' => (string) ($applySummary['next_action'] ?? ''),
            'counts' => (array) ($applySummary['counts'] ?? []),
            'plan_hash' => self::computeApprovalApplyPlanHash($approvalDraftPreview),
        ];

        if (! empty($applyResult['errors'])) {
            $state['errors'] = array_values(array_slice((array) $applyResult['errors'], 0, 5));
        }

        $hashable = $state;
        unset($hashable['updated_at']);
        $state['_hash'] = md5((string) json_encode($hashable));

        return $state;
    }

    private static function extractProposalGenerationState(array $approvalDraftPreview, array $generationResult, array $generationSummary): array
    {
        $personIds = array_values(array_filter(array_map(
            static fn ($entry) => (int) ($entry['proposal_id'] ?? 0),
            (array) ($generationResult['persisted_person_changes'] ?? [])
        )));
        $relationshipIds = array_values(array_filter(array_map(
            static fn ($entry) => (int) ($entry['proposal_id'] ?? 0),
            (array) ($generationResult['persisted_relationships'] ?? [])
        )));

        $state = [
            'updated_at' => now()->toDateTimeString(),
            'success' => (bool) ($generationResult['success'] ?? false),
            'status' => (string) ($generationSummary['status'] ?? ''),
            'summary' => (string) ($generationSummary['summary'] ?? ''),
            'next_action' => (string) ($generationSummary['next_action'] ?? ''),
            'counts' => (array) ($generationSummary['counts'] ?? []),
            'plan_hash' => self::computeApprovalApplyPlanHash($approvalDraftPreview),
        ];

        if ($personIds !== []) {
            $state['person_proposal_ids'] = array_values(array_slice($personIds, 0, 50));
        }

        if ($relationshipIds !== []) {
            $state['relationship_proposal_ids'] = array_values(array_slice($relationshipIds, 0, 50));
        }

        if (! empty($generationSummary['highlights'])) {
            $state['highlights'] = array_values(array_slice((array) $generationSummary['highlights'], 0, 6));
        }

        if (! empty($generationResult['errors'])) {
            $state['errors'] = array_values(array_slice((array) $generationResult['errors'], 0, 5));
        }

        $hashable = $state;
        unset($hashable['updated_at']);
        $state['_hash'] = md5((string) json_encode($hashable));

        return $state;
    }

    private function resolveRunStatus(array $run): string
    {
        $packets = array_values((array) ($run['packets'] ?? []));
        if ($packets === []) {
            return (string) ($run['status'] ?? 'staged');
        }

        $hasConflict = false;
        $allCopiedOrPresent = true;

        foreach ($packets as $packet) {
            $execution = (array) (($packet['reference_copy_execution']['execution'] ?? []));
            $summary = (array) ($execution['summary'] ?? []);

            if (($summary['blocked_conflicts'] ?? 0) > 0 || ($summary['failed'] ?? 0) > 0) {
                $hasConflict = true;
            }

            if (($summary['copied'] ?? 0) === 0 && ($summary['already_in_place'] ?? 0) === 0) {
                $allCopiedOrPresent = false;
            }
        }

        if ($hasConflict) {
            return 'copy_attention_needed';
        }

        if ($allCopiedOrPresent) {
            return 'reference_copies_ready';
        }

        return 'staged';
    }

    private static function extractCopyProgressFromSnapshot(string $snapshotJson): array
    {
        $snapshot = json_decode($snapshotJson, true);
        if (! is_array($snapshot)) {
            return [
                'packets_with_execution' => 0,
                'copied' => 0,
                'already_in_place' => 0,
                'blocked_conflicts' => 0,
                'failed' => 0,
            ];
        }

        $progress = [
            'packets_with_execution' => 0,
            'copied' => 0,
            'already_in_place' => 0,
            'blocked_conflicts' => 0,
            'failed' => 0,
        ];

        foreach (array_values((array) ($snapshot['packets'] ?? [])) as $packet) {
            $summary = (array) (($packet['reference_copy_execution']['execution']['summary'] ?? []));
            if ($summary === []) {
                continue;
            }

            $progress['packets_with_execution']++;
            $progress['copied'] += (int) ($summary['copied'] ?? 0);
            $progress['already_in_place'] += (int) ($summary['already_in_place'] ?? 0);
            $progress['blocked_conflicts'] += (int) ($summary['blocked_conflicts'] ?? 0);
            $progress['failed'] += (int) ($summary['failed'] ?? 0);
        }

        return $progress;
    }
}
