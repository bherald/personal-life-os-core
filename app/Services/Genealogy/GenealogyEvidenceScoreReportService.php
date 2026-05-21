<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenealogyEvidenceScoreReportService
{
    public const LEVEL_STRONG = 'strong';

    public const LEVEL_MEDIUM = 'medium';

    public const LEVEL_WEAK = 'weak';

    public const LEVEL_CONFLICT = 'conflict';

    public const LEVEL_MISSING = 'missing';

    private const LEVELS = [
        self::LEVEL_STRONG,
        self::LEVEL_MEDIUM,
        self::LEVEL_WEAK,
        self::LEVEL_CONFLICT,
        self::LEVEL_MISSING,
    ];

    public function collect(?int $treeId = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $treeIds = $treeId !== null ? [$treeId] : $this->knownTreeIds();

        $trees = [];
        foreach ($treeIds as $id) {
            if ($id <= 0) {
                continue;
            }

            $trees[] = $this->collectTree($id, $limit);
        }

        return [
            'version' => 1,
            'tool' => 'genealogy_evidence_score_report',
            'mode' => 'observe',
            'read_only' => true,
            'mutation_allowed' => false,
            'tree_id' => $treeId,
            'tree_count' => count($trees),
            'score_levels' => $this->scoreLevelDefinitions(),
            'summary' => $this->aggregateSummary($trees),
            'trees' => $trees,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function collectTree(int $treeId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $personChanges = $this->scoreRows(
            'person_change',
            $this->personChangeRows($treeId, $limit)
        );
        $relationships = $this->scoreRows(
            'relationship',
            $this->relationshipRows($treeId, $limit)
        );

        return [
            'tree_id' => $treeId,
            'counts' => $this->countsForRows([...$personChanges, ...$relationships]),
            'person_changes' => $personChanges,
            'relationships' => $relationships,
        ];
    }

    public function scoreLevelDefinitions(): array
    {
        return [
            self::LEVEL_STRONG => 'confidence >= 0.85 with evidence summary and at least one source',
            self::LEVEL_MEDIUM => 'confidence >= 0.65 with evidence summary and at least one source',
            self::LEVEL_WEAK => 'confidence >= 0.50 with evidence summary and at least one source',
            self::LEVEL_CONFLICT => 'rejected/reverted/conflict-marked proposal or evidence text indicates conflicting evidence',
            self::LEVEL_MISSING => 'missing confidence, evidence summary, or evidence source',
        ];
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function scoreRows(string $proposalType, array $rows): array
    {
        return array_map(function (object $row) use ($proposalType): array {
            $sources = $this->decodeSources($row->evidence_sources ?? null);
            $summary = trim((string) ($row->evidence_summary ?? ''));
            $confidence = isset($row->confidence) ? (float) $row->confidence : null;
            $status = (string) ($row->status ?? '');
            $level = $this->scoreLevel($confidence, $summary, $sources, $status);

            return [
                'proposal_type' => $proposalType,
                'proposal_id' => (int) ($row->id ?? 0),
                'tree_id' => (int) ($row->tree_id ?? 0),
                'person_id' => isset($row->person_id) ? (int) $row->person_id : null,
                'related_person_id' => isset($row->related_person_id) ? (int) $row->related_person_id : null,
                'change_type' => $row->change_type ?? null,
                'relationship_type' => $row->relationship_type ?? null,
                'field_name' => $row->field_name ?? null,
                'status' => $status,
                'confidence' => $confidence,
                'score_level' => $level,
                'has_evidence_summary' => $summary !== '',
                'evidence_source_count' => count($sources),
                'evidence_summary_excerpt' => $summary !== '' ? mb_substr($summary, 0, 240) : null,
                'agent_id' => $row->agent_id ?? null,
                'created_at' => $row->created_at ?? null,
            ];
        }, $rows);
    }

    /**
     * @param  list<string>  $sources
     */
    private function scoreLevel(?float $confidence, string $summary, array $sources, string $status): string
    {
        $summaryLower = strtolower($summary);
        if (in_array($status, ['rejected', 'reverted'], true) || str_contains($summaryLower, 'conflict') || str_contains($summaryLower, 'conflicting')) {
            return self::LEVEL_CONFLICT;
        }

        if ($confidence === null || $summary === '' || $sources === []) {
            return self::LEVEL_MISSING;
        }

        return match (true) {
            $confidence >= 0.85 => self::LEVEL_STRONG,
            $confidence >= 0.65 => self::LEVEL_MEDIUM,
            default => self::LEVEL_WEAK,
        };
    }

    /**
     * @return list<string>
     */
    private function decodeSources(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            return [trim((string) $raw)];
        }

        return array_values(array_filter(
            array_map(static fn ($source): string => trim((string) $source), $decoded),
            static fn (string $source): bool => $source !== ''
        ));
    }

    /**
     * @return array<int, object>
     */
    private function personChangeRows(int $treeId, int $limit): array
    {
        if (! Schema::hasTable('genealogy_proposed_changes')) {
            return [];
        }

        if (! Schema::hasColumn('genealogy_proposed_changes', 'tree_id')) {
            if (! $this->hasColumns('genealogy_proposed_changes', ['person_id', 'change_type', 'field_name', 'confidence', 'agent_id'])) {
                return [];
            }

            return DB::select(
                'SELECT pc.id, p.tree_id, pc.person_id, pc.change_type, pc.field_name,
                        pc.evidence_sources, pc.evidence_summary, pc.confidence,
                        pc.agent_id, pc.status, pc.created_at
                 FROM genealogy_proposed_changes pc
                 JOIN genealogy_persons p ON p.id = pc.person_id
                 WHERE p.tree_id = ?
                 ORDER BY FIELD(pc.status, \'pending\', \'approved\', \'rejected\', \'applied\'),
                          pc.confidence ASC,
                          pc.created_at DESC
                 LIMIT ?',
                [$treeId, $limit]
            );
        }

        return DB::select(
            'SELECT id, tree_id, person_id, change_type, field_name, evidence_sources,
                    evidence_summary, confidence, agent_id, status, created_at
             FROM genealogy_proposed_changes
             WHERE tree_id = ?
             ORDER BY FIELD(status, \'pending\', \'approved\', \'rejected\', \'applied\'),
                      confidence ASC,
                      created_at DESC
             LIMIT ?',
            [$treeId, $limit]
        );
    }

    /**
     * @return array<int, object>
     */
    private function relationshipRows(int $treeId, int $limit): array
    {
        if (! Schema::hasTable('genealogy_proposed_relationships')) {
            return [];
        }

        if (! Schema::hasColumn('genealogy_proposed_relationships', 'tree_id')) {
            if (! $this->hasColumns('genealogy_proposed_relationships', ['person_id', 'related_person_id', 'relationship_type', 'confidence', 'agent_id'])) {
                return [];
            }

            return DB::select(
                'SELECT pr.id, p.tree_id, pr.person_id, pr.related_person_id,
                        pr.relationship_type, pr.evidence_sources, pr.evidence_summary,
                        pr.confidence, pr.agent_id, pr.status, pr.created_at
                 FROM genealogy_proposed_relationships pr
                 JOIN genealogy_persons p ON p.id = pr.person_id
                 WHERE p.tree_id = ?
                 ORDER BY FIELD(pr.status, \'pending\', \'approved\', \'rejected\', \'applied\'),
                          pr.confidence ASC,
                          pr.created_at DESC
                 LIMIT ?',
                [$treeId, $limit]
            );
        }

        return DB::select(
            'SELECT id, tree_id, person_id, related_person_id, relationship_type,
                    evidence_sources, evidence_summary, confidence, agent_id, status, created_at
             FROM genealogy_proposed_relationships
             WHERE tree_id = ?
             ORDER BY FIELD(status, \'pending\', \'approved\', \'rejected\', \'applied\'),
                      confidence ASC,
                      created_at DESC
             LIMIT ?',
            [$treeId, $limit]
        );
    }

    /**
     * @return list<int>
     */
    private function knownTreeIds(): array
    {
        if (Schema::hasTable('genealogy_trees')) {
            return DB::table('genealogy_trees')
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function countsForRows(array $rows): array
    {
        $counts = array_fill_keys(self::LEVELS, 0);
        foreach ($rows as $row) {
            $level = (string) ($row['score_level'] ?? self::LEVEL_MISSING);
            $counts[$level] = ($counts[$level] ?? 0) + 1;
        }

        return $counts + ['total' => count($rows)];
    }

    private function aggregateSummary(array $trees): array
    {
        $summary = array_fill_keys(self::LEVELS, 0) + ['total' => 0];

        foreach ($trees as $tree) {
            foreach ($summary as $key => $value) {
                $summary[$key] = $value + (int) ($tree['counts'][$key] ?? 0);
            }
        }

        return $summary;
    }
}
