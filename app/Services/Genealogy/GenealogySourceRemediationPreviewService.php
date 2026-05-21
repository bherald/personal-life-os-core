<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

class GenealogySourceRemediationPreviewService
{
    public function preview(array $payload, int $index = 0): ?array
    {
        $operationType = $this->operationType($payload);

        return match ($operationType) {
            'source_duplicate_mark' => $this->previewSourceDuplicateMark($payload, $index),
            default => null,
        };
    }

    private function previewSourceDuplicateMark(array $payload, int $index): array
    {
        $resolution = $this->sourceRemediationResolution($payload);
        if (($resolution['cluster_preview'] ?? false) === true) {
            return $this->previewSourceAddDuplicateCluster($resolution, $index);
        }

        $treeId = $resolution['tree_id'];
        $suspectSourceId = $resolution['suspect_source_id'];
        $retainedSourceId = $resolution['retained_source_id'];
        $suspectSource = $suspectSourceId !== null ? $this->sourceSnapshot($suspectSourceId) : null;
        $retainedSource = $retainedSourceId !== null ? $this->sourceSnapshot($retainedSourceId) : null;
        $duplicateSignals = $this->duplicateSignals($suspectSource, $retainedSource);
        $guards = [
            ...$this->proposedChangeResolutionGuards($resolution),
            ...$this->sourceDuplicateGuards(
                $treeId,
                $suspectSourceId,
                $retainedSourceId,
                $suspectSource,
                $retainedSource,
                $duplicateSignals,
            ),
        ];
        $blocked = collect($guards)->contains(fn (array $guard): bool => ($guard['status'] ?? null) === 'fail');
        $state = [
            'suspect_source' => $suspectSource,
            'retained_source' => $retainedSource,
            'duplicate_signals' => $duplicateSignals,
        ];
        if ($resolution['proposed_change_resolution'] !== null) {
            $state['proposed_change_resolution'] = $resolution['proposed_change_resolution'];
        }

        $operation = [
            'index' => $index,
            'operation' => 'source_duplicate_mark_preview',
            'operation_type' => 'source_duplicate_mark',
            'target_table' => 'genealogy_sources',
            'status' => $blocked ? 'blocked' : 'preview_only',
            'apply_enabled' => false,
            'mutates_accepted_facts' => false,
            'tree_id' => $treeId,
            'suspect_source_id' => $suspectSourceId,
            'retained_source_id' => $retainedSourceId,
            'guards' => $guards,
            'current_state' => $state,
            'stale_hash' => $this->hash($state),
            'proposed_effect' => [
                'type' => 'mark_source_duplicate_preview_only',
                'description' => 'Would mark the suspect source as a duplicate of the retained source only after a later approved apply path exists.',
                'rows_that_would_be_touched' => $suspectSourceId !== null ? [[
                    'table' => 'genealogy_sources',
                    'id' => $suspectSourceId,
                    'action' => 'mark_source_duplicate_preview_only',
                ]] : [],
            ],
        ];

        if ($resolution['proposed_change_ids'] !== []) {
            $operation['proposed_change_ids'] = $resolution['proposed_change_ids'];
        }

        if ($resolution['resolution_strategy'] !== null) {
            $operation['resolution_strategy'] = $resolution['resolution_strategy'];
        }

        return $operation;
    }

    /**
     * @param  array<string, mixed>  $resolution
     */
    private function previewSourceAddDuplicateCluster(array $resolution, int $index): array
    {
        $proposedChangeResolution = $resolution['proposed_change_resolution'] ?? [];
        $locatorGroups = is_array($proposedChangeResolution)
            ? $this->listArray($proposedChangeResolution['locator_groups'] ?? [])
            : [];
        $duplicateProposalCount = array_sum(array_map(
            fn (array $group): int => (int) ($group['proposal_count'] ?? 0),
            $locatorGroups,
        ));
        $guards = $this->proposedChangeResolutionGuards($resolution);
        $guards[] = [
            'name' => 'source_add_duplicate_locator_groups',
            'status' => $locatorGroups !== [] ? 'pass' : 'fail',
            'message' => 'Multi-ID source cleanup previews require at least one duplicate locator group.',
        ];

        $state = [
            'proposed_change_resolution' => $proposedChangeResolution,
            'duplicate_locator_group_count' => count($locatorGroups),
            'duplicate_locator_proposal_count' => $duplicateProposalCount,
        ];

        return [
            'index' => $index,
            'operation' => 'source_add_duplicate_cluster_preview',
            'operation_type' => 'source_duplicate_cleanup',
            'target_table' => null,
            'status' => 'blocked',
            'apply_enabled' => false,
            'mutates_accepted_facts' => false,
            'tree_id' => $resolution['tree_id'],
            'proposed_change_ids' => $resolution['proposed_change_ids'],
            'resolution_strategy' => 'duplicate_locator_cluster_preview',
            'guards' => $guards,
            'current_state' => $state,
            'stale_hash' => $this->hash($state),
            'proposed_effect' => [
                'type' => 'source_add_duplicate_cluster_preview_only',
                'description' => 'Shows duplicate source_add locator groups for operator review without selecting retained/suspect source rows or changing genealogy data.',
                'rows_that_would_be_touched' => [],
            ],
        ];
    }

