<?php

namespace App\Services\AgentMetrics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AgentUsefulYieldService
{
    /**
     * Return outcome counts for submissions created in one bounded window.
     * This cohort view is shared by runtime throttles so ignored findings are
     * not mistaken for useful agent yield.
     *
     * @return array{available:bool,submitted:int,accepted:int,declined:int,reviewed_only:int,expired:int,pending:int}
     */
    public function submissionOutcomeSnapshot(
        string $agentId,
        string $reviewType,
        int $hours
    ): array {
        $empty = [
            'available' => false,
            'submitted' => 0,
            'accepted' => 0,
            'declined' => 0,
            'reviewed_only' => 0,
            'expired' => 0,
            'pending' => 0,
        ];

        if (! Schema::hasTable('agent_review_queue')) {
            return $empty;
        }

        try {
            $row = DB::table('agent_review_queue')
                ->selectRaw("COUNT(*) AS submitted,
                    SUM(CASE WHEN status IN ('approved', 'resolved') THEN 1 ELSE 0 END) AS accepted,
                    SUM(CASE WHEN status IN ('rejected', 'ignored', 'dismissed') THEN 1 ELSE 0 END) AS declined,
                    SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) AS reviewed_only,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending")
                ->where('agent_id', $agentId)
                ->where('review_type', $reviewType)
                ->where('created_at', '>=', Carbon::now()->subHours(max(1, min(720, $hours))))
                ->first();
        } catch (Throwable $e) {
            Log::warning('Agent useful-yield submission snapshot failed', [
                'agent_id' => $agentId,
                'review_type' => $reviewType,
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }

        return [
            'available' => true,
            'submitted' => (int) ($row->submitted ?? 0),
            'accepted' => (int) ($row->accepted ?? 0),
            'declined' => (int) ($row->declined ?? 0),
            'reviewed_only' => (int) ($row->reviewed_only ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'pending' => (int) ($row->pending ?? 0),
        ];
    }

    /**
     * @param  list<object|array<string,mixed>>  $agents
     * @return list<array<string,mixed>>
     */
    public function enrich(array $agents, int $hours): array
    {
        $reviewsByAgent = $this->reviewOutcomes(max(1, min(720, $hours)));
        $seen = [];
        $enriched = array_map(function (object|array $agent) use ($reviewsByAgent, &$seen): array {
            $row = (array) $agent;
            $agentId = trim((string) ($row['agent_id'] ?? ''));
            $seen[$agentId] = true;
            $reviews = $reviewsByAgent[$agentId] ?? [];

            return $this->scoreAgent($row, $reviews);
        }, $agents);

        foreach ($reviewsByAgent as $agentId => $reviews) {
            if (isset($seen[$agentId])) {
                continue;
            }

            $enriched[] = $this->scoreAgent([
                'agent_id' => $agentId,
                'total_runs' => 0,
                'completed' => 0,
                'errors' => 0,
                'tool_calls' => 0,
                'hallucinations' => 0,
                'avg_duration_ms' => null,
                'last_activity' => null,
            ], $reviews);
        }

        return $enriched;
    }

    /**
     * @param  array<string,mixed>  $agent
     * @param  array<string,mixed>  $reviews
     * @return array<string,mixed>
     */
    public function scoreAgent(array $agent, array $reviews): array
    {
        $completed = max(0, (int) ($agent['completed'] ?? 0));
        $errors = max(0, (int) ($agent['errors'] ?? 0));
        $accepted = max(0, (int) ($reviews['accepted'] ?? 0));
        $declined = max(0, (int) ($reviews['declined'] ?? 0));
        $reviewedOnly = max(0, (int) ($reviews['reviewed_only'] ?? 0));
        $expired = max(0, (int) ($reviews['expired'] ?? 0));
        $pending = max(0, (int) ($reviews['pending'] ?? 0));
        $submitted = max(0, (int) ($reviews['submitted'] ?? 0));
        $decisions = $accepted + $declined;

        $reliability = ($completed + $errors) > 0 ? $completed / ($completed + $errors) : 0.0;
        $decisionYield = $decisions > 0 ? $accepted / $decisions : null;
        $reviewBurden = $pending / max(1, $completed + $pending);
        $decisionComponent = $decisionYield ?? $reliability;
        $hasYieldEvidence = ($completed + $errors + $decisions) > 0;
        $score = $hasYieldEvidence
            ? max(0.0, min(100.0, ($reliability * 70) + ($decisionComponent * 30) - ($reviewBurden * 15)))
            : null;

        return array_merge($agent, [
            'review_submissions' => $submitted,
            'review_accepted' => $accepted,
            'review_declined' => $declined,
            'reviewed_preview_only' => $reviewedOnly,
            'review_expired' => $expired,
            'pending_reviews' => $pending,
            'completion_reliability_pct' => round($reliability * 100, 1),
            'decision_yield_pct' => $decisionYield === null ? null : round($decisionYield * 100, 1),
            'review_load_per_completion' => round($pending / max(1, $completed), 2),
            'useful_yield_score' => $score === null ? null : round($score, 1),
            'yield_basis' => '70% completion reliability + 30% accepted review decisions - pending review burden',
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $agents
     * @return array<string,int|float|null>
     */
    public function totals(array $agents): array
    {
        $accepted = array_sum(array_column($agents, 'review_accepted'));
        $declined = array_sum(array_column($agents, 'review_declined'));
        $pending = array_sum(array_column($agents, 'pending_reviews'));
        $scores = array_values(array_filter(
            array_column($agents, 'useful_yield_score'),
            static fn (mixed $score): bool => is_numeric($score)
        ));

        return [
            'review_accepted' => (int) $accepted,
            'review_declined' => (int) $declined,
            'pending_reviews' => (int) $pending,
            'decision_yield_pct' => ($accepted + $declined) > 0
                ? round(($accepted / ($accepted + $declined)) * 100, 1)
                : null,
            'avg_useful_yield_score' => $scores !== [] ? round(array_sum($scores) / count($scores), 1) : null,
        ];
    }

    /**
     * @return array<string,array<string,int>>
     */
    private function reviewOutcomes(int $hours): array
    {
        if (! Schema::hasTable('agent_review_queue')) {
            return [];
        }

        $cutoff = Carbon::now()->subHours($hours);
        try {
            $rows = DB::table('agent_review_queue')
                ->selectRaw("agent_id,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS submitted,
                SUM(CASE WHEN reviewed_at >= ? AND status IN ('approved', 'resolved') THEN 1 ELSE 0 END) AS accepted,
                SUM(CASE WHEN reviewed_at >= ? AND status IN ('rejected', 'ignored', 'dismissed') THEN 1 ELSE 0 END) AS declined,
                SUM(CASE WHEN reviewed_at >= ? AND status = 'reviewed' THEN 1 ELSE 0 END) AS reviewed_only,
                SUM(CASE WHEN reviewed_at >= ? AND status = 'expired' THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN status = 'pending' AND (expires_at IS NULL OR expires_at > ?) THEN 1 ELSE 0 END) AS pending", [
                    $cutoff,
                    $cutoff,
                    $cutoff,
                    $cutoff,
                    $cutoff,
                    now(),
                ])
                ->where(function ($query) use ($cutoff): void {
                    $query->where('created_at', '>=', $cutoff)
                        ->orWhere('reviewed_at', '>=', $cutoff)
                        ->orWhere('status', 'pending');
                })
                ->groupBy('agent_id')
                ->get();
        } catch (Throwable $e) {
            Log::warning('Agent useful-yield review query failed', ['error' => $e->getMessage()]);

            return [];
        }

        $outcomes = [];
        foreach ($rows as $row) {
            $outcomes[(string) $row->agent_id] = [
                'submitted' => (int) $row->submitted,
                'accepted' => (int) $row->accepted,
                'declined' => (int) $row->declined,
                'reviewed_only' => (int) $row->reviewed_only,
                'expired' => (int) $row->expired,
                'pending' => (int) $row->pending,
            ];
        }

        return $outcomes;
    }
}
