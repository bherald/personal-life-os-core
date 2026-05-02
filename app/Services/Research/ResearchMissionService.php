<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ResearchMissionService - Manages research missions lifecycle
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class ResearchMissionService
{
    private string $connection = 'pgsql_rag';

    /**
     * List missions with optional filters
     */
    public function list(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['domain_category'])) {
            $where[] = 'domain_category = ?';
            $params[] = $filters['domain_category'];
        }
        if (!empty($filters['mission_type'])) {
            $where[] = 'mission_type = ?';
            $params[] = $filters['mission_type'];
        }

        $limit = $filters['limit'] ?? 50;
        $offset = $filters['offset'] ?? 0;

        $whereClause = implode(' AND ', $where);

        $total = DB::connection($this->connection)->select(
            "SELECT COUNT(*) as count FROM research_missions WHERE {$whereClause}",
            $params
        )[0]->count;

        $missions = DB::connection($this->connection)->select(
            "SELECT * FROM research_missions WHERE {$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return [
            'missions' => array_map(fn($m) => $this->formatMission($m), $missions),
            'total' => (int) $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Get a single mission by ID
     */
    public function get(string $id): ?array
    {
        $result = DB::connection($this->connection)->select(
            "SELECT * FROM research_missions WHERE id = ?",
            [$id]
        );

        return !empty($result) ? $this->formatMission($result[0]) : null;
    }

    /**
     * Create a new mission
     */
    public function create(array $data): array
    {
        try {
            $id = Str::uuid()->toString();

            DB::connection($this->connection)->statement("
                INSERT INTO research_missions (
                    id, title, description, mission_type, domain_category,
                    query_template, constraints, status, depth_level,
                    verification_level, max_sources, time_limit_minutes,
                    frequency, rag_category, is_active, require_human_approval,
                    created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, 'pending', ?, ?, ?, ?, ?, ?, true, ?, 'api', NOW(), NOW())
            ", [
                $id,
                $data['title'],
                $data['description'] ?? null,
                $data['mission_type'] ?? 'knowledge_capture',
                $data['domain_category'] ?? 'general',
                $data['query'] ?? $data['title'],
                json_encode($data['constraints'] ?? []),
                $data['depth_level'] ?? 3,
                $data['verification_level'] ?? 'standard',
                $data['max_sources'] ?? 20,
                $data['time_limit_minutes'] ?? 30,
                $data['recurrence_schedule'] ?? null,
                $data['domain_category'] ?? 'general',
                !($data['auto_index_to_rag'] ?? false),
            ]);

            return ['success' => true, 'mission_id' => $id, 'mission' => $this->get($id)];
        } catch (\Throwable $e) {
            Log::error('ResearchMissionService: Create failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update a mission
     */
    public function update(string $id, array $data): array
    {
        try {
            $sets = ['updated_at = NOW()'];
            $params = [];

            foreach (['title', 'description', 'query_template', 'mission_type', 'domain_category', 'depth_level', 'verification_level', 'max_sources', 'time_limit_minutes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $sets[] = "{$field} = ?";
                    $params[] = $data[$field];
                }
            }

            if (isset($data['constraints'])) {
                $sets[] = "constraints = ?::jsonb";
                $params[] = json_encode($data['constraints']);
            }

            $params[] = $id;

            DB::connection($this->connection)->update(
                "UPDATE research_missions SET " . implode(', ', $sets) . " WHERE id = ?",
                $params
            );

            return ['success' => true, 'mission' => $this->get($id)];
        } catch (\Throwable $e) {
            Log::error('ResearchMissionService: Update failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a mission
     */
    public function delete(string $id): array
    {
        try {
            DB::connection($this->connection)->delete(
                "DELETE FROM research_missions WHERE id = ?",
                [$id]
            );
            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pause a running mission
     */
    public function pause(string $id): array
    {
        return $this->setStatus($id, 'paused');
    }

    /**
     * Resume a paused mission
     */
    public function resume(string $id): array
    {
        return $this->setStatus($id, 'pending');
    }

    /**
     * Retry a failed mission
     */
    public function retry(string $id): array
    {
        try {
            DB::connection($this->connection)->update(
                "UPDATE research_missions SET status = 'pending', last_error = NULL, error_count = 0, updated_at = NOW() WHERE id = ?",
                [$id]
            );
            return ['success' => true, 'mission' => $this->get($id)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Refresh mission data (re-query for latest state)
     */
    public function refreshMission(string $id): array
    {
        $mission = $this->get($id);
        return $mission ? ['success' => true, 'mission' => $mission] : ['success' => false, 'error' => 'Not found'];
    }

    /**
     * Get recurring missions that are due to run
     */
    public function getDueRecurringMissions(): array
    {
        return DB::connection($this->connection)->select("
            SELECT * FROM research_missions
            WHERE is_active = true
            AND frequency IS NOT NULL
            AND (
                last_ran_at IS NULL
                OR (
                    CASE frequency
                        WHEN 'daily' THEN last_ran_at < NOW() - INTERVAL '1 day'
                        WHEN 'weekly' THEN last_ran_at < NOW() - INTERVAL '7 days'
                        WHEN 'monthly' THEN last_ran_at < NOW() - INTERVAL '30 days'
                        WHEN 'quarterly' THEN last_ran_at < NOW() - INTERVAL '90 days'
                        ELSE false
                    END
                )
            )
            ORDER BY last_ran_at ASC NULLS FIRST
        ");
    }

    /**
     * Get mission progress
     */
    public function getProgress(string $id): array
    {
        $mission = $this->get($id);
        if (!$mission) {
            return ['success' => false, 'error' => 'Not found'];
        }

        return [
            'success' => true,
            'progress' => [
                'status' => $mission['status'],
                'progress_pct' => $mission['progress_pct'],
                'current_phase' => $mission['current_phase'],
                'phase_details' => $mission['phase_details'],
                'facts_discovered' => $mission['facts_discovered'],
                'facts_verified' => $mission['facts_verified'],
                'sources_used' => $mission['sources_used'],
            ],
        ];
    }

    /**
     * Get mission results/facts
     */
    public function getResults(string $id): array
    {
        $facts = DB::connection($this->connection)->select("
            SELECT * FROM research_facts
            WHERE mission_id = ?
            ORDER BY confidence_score DESC
        ", [$id]);

        return [
            'success' => true,
            'facts' => array_map(fn($f) => (array) $f, $facts),
            'total' => count($facts),
        ];
    }

    /**
     * Get aggregate statistics
     */
    public function getStats(): array
    {
        $stats = DB::connection($this->connection)->select("
            SELECT
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE status = 'pending') as pending,
                COUNT(*) FILTER (WHERE status = 'running') as running,
                COUNT(*) FILTER (WHERE status = 'completed') as completed,
                COUNT(*) FILTER (WHERE status = 'failed') as failed,
                COUNT(*) FILTER (WHERE frequency IS NOT NULL) as recurring,
                COALESCE(SUM(facts_discovered), 0) as total_facts_discovered,
                COALESCE(SUM(facts_verified), 0) as total_facts_verified,
                COALESCE(SUM(sources_used), 0) as total_sources_used
            FROM research_missions
        ");

        $s = $stats[0];
        return [
            'total_missions' => (int) $s->total,
            'by_status' => [
                'pending' => (int) $s->pending,
                'running' => (int) $s->running,
                'completed' => (int) $s->completed,
                'failed' => (int) $s->failed,
            ],
            'recurring_missions' => (int) $s->recurring,
            'total_facts_discovered' => (int) $s->total_facts_discovered,
            'total_facts_verified' => (int) $s->total_facts_verified,
            'total_sources_used' => (int) $s->total_sources_used,
        ];
    }

    private function setStatus(string $id, string $status): array
    {
        try {
            DB::connection($this->connection)->update(
                "UPDATE research_missions SET status = ?, updated_at = NOW() WHERE id = ?",
                [$status, $id]
            );
            return ['success' => true, 'mission' => $this->get($id)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function formatMission(object $m): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'description' => $m->description,
            'mission_type' => $m->mission_type,
            'domain_category' => $m->domain_category,
            'query_template' => $m->query_template,
            'constraints' => json_decode($m->constraints ?? '[]', true),
            'status' => $m->status,
            'progress_pct' => (float) ($m->progress_pct ?? 0),
            'current_phase' => $m->current_phase,
            'phase_details' => json_decode($m->phase_details ?? '{}', true),
            'depth_level' => (int) ($m->depth_level ?? 3),
            'verification_level' => $m->verification_level ?? 'standard',
            'max_sources' => (int) ($m->max_sources ?? 20),
            'time_limit_minutes' => (int) ($m->time_limit_minutes ?? 30),
            'facts_discovered' => (int) ($m->facts_discovered ?? 0),
            'facts_verified' => (int) ($m->facts_verified ?? 0),
            'facts_rejected' => (int) ($m->facts_rejected ?? 0),
            'sources_discovered' => (int) ($m->sources_discovered ?? 0),
            'sources_used' => (int) ($m->sources_used ?? 0),
            'last_error' => $m->last_error,
            'error_count' => (int) ($m->error_count ?? 0),
            'frequency' => $m->frequency,
            'rag_category' => $m->rag_category,
            'is_active' => (bool) ($m->is_active ?? true),
            'require_human_approval' => (bool) ($m->require_human_approval ?? true),
            'created_by' => $m->created_by,
            'created_at' => $m->created_at,
            'updated_at' => $m->updated_at,
            'started_at' => $m->started_at,
            'completed_at' => $m->completed_at,
        ];
    }
}