    private function operationType(array $payload): ?string
    {
        foreach (['operation_type', 'operation', 'type', 'change_type'] as $key) {
            $value = $payload[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            $type = trim((string) $value);
            if (in_array($type, ['source_duplicate_mark', 'source_duplicate_cleanup', 'genealogy_source_cleanup'], true)) {
                return 'source_duplicate_mark';
            }
        }

        return null;
    }

    private function sourceRemediationResolution(array $payload): array
    {
        $treeId = $this->positiveInt($payload['tree_id'] ?? $payload['target_tree_id'] ?? null);
        $suspectSourceId = $this->positiveInt($payload['suspect_source_id'] ?? $payload['duplicate_source_id'] ?? $payload['source_id'] ?? null);
        $retainedSourceId = $this->positiveInt($payload['retained_source_id'] ?? $payload['canonical_source_id'] ?? $payload['target_source_id'] ?? null);
        $proposedChangeIds = $this->proposedChangeIds($payload);

        if ($suspectSourceId !== null && $retainedSourceId !== null) {
            return [
                'tree_id' => $treeId,
                'suspect_source_id' => $suspectSourceId,
                'retained_source_id' => $retainedSourceId,
                'proposed_change_ids' => [],
                'resolution_strategy' => null,
                'proposed_change_resolution' => null,
            ];
        }

        if ($proposedChangeIds === []) {
            return [
                'tree_id' => $treeId,
                'suspect_source_id' => $suspectSourceId,
                'retained_source_id' => $retainedSourceId,
                'proposed_change_ids' => [],
                'resolution_strategy' => null,
                'proposed_change_resolution' => null,
            ];
        }

        $proposedChangeResolution = $this->resolveSourceAddProposedChanges(
            $proposedChangeIds,
            $treeId,
            $suspectSourceId,
            $retainedSourceId,
        );
        $treeId ??= $this->positiveInt($proposedChangeResolution['tree_id'] ?? null);
        $pairResolution = $proposedChangeResolution['source_pair_resolution'];
        $clusterPreview = count($proposedChangeIds) > 2;

        return [
            'tree_id' => $treeId,
            'suspect_source_id' => $clusterPreview ? null : $pairResolution['suspect_source_id'],
            'retained_source_id' => $clusterPreview ? null : $pairResolution['retained_source_id'],
            'proposed_change_ids' => $proposedChangeIds,
            'resolution_strategy' => $clusterPreview ? 'duplicate_locator_cluster_preview' : ($pairResolution['strategy'] ?? null),
            'proposed_change_resolution' => $proposedChangeResolution,
            'cluster_preview' => $clusterPreview,
        ];
    }

    /**
     * @return list<int>
     */
    private function proposedChangeIds(array $payload): array
    {
        foreach (['proposed_change_ids', 'source_proposed_change_ids'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_array($value)) {
                $ids = [];
                foreach ($value as $item) {
                    $id = $this->positiveInt($item);
                    if ($id !== null) {
                        $ids[] = $id;
                    }
                }

                return $ids;
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                $text = trim((string) $value);
                $decoded = json_decode($text, true);
                $items = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', $text);
                $ids = [];
                foreach ($items ?: [] as $item) {
                    $id = $this->positiveInt($item);
                    if ($id !== null) {
                        $ids[] = $id;
                    }
                }

                return $ids;
            }
        }

        return [];
    }

    private function resolveSourceAddProposedChanges(
        array $proposedChangeIds,
        ?int $targetTreeId,
        ?int $explicitSuspectSourceId,
        ?int $explicitRetainedSourceId,
    ): array {
        $resolution = [
            'status' => 'blocked',
            'tree_id' => $targetTreeId,
            'proposed_change_ids' => $proposedChangeIds,
            'found_count' => 0,
            'all_source_add' => false,
            'all_resolved' => false,
            'all_same_tree' => false,
            'rows' => [],
            'messages' => [],
            'source_pair_resolution' => $this->sourcePairResolution(
                'blocked',
                null,
                null,
                null,
                [],
                'Cannot select retained and suspect sources until both source_add proposed changes resolve.',
            ),
        ];

        $uniqueIds = array_values(array_unique($proposedChangeIds));
        $placeholders = implode(',', array_fill(0, count($uniqueIds), '?'));
        $rows = DB::select(
            "SELECT id, tree_id, person_id, change_type, proposed_value, status
             FROM genealogy_proposed_changes
             WHERE id IN ({$placeholders})",
            $uniqueIds
        );
        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int) $row->id] = $row;
        }

        $resolution['found_count'] = count($rowsById);

        $rowTreeIds = [];
        $rowPersonIds = [];
        foreach ($proposedChangeIds as $proposedChangeId) {
            $row = $rowsById[$proposedChangeId] ?? null;
            if ($row === null) {
                $resolution['rows'][] = [
                    'proposed_change_id' => $proposedChangeId,
                    'resolution_status' => 'missing',
                    'message' => 'Proposed change row was not found.',
                ];

                continue;
            }

            $rowTreeId = $this->positiveInt($row->tree_id ?? null);
            if ($rowTreeId !== null) {
                $rowTreeIds[] = $rowTreeId;
            }
            $rowPersonId = $this->positiveInt($row->person_id ?? null);
            if ($rowPersonId !== null) {
                $rowPersonIds[] = $rowPersonId;
            }

            $scopeTreeId = $targetTreeId ?? $rowTreeId;
            $sourceResolution = $this->sourceIdResolution($row, $scopeTreeId);

            $resolution['rows'][] = [
                'proposed_change_id' => (int) $row->id,
                'tree_id' => $rowTreeId,
                'person_id' => $rowPersonId,
                'change_type' => (string) ($row->change_type ?? ''),
                'proposal_status' => (string) ($row->status ?? ''),
                'proposed_value' => $row->proposed_value,
                'resolution_status' => $sourceResolution['status'],
                'resolution_method' => $sourceResolution['method'],
                'resolved_source_id' => $sourceResolution['source_id'],
                'candidate_source_ids' => $sourceResolution['candidate_source_ids'],
                'url' => $sourceResolution['url'],
                'locator' => $sourceResolution['locator'],
                'locator_key' => $sourceResolution['locator_key'],
                'message' => $sourceResolution['message'],
            ];
        }

        $rowTreeIds = array_values(array_unique($rowTreeIds));
        $rowPersonIds = array_values(array_unique($rowPersonIds));
        if ($targetTreeId === null && count($rowTreeIds) === 1) {
            $resolution['tree_id'] = $rowTreeIds[0];
        }

        $resolution['all_found'] = count($rowsById) === count($uniqueIds);
        $resolution['all_source_add'] = $resolution['all_found']
            && collect($resolution['rows'])->every(fn (array $row): bool => ($row['change_type'] ?? null) === 'source_add');
        $resolution['all_resolved'] = $resolution['all_found']
            && collect($resolution['rows'])->every(fn (array $row): bool => ($row['resolution_status'] ?? null) === 'resolved');
        $resolution['all_same_tree'] = count($rowTreeIds) === 1
            && ($targetTreeId === null || $targetTreeId === $rowTreeIds[0]);
        $resolution['all_same_person'] = count($rowPersonIds) <= 1;
        $resolution['locator_groups'] = $this->sourceAddLocatorGroups($resolution['rows']);

        if (count($proposedChangeIds) !== 2) {
            $resolution['messages'][] = count($proposedChangeIds) > 2
                ? 'Multi-ID source cleanup is shown as duplicate locator groups; retained/suspect source row selection is intentionally blocked.'
                : 'Exactly two source_add proposed_change_ids are required to preview a source duplicate cleanup.';

            return $resolution;
        }

        if (! $resolution['all_source_add'] || ! $resolution['all_resolved'] || ! $resolution['all_same_tree'] || ! $resolution['all_same_person']) {
            if (! $resolution['all_same_tree']) {
                $resolution['messages'][] = 'Both source_add proposed changes must belong to the same target tree.';
            }
            if (! $resolution['all_same_person']) {
                $resolution['messages'][] = 'Both source_add proposed changes must belong to the same target person.';
            }
            if (! $resolution['all_source_add']) {
                $resolution['messages'][] = 'Both proposed_change_ids must point to source_add rows.';
            }
            if (! $resolution['all_resolved']) {
                $resolution['messages'][] = 'Each source_add proposed change must resolve to exactly one genealogy_sources row.';
            }

            return $resolution;
        }

        $sourceIds = array_values(array_unique(array_filter(
            array_map(fn (array $row): ?int => $this->positiveInt($row['resolved_source_id'] ?? null), $resolution['rows']),
        )));
        sort($sourceIds, SORT_NUMERIC);
        if (count($sourceIds) !== 2) {
            $resolution['source_pair_resolution'] = $this->sourcePairResolution(
                'blocked',
                null,
                null,
                null,
                [],
                'Resolved source_add proposed changes must identify exactly two distinct source rows.',
            );

            return $resolution;
        }

        if ($explicitSuspectSourceId !== null || $explicitRetainedSourceId !== null) {
            if (($explicitSuspectSourceId !== null && ! in_array($explicitSuspectSourceId, $sourceIds, true))
                || ($explicitRetainedSourceId !== null && ! in_array($explicitRetainedSourceId, $sourceIds, true))
                || ($explicitSuspectSourceId !== null && $explicitSuspectSourceId === $explicitRetainedSourceId)) {
                $resolution['source_pair_resolution'] = $this->sourcePairResolution(
                    'blocked',
                    null,
                    null,
                    null,
                    [],
                    'Explicit retained/suspect source IDs must be different rows from the resolved source_add sources.',
                );

                return $resolution;
            }

            $suspectSourceId = $explicitSuspectSourceId ?? array_values(array_diff($sourceIds, [$explicitRetainedSourceId]))[0];
            $retainedSourceId = $explicitRetainedSourceId ?? array_values(array_diff($sourceIds, [$explicitSuspectSourceId]))[0];
            $resolution['status'] = 'resolved';
            $resolution['source_pair_resolution'] = $this->sourcePairResolution(
                'resolved',
                $explicitSuspectSourceId !== null && $explicitRetainedSourceId !== null
                    ? 'explicit_source_ids'
                    : ($explicitRetainedSourceId !== null ? 'explicit_retained_source_id' : 'explicit_suspect_source_id'),
                $suspectSourceId,
                $retainedSourceId,
                $this->sourceUsageTotals($sourceIds),
                null,
            );

            return $resolution;
        }

        $usageTotals = $this->sourceUsageTotals($sourceIds);
        arsort($usageTotals, SORT_NUMERIC);
        $rankedSourceIds = array_keys($usageTotals);
        $retainedSourceId = (int) $rankedSourceIds[0];
        $suspectSourceId = (int) $rankedSourceIds[1];
        if ($usageTotals[$retainedSourceId] === $usageTotals[$suspectSourceId]) {
            $resolution['source_pair_resolution'] = $this->sourcePairResolution(
                'ambiguous',
                null,
                null,
                null,
                $usageTotals,
                'Retained source is ambiguous because resolved source rows have equal usage counts.',
            );

            return $resolution;
        }

        $resolution['status'] = 'resolved';
        $resolution['source_pair_resolution'] = $this->sourcePairResolution(
            'resolved',
            'retain_higher_usage_count',
            $suspectSourceId,
            $retainedSourceId,
            $usageTotals,
            null,
        );

        return $resolution;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sourceAddLocatorGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if (($row['change_type'] ?? null) !== 'source_add') {
                continue;
            }

            $key = $this->nullableText($row['locator_key'] ?? null);
            if ($key === null) {
                continue;
            }

            $groups[$key] ??= [
                'locator' => $this->nullableText($row['locator'] ?? null) ?? $key,
                'locator_key' => $key,
                'proposal_count' => 0,
                'proposed_change_ids' => [],
                'proposal_statuses' => [],
                'resolution_methods' => [],
                'resolved_source_ids' => [],
            ];
            $groups[$key]['proposal_count']++;
            $groups[$key]['proposed_change_ids'][] = (int) ($row['proposed_change_id'] ?? 0);
            $status = $this->nullableText($row['proposal_status'] ?? null) ?? 'unknown';
            $groups[$key]['proposal_statuses'][$status] = (int) ($groups[$key]['proposal_statuses'][$status] ?? 0) + 1;
            $method = $this->nullableText($row['resolution_method'] ?? null);
            if ($method !== null) {
                $groups[$key]['resolution_methods'][] = $method;
            }
            $sourceId = $this->positiveInt($row['resolved_source_id'] ?? null);
            if ($sourceId !== null) {
                $groups[$key]['resolved_source_ids'][] = $sourceId;
            }
        }

        $groups = array_filter($groups, fn (array $group): bool => (int) ($group['proposal_count'] ?? 0) > 1);
        foreach ($groups as &$group) {
            $group['proposed_change_ids'] = array_values(array_unique(array_filter($group['proposed_change_ids'])));
            sort($group['proposed_change_ids'], SORT_NUMERIC);
            $group['resolution_methods'] = array_values(array_unique($group['resolution_methods']));
            sort($group['resolution_methods']);
            $group['resolved_source_ids'] = array_values(array_unique($group['resolved_source_ids']));
            sort($group['resolved_source_ids'], SORT_NUMERIC);
            ksort($group['proposal_statuses']);
        }
        unset($group);

        usort($groups, fn (array $left, array $right): int => ((int) $right['proposal_count'] <=> (int) $left['proposal_count'])
            ?: strcmp((string) $left['locator'], (string) $right['locator']));

        return array_values($groups);
    }

    private function sourcePairResolution(
        string $status,
        ?string $strategy,
        ?int $suspectSourceId,
        ?int $retainedSourceId,
        array $usageTotals,
        ?string $message,
    ): array {
        return [
            'status' => $status,
            'strategy' => $strategy,
            'suspect_source_id' => $suspectSourceId,
            'retained_source_id' => $retainedSourceId,
            'usage_totals' => $usageTotals,
            'message' => $message,
        ];
    }

    private function sourceUsageTotals(array $sourceIds): array
    {
        $totals = [];
        foreach ($sourceIds as $sourceId) {
            $totals[$sourceId] = array_sum($this->sourceUsageCounts($sourceId));
        }

        return $totals;
    }

    private function sourceIdResolution(object $proposedChange, ?int $treeId): array
    {
        if ((string) ($proposedChange->change_type ?? '') !== 'source_add') {
            return [
                'status' => 'blocked',
                'method' => null,
                'source_id' => null,
                'candidate_source_ids' => [],
                'url' => null,
                'locator' => null,
                'locator_key' => null,
                'message' => 'Proposed change is not a source_add row.',
            ];
        }

        $proposedValue = $proposedChange->proposed_value ?? null;
        $sourceId = $this->sourceIdFromProposedValue($proposedValue);
        $url = $this->sourceUrlFromProposedValue($proposedValue);
        if ($sourceId !== null) {
            $sourceLocator = $this->sourceLocatorForId($sourceId, $treeId);
            if ($sourceLocator === null) {
                return [
                    'status' => 'unresolved',
                    'method' => 'proposed_value_source_id',
                    'source_id' => null,
                    'candidate_source_ids' => [$sourceId],
                    'url' => $url,
                    'locator' => $url,
                    'locator_key' => $this->locatorKey($url, $sourceId),
                    'message' => 'source_add proposed_value referenced a source_id that was not found in genealogy_sources for the target tree.',
                ];
            }

            return [
                'status' => 'resolved',
                'method' => 'proposed_value_source_id',
                'source_id' => $sourceId,
                'candidate_source_ids' => [$sourceId],
                'url' => $url ?? $sourceLocator['url'],
                'locator' => $url ?? $sourceLocator['locator'],
                'locator_key' => $this->locatorKey($url ?? $sourceLocator['url'], $sourceId),
                'message' => null,
            ];
        }

        if ($url === null) {
            return [
                'status' => 'unresolved',
                'method' => null,
                'source_id' => null,
                'candidate_source_ids' => [],
                'url' => null,
                'locator' => null,
                'locator_key' => null,
                'message' => 'source_add proposed_value did not contain a source_id or URL.',
            ];
        }

        $candidateSourceIds = $this->sourceCandidatesForUrl($treeId, $url);
        if (count($candidateSourceIds) === 1) {
            return [
                'status' => 'resolved',
                'method' => 'unique_url_match',
                'source_id' => $candidateSourceIds[0],
                'candidate_source_ids' => $candidateSourceIds,
                'url' => $url,
                'locator' => $url,
                'locator_key' => $this->locatorKey($url, $candidateSourceIds[0]),
                'message' => null,
            ];
        }

        return [
            'status' => $candidateSourceIds === [] ? 'unresolved' : 'ambiguous',
            'method' => 'url_match',
            'source_id' => null,
            'candidate_source_ids' => $candidateSourceIds,
            'url' => $url,
            'locator' => $url,
            'locator_key' => $this->locatorKey($url, null),
            'message' => $candidateSourceIds === []
                ? 'URL-only source_add did not match any genealogy_sources row.'
                : 'URL-only source_add matched multiple genealogy_sources rows.',
        ];
    }

    /**
     * @return array{url:?string,locator:string}|null
     */
    private function sourceLocatorForId(int $sourceId, ?int $treeId): ?array
    {
        $query = 'SELECT id, tree_id, url, title FROM genealogy_sources WHERE id = ?';
        $params = [$sourceId];
        if ($treeId !== null) {
            $query .= ' AND tree_id = ?';
            $params[] = $treeId;
        }

        $row = DB::selectOne($query, $params);
        if (! $row) {
            return null;
        }

        $url = $this->nullableText($row->url ?? null);

        return [
            'url' => $url,
            'locator' => $url ?? ('source_id:'.$sourceId),
        ];
    }

    private function locatorKey(?string $url, ?int $sourceId): ?string
    {
        $normalizedUrl = $this->normalizeUrl($url);
        if ($normalizedUrl !== '') {
            return 'url:'.$normalizedUrl;
        }

        return $sourceId !== null ? 'source_id:'.$sourceId : null;
    }

    private function sourceIdFromProposedValue(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $sourceId = $this->positiveInt($text);
        if ($sourceId !== null && preg_match('/^\d+$/', $text)) {
            return $sourceId;
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $this->positiveInt($decoded['source_id'] ?? null);
        }

        return null;
    }

    private function sourceUrlFromProposedValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded) && is_scalar($decoded['url'] ?? null) && preg_match('/^https?:\/\//i', (string) $decoded['url'])) {
            return trim((string) $decoded['url']);
        }

        if (preg_match('/^https?:\/\//i', $text)) {
            return $text;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function sourceCandidatesForUrl(?int $treeId, string $url): array
    {
        $variants = $this->urlLookupVariants($url);
        if ($variants === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $params = $variants;
        $treeFilter = '';
        if ($treeId !== null) {
            $treeFilter = ' AND tree_id = ?';
            $params[] = $treeId;
        }

        $rows = DB::select(
            "SELECT id, url
             FROM genealogy_sources
             WHERE url IS NOT NULL
                AND LOWER(TRIM(url)) IN ({$placeholders}){$treeFilter}",
            $params
        );

        $normalizedUrl = $this->normalizeUrl($url);
        $ids = [];
        foreach ($rows as $row) {
            if ($this->normalizeUrl($row->url ?? null) === $normalizedUrl) {
                $ids[] = (int) $row->id;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function urlLookupVariants(string $url): array
    {
        $normalized = $this->normalizeUrl($url);
        if ($normalized === '') {
            return [];
        }

        $variants = [
            $this->normalizeText($url),
            $normalized,
            $normalized.'/',
            'http://'.$normalized,
            'http://'.$normalized.'/',
            'https://'.$normalized,
            'https://'.$normalized.'/',
        ];

        return array_values(array_unique(array_filter($variants, fn (string $value): bool => $value !== '')));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sourceSnapshot(int $sourceId): ?array
    {
        $source = DB::selectOne(
            'SELECT id, tree_id, gedcom_id, uid, author, title, publication, repository,
                    call_number, url, source_quality, source_category, information_quality,
                    classification_confidence, classification_method, classified_at
             FROM genealogy_sources
             WHERE id = ?',
            [$sourceId]
        );

        if (! $source) {
            return null;
        }

        return [
            'id' => (int) $source->id,
            'tree_id' => (int) $source->tree_id,
            'gedcom_id' => $source->gedcom_id,
            'uid' => $source->uid,
            'title' => $source->title,
            'author' => $source->author,
            'publication' => $source->publication,
            'repository' => $source->repository,
            'call_number' => $source->call_number,
            'url' => $source->url,
            'source_quality' => $source->source_quality,
            'source_category' => $source->source_category,
            'information_quality' => $source->information_quality,
            'classification_confidence' => $source->classification_confidence !== null ? (float) $source->classification_confidence : null,
            'classification_method' => $source->classification_method,
            'classified_at' => $source->classified_at,
            'usage_counts' => $this->sourceUsageCounts($sourceId),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function sourceUsageCounts(int $sourceId): array
    {
        $row = DB::selectOne(
            'SELECT
                (SELECT COUNT(*) FROM genealogy_citations WHERE source_id = ?) AS citation_count,
                (SELECT COUNT(*) FROM genealogy_person_sources WHERE source_id = ?) AS person_link_count,
                (SELECT COUNT(*) FROM genealogy_family_sources WHERE source_id = ?) AS family_link_count,
                (SELECT COUNT(*) FROM genealogy_events WHERE source_id = ?) AS event_link_count,
                (SELECT COUNT(*) FROM genealogy_family_events WHERE source_id = ?) AS family_event_link_count,
                (SELECT COUNT(*) FROM genealogy_residences WHERE source_id = ?) AS residence_link_count,
                (SELECT COUNT(*) FROM genealogy_newspaper_clippings WHERE source_id = ?) AS clipping_link_count',
            array_fill(0, 7, $sourceId)
        );

        return [
            'citations' => (int) ($row->citation_count ?? 0),
            'person_links' => (int) ($row->person_link_count ?? 0),
            'family_links' => (int) ($row->family_link_count ?? 0),
            'events' => (int) ($row->event_link_count ?? 0),
            'family_events' => (int) ($row->family_event_link_count ?? 0),
            'residences' => (int) ($row->residence_link_count ?? 0),
            'clippings' => (int) ($row->clipping_link_count ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $suspectSource
     * @param  array<string, mixed>|null  $retainedSource
     * @return array<string, mixed>
     */
    private function duplicateSignals(?array $suspectSource, ?array $retainedSource): array
    {
        if ($suspectSource === null || $retainedSource === null) {
            return [
                'matching_fields' => [],
                'matching_field_count' => 0,
            ];
        }

        $matching = [];

        foreach (['gedcom_id', 'uid'] as $field) {
            if ($this->normalizeText($suspectSource[$field] ?? null) !== ''
                && $this->normalizeText($suspectSource[$field] ?? null) === $this->normalizeText($retainedSource[$field] ?? null)) {
                $matching[] = $field;
            }
        }

        if ($this->normalizeUrl($suspectSource['url'] ?? null) !== ''
            && $this->normalizeUrl($suspectSource['url'] ?? null) === $this->normalizeUrl($retainedSource['url'] ?? null)) {
            $matching[] = 'url';
        }

        foreach (['title', 'publication', 'repository', 'call_number'] as $field) {
            if ($this->normalizeText($suspectSource[$field] ?? null) !== ''
                && $this->normalizeText($suspectSource[$field] ?? null) === $this->normalizeText($retainedSource[$field] ?? null)) {
                $matching[] = $field;
            }
        }

        $matching = array_values(array_unique($matching));

        return [
            'matching_fields' => $matching,
            'matching_field_count' => count($matching),
        ];
    }

    /**
     * @param  array<string, mixed>  $resolution
     * @return array<int, array<string, string>>
     */
    private function proposedChangeResolutionGuards(array $resolution): array
    {
        $proposedChangeIds = $resolution['proposed_change_ids'] ?? [];
        if (! is_array($proposedChangeIds) || $proposedChangeIds === []) {
            return [];
        }

        $messages = $resolution['proposed_change_resolution']['messages'] ?? [];
        $message = is_array($messages) && $messages !== []
            ? implode(' ', array_map('strval', $messages))
            : 'Two source_add proposed changes must resolve to exactly two source rows.';

        return [
            [
                'name' => 'source_add_proposed_change_count',
                'status' => count($proposedChangeIds) === 2 ? 'pass' : 'fail',
                'message' => 'Exactly two source_add proposed_change_ids are required.',
            ],
            [
                'name' => 'source_add_proposed_changes_found',
                'status' => ($resolution['proposed_change_resolution']['all_found'] ?? false) === true ? 'pass' : 'fail',
                'message' => 'All referenced proposed_change_ids must still exist.',
            ],
            [
                'name' => 'source_add_proposed_change_types',
                'status' => ($resolution['proposed_change_resolution']['all_source_add'] ?? false) === true ? 'pass' : 'fail',
                'message' => 'Both proposed_change_ids must point to source_add rows.',
            ],
            [
                'name' => 'source_add_proposed_change_tree',
                'status' => ($resolution['proposed_change_resolution']['all_same_tree'] ?? false) === true ? 'pass' : 'fail',
                'message' => 'Both source_add proposed changes must belong to the same target tree.',
            ],
            [
                'name' => 'source_add_proposed_change_person',
                'status' => ($resolution['proposed_change_resolution']['all_same_person'] ?? false) === true ? 'pass' : 'fail',
                'message' => 'source_add proposed changes must belong to the same target person for grouped cleanup preview.',
            ],
            [
                'name' => 'source_add_source_resolution',
                'status' => ($resolution['proposed_change_resolution']['all_resolved'] ?? false) === true ? 'pass' : 'fail',
                'message' => $message,
            ],
            [
                'name' => 'source_add_retained_source_selection',
                'status' => ($resolution['proposed_change_resolution']['source_pair_resolution']['status'] ?? null) === 'resolved' ? 'pass' : 'fail',
                'message' => (string) ($resolution['proposed_change_resolution']['source_pair_resolution']['message']
                    ?? 'Retained and suspect sources must be explicit or unambiguous.'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $suspectSource
     * @param  array<string, mixed>|null  $retainedSource
     * @param  array<string, mixed>  $duplicateSignals
     * @return array<int, array<string, string>>
     */
    private function sourceDuplicateGuards(
        ?int $treeId,
        ?int $suspectSourceId,
        ?int $retainedSourceId,
        ?array $suspectSource,
        ?array $retainedSource,
        array $duplicateSignals,
    ): array {
        $guards = [
            [
                'name' => 'required_ids',
                'status' => $suspectSourceId !== null && $retainedSourceId !== null ? 'pass' : 'fail',
                'message' => 'suspect_source_id and retained_source_id are required.',
            ],
            [
                'name' => 'distinct_sources',
                'status' => $suspectSourceId !== null && $retainedSourceId !== null && $suspectSourceId !== $retainedSourceId ? 'pass' : 'fail',
                'message' => 'Suspect and retained sources must be different rows.',
            ],
            [
                'name' => 'sources_exist',
                'status' => $suspectSource !== null && $retainedSource !== null ? 'pass' : 'fail',
                'message' => 'Both source rows must still exist.',
            ],
        ];

        if ($suspectSource === null || $retainedSource === null) {
            return $guards;
        }

        $sameTree = $suspectSource['tree_id'] === $retainedSource['tree_id']
            && ($treeId === null || $treeId === $suspectSource['tree_id']);
        $matchingFieldCount = (int) ($duplicateSignals['matching_field_count'] ?? 0);

        $guards[] = [
            'name' => 'same_tree',
            'status' => $sameTree ? 'pass' : 'fail',
            'message' => 'Sources must belong to the same target tree.',
        ];
        $guards[] = [
            'name' => 'duplicate_signal',
            'status' => $matchingFieldCount > 0 ? 'pass' : 'fail',
            'message' => 'Source duplicate preview requires at least one matching GEDCOM id, UID, URL, title, publication, repository, or call number.',
        ];

        return $guards;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    private function normalizeText(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return preg_replace('/\s+/', ' ', strtolower(trim((string) $value))) ?? '';
    }

    private function normalizeUrl(mixed $value): string
    {
        $text = $this->normalizeText($value);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('#^https?://#', '', $text) ?? $text;

        return rtrim($text, '/');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function hash(array $state): string
    {
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $encoded === false ? serialize($state) : $encoded);
    }
}
