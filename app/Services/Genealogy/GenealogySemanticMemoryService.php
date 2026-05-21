<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenealogySemanticMemoryService
{
    public function recordRejectedName(
        int $personId,
        string $candidateName,
        string $reasonCode = 'rejected',
        ?int $sourceId = null,
        ?string $agentId = null,
        float $confidence = 0.5
    ): int {
        $name = trim($candidateName);
        $confidence = max(0.0, min(1.0, $confidence));

        $memoryId = (int) DB::table('agent_semantic_memory')->insertGetId([
            'entity_type' => 'genealogy_person',
            'entity_id' => $personId,
            'fact_type' => 'rejected_name',
            'fact_key' => $this->normalizeFactKey($name),
            'fact_value' => $name,
            'confidence' => $confidence,
            'consensus_status' => 'disputed',
            'source_count' => 1,
        ]);

        DB::table('agent_semantic_fact_sources')->insert([
            'memory_id' => $memoryId,
            'source_type' => Str::limit('rejected_name:'.$this->normalizeReasonCode($reasonCode), 50, ''),
            'source_id' => $sourceId,
            'confidence' => $confidence,
            'agent_id' => $agentId,
        ]);

        return $memoryId;
    }

    public function recordNonFtName(
        int $treeId,
        string $candidateName,
        string $reasonCode = 'not_ft_member',
        ?int $sourceId = null,
        ?string $agentId = null,
        float $confidence = 0.8
    ): int {
        $name = trim($candidateName);
        $confidence = max(0.0, min(1.0, $confidence));

        $memoryId = (int) DB::table('agent_semantic_memory')->insertGetId([
            'entity_type' => 'genealogy_tree',
            'entity_id' => $treeId,
            'fact_type' => 'non_ft_name',
            'fact_key' => $this->normalizeFactKey($name),
            'fact_value' => $name,
            'confidence' => $confidence,
            'consensus_status' => 'disputed',
            'source_count' => 1,
        ]);

        DB::table('agent_semantic_fact_sources')->insert([
            'memory_id' => $memoryId,
            'source_type' => Str::limit('non_ft_name:'.$this->normalizeReasonCode($reasonCode), 50, ''),
            'source_id' => $sourceId,
            'confidence' => $confidence,
            'agent_id' => $agentId,
        ]);

        return $memoryId;
    }

    /**
     * Record an operator/tool review outcome so future Genea runs can reuse accepted examples
     * and rejected guardrails without re-reading raw proposal rows.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordReviewDecision(
        int $treeId,
        string $proposalType,
        int $proposalId,
        string $decision,
        array $payload = [],
        ?string $agentId = null,
        float $confidence = 0.75
    ): int {
        $decision = $this->normalizeDecision($decision);
        $proposalType = $this->normalizeReasonCode($proposalType);
        $confidence = max(0.0, min(1.0, $confidence));

        $memoryId = (int) DB::table('agent_semantic_memory')->insertGetId([
            'entity_type' => 'genealogy_tree',
            'entity_id' => $treeId,
            'fact_type' => $decision === 'rejected' ? 'review_rejected' : 'review_accepted',
            'fact_key' => $this->normalizeFactKey($proposalType.':'.$proposalId.':'.$decision),
            'fact_value' => $this->compactFactValue([
                'schema' => 'genealogy_review_decision_memory.v1',
                'tree_id' => $treeId,
                'proposal_type' => $proposalType,
                'proposal_id' => $proposalId,
                'decision' => $decision,
                'payload' => $payload,
            ]),
            'confidence' => $confidence,
            'consensus_status' => $decision === 'rejected' ? 'disputed' : 'agreed',
            'source_count' => 1,
        ]);

        DB::table('agent_semantic_fact_sources')->insert([
            'memory_id' => $memoryId,
            'source_type' => Str::limit('review_decision:'.$proposalType.':'.$decision, 50, ''),
            'source_id' => $proposalId,
            'confidence' => $confidence,
            'agent_id' => $agentId,
        ]);

        return $memoryId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordHealthAuditFinding(
        int $treeId,
        string $issueCode,
        array $payload = [],
        ?string $agentId = null,
        float $confidence = 0.65
    ): int {
        $issueCode = $this->normalizeFactKey($issueCode);
        $confidence = max(0.0, min(1.0, $confidence));

        $memoryId = (int) DB::table('agent_semantic_memory')->insertGetId([
            'entity_type' => 'genealogy_tree',
            'entity_id' => $treeId,
            'fact_type' => 'health_audit_issue',
            'fact_key' => $this->normalizeFactKey($issueCode),
            'fact_value' => $this->compactFactValue([
                'schema' => 'genealogy_health_audit_memory.v1',
                'tree_id' => $treeId,
                'issue_code' => $issueCode,
                'payload' => $payload,
            ]),
            'confidence' => $confidence,
            'consensus_status' => 'evolving',
            'source_count' => 1,
        ]);

        DB::table('agent_semantic_fact_sources')->insert([
            'memory_id' => $memoryId,
            'source_type' => Str::limit('health_audit:'.$issueCode, 50, ''),
            'source_id' => null,
            'confidence' => $confidence,
            'agent_id' => $agentId,
        ]);

        return $memoryId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordMediaIntakeOutcome(
        int $treeId,
        int $runId,
        string $runKey,
        array $payload = [],
        ?string $agentId = null,
        float $confidence = 0.75
    ): int {
        $runKey = trim($runKey);
        $confidence = max(0.0, min(1.0, $confidence));

        $memoryId = (int) DB::table('agent_semantic_memory')->insertGetId([
            'entity_type' => 'genealogy_tree',
            'entity_id' => $treeId,
            'fact_type' => 'media_intake_outcome',
            'fact_key' => $this->normalizeFactKey($runKey !== '' ? $runKey : 'run:'.$runId),
            'fact_value' => $this->compactFactValue([
                'schema' => 'genealogy_media_intake_memory.v1',
                'tree_id' => $treeId,
                'run_id' => $runId,
                'run_key' => $runKey,
                'payload' => $payload,
            ]),
            'confidence' => $confidence,
            'consensus_status' => 'agreed',
            'source_count' => 1,
        ]);

        DB::table('agent_semantic_fact_sources')->insert([
            'memory_id' => $memoryId,
            'source_type' => 'media_intake_run',
            'source_id' => $runId,
            'confidence' => $confidence,
            'agent_id' => $agentId,
        ]);

        return $memoryId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordSourceBackfillOutcome(
        int $treeId,
        int $sourceId,
        string $status,
        array $payload = [],
        ?string $agentId = null,
        float $confidence = 0.75
    ): int {
        $status = $this->normalizeReasonCode($status);
        $status = $status !== '' ? $status : 'unknown';
        $confidence = max(0.0, min(1.0, $confidence));

        $memoryId = (int) DB::table('agent_semantic_memory')->insertGetId([
            'entity_type' => 'genealogy_source',
            'entity_id' => $sourceId,
            'fact_type' => 'source_media_backfill_outcome',
            'fact_key' => $this->normalizeFactKey('source:'.$sourceId.':'.$status),
            'fact_value' => $this->compactFactValue([
                'schema' => 'genealogy_source_media_backfill_memory.v1',
                'tree_id' => $treeId,
                'source_id' => $sourceId,
                'status' => $status,
                'payload' => $payload,
            ]),
            'confidence' => $confidence,
            'consensus_status' => in_array($status, ['captured', 'media_reused'], true) ? 'agreed' : 'evolving',
            'source_count' => 1,
        ]);

        DB::table('agent_semantic_fact_sources')->insert([
            'memory_id' => $memoryId,
            'source_type' => Str::limit('source_backfill:'.$status, 50, ''),
            'source_id' => $sourceId,
            'confidence' => $confidence,
            'agent_id' => $agentId,
        ]);

        return $memoryId;
    }

    /**
     * Record reusable local Genea research wisdom from a reviewed sprint,
     * document pass, source-capture attempt, or identity decision.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordLessonMemory(
        int $treeId,
        string $lessonType,
        string $title,
        string $lesson,
        array $payload = [],
        ?string $agentId = null,
        float $confidence = 0.8
    ): int {
        $lessonType = $this->normalizeReasonCode($lessonType);
        $title = Str::limit(trim($title), 160, '');
        $lesson = Str::limit(trim($lesson), 2400, '');
        $confidence = max(0.0, min(1.0, $confidence));

        $memoryId = (int) DB::table('agent_semantic_memory')->insertGetId([
            'entity_type' => 'genealogy_tree',
            'entity_id' => $treeId,
            'fact_type' => $lessonType,
            'fact_key' => $this->normalizeFactKey($lessonType.':'.$title),
            'fact_value' => $this->compactFactValue([
                'schema' => 'genealogy_lesson_memory.v1',
                'tree_id' => $treeId,
                'lesson_type' => $lessonType,
                'title' => $title,
                'lesson' => $lesson,
                'payload' => $payload,
            ]),
            'confidence' => $confidence,
            'consensus_status' => 'agreed',
            'source_count' => max(1, count((array) ($payload['source_ids'] ?? []))),
        ]);

        DB::table('agent_semantic_fact_sources')->insert([
            'memory_id' => $memoryId,
            'source_type' => Str::limit('genea_lesson:'.$lessonType, 50, ''),
            'source_id' => null,
            'confidence' => $confidence,
            'agent_id' => $agentId,
        ]);

        return $memoryId;
    }

    /**
     * @return list<string>
     */
    public function getPersonRejectedNames(int $personId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $rows = DB::table('agent_semantic_memory')
            ->where('entity_type', 'genealogy_person')
            ->where('entity_id', $personId)
            ->where('fact_type', 'rejected_name')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('fact_value')
            ->all();

        $seen = [];
        $names = [];

        foreach ($rows as $row) {
            $name = trim((string) $row);
            $key = $this->normalizeFactKey($name);

            if ($name === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $names[] = $name;
        }

        return $names;
    }

    private function normalizeFactKey(string $value): string
    {
        $normalized = Str::of($value)
            ->lower()
            ->squish()
            ->toString();

        if (mb_strlen($normalized) > 100) {
            $normalized = mb_substr($normalized, 0, 83).':'.substr(sha1($normalized), 0, 16);
        }

        return $normalized !== '' ? $normalized : 'unknown';
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $normalized = Str::of($reasonCode)
            ->lower()
            ->replaceMatches('/[^a-z0-9_:-]+/', '_')
            ->trim('_')
            ->limit(35, '')
            ->toString();

        return $normalized !== '' ? $normalized : 'rejected';
    }

    private function normalizeDecision(string $decision): string
    {
        $normalized = $this->normalizeReasonCode($decision);

        return in_array($normalized, ['rejected', 'declined', 'incorrect'], true)
            ? 'rejected'
            : 'accepted';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function compactFactValue(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return Str::limit(is_string($json) ? $json : '{}', 4000, '');
    }
}
