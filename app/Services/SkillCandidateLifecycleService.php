<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SkillCandidateLifecycleService
{
    public const SCHEMA = 'plos.skill_candidate_lifecycle.v1';

    public const STATE_PROPOSED = 'proposed';

    public const STATE_SCANNED = 'scanned';

    public const STATE_NEEDS_REVIEW = 'needs_review';

    public const STATE_APPROVED = 'approved';

    public const STATE_INSTALLED = 'installed';

    public const STATE_REJECTED = 'rejected';

    public const STATE_SUPERSEDED = 'superseded';

    /**
     * @return array<string, mixed>
     */
    public function prepareForReview(string $agentId, array $candidate, string $source = 'skill_optimization'): array
    {
        $scan = $this->scanCandidate($candidate);
        $duplicate = $this->duplicateHint($agentId, $candidate);
        $blocked = (bool) ($scan['blocked'] ?? false);

        return [
            'schema' => self::SCHEMA,
            'state' => $blocked ? self::STATE_REJECTED : self::STATE_NEEDS_REVIEW,
            'state_history' => [
                ['state' => self::STATE_PROPOSED, 'at' => now()->toIso8601String(), 'reason' => 'candidate received'],
                ['state' => self::STATE_SCANNED, 'at' => now()->toIso8601String(), 'reason' => 'static scan completed'],
                ['state' => $blocked ? self::STATE_REJECTED : self::STATE_NEEDS_REVIEW, 'at' => now()->toIso8601String(), 'reason' => $blocked ? 'static scan blocked candidate' : 'operator review required'],
            ],
            'source' => $source,
            'agent_id' => $agentId,
            'operator_required' => ! $blocked,
            'install_allowed' => false,
            'scan' => $scan,
            'duplicate' => $duplicate,
            'preferred_action' => $duplicate['has_duplicate'] ? 'patch_existing_review' : 'patch_existing_skill',
        ];
    }

    /**
     * @return array{blocked: bool, risk: string, findings: array<int, array<string, string>>, content_hash: string}
     */
    public function scanCandidate(array $candidate): array
    {
        $content = $this->candidateText($candidate);
        $findings = [];

        foreach ($this->dangerousCommandPatterns() as $pattern => $label) {
            if (preg_match($pattern, $content)) {
                $findings[] = ['category' => 'dangerous_command', 'severity' => 'critical', 'label' => $label];
            }
        }

        foreach ($this->secretPatterns() as $pattern => $label) {
            if (preg_match($pattern, $content)) {
                $findings[] = ['category' => 'secret', 'severity' => 'critical', 'label' => $label];
            }
        }

        foreach ($this->privateDataPatterns() as $pattern => $label) {
            if (preg_match($pattern, $content)) {
                $findings[] = ['category' => 'private_data', 'severity' => 'high', 'label' => $label];
            }
        }

        foreach ((array) config('injection_patterns.patterns', []) as $pattern => $label) {
            if (preg_match($pattern, $content)) {
                $findings[] = ['category' => 'prompt_injection', 'severity' => 'high', 'label' => (string) $label];
            }
        }

        $blocked = $findings !== [];

        return [
            'blocked' => $blocked,
            'risk' => $blocked ? $this->highestSeverity($findings) : 'low',
            'findings' => $findings,
            'content_hash' => hash('sha256', $content),
        ];
    }

    /**
     * @return array{has_duplicate: bool, strategy: string, review_id: ?int, reason: string}
     */
    public function duplicateHint(string $agentId, array $candidate): array
    {
        $type = (string) ($candidate['type'] ?? $candidate['amendment_type'] ?? 'unknown');

        try {
            $row = DB::table('agent_review_queue')
                ->select(['id', 'status'])
                ->where('agent_id', $agentId)
                ->where('review_type', 'skill_optimization')
                ->whereIn('status', ['pending', 'approved'])
                ->where('title', 'like', '%'.$type.'%')
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable $e) {
            Log::debug('SkillCandidateLifecycle: duplicate check failed', [
                'agent_id' => $agentId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            $row = null;
        }

        if ($row !== null) {
            return [
                'has_duplicate' => true,
                'strategy' => 'patch_existing_review',
                'review_id' => (int) $row->id,
                'reason' => 'existing pending or approved skill optimization for this agent/type',
            ];
        }

        return [
            'has_duplicate' => false,
            'strategy' => 'patch_existing_skill',
            'review_id' => null,
            'reason' => 'no active duplicate review found; amend the existing skill rather than creating a duplicate skill',
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function transitionDetails(array $details, string $state, string $reason): array
    {
        $lifecycle = $details['candidate_lifecycle'] ?? [];
        if (! is_array($lifecycle)) {
            $lifecycle = ['schema' => self::SCHEMA];
        }

        $lifecycle['schema'] = $lifecycle['schema'] ?? self::SCHEMA;
        $lifecycle['previous_state'] = $lifecycle['state'] ?? null;
        $lifecycle['state'] = $state;
        $lifecycle['updated_at'] = now()->toIso8601String();
        $lifecycle['install_allowed'] = $state === self::STATE_APPROVED;
        $lifecycle['state_history'] = array_values(array_merge(
            is_array($lifecycle['state_history'] ?? null) ? $lifecycle['state_history'] : [],
            [['state' => $state, 'at' => now()->toIso8601String(), 'reason' => $reason]]
        ));

        $details['candidate_lifecycle'] = $lifecycle;

        return $details;
    }

    public function updateReviewLifecycleState(int $itemId, string $state, string $reason): void
    {
        if ($itemId <= 0) {
            return;
        }

        try {
            $row = DB::table('agent_review_queue')
                ->select(['details'])
                ->where('id', $itemId)
                ->first();

            if ($row === null) {
                return;
            }

            $details = json_decode((string) ($row->details ?? '{}'), true);
            if (! is_array($details)) {
                $details = [];
            }

            DB::table('agent_review_queue')
                ->where('id', $itemId)
                ->update([
                    'details' => json_encode($this->transitionDetails($details, $state, $reason)),
                    'updated_at' => now()->toDateTimeString(),
                ]);
        } catch (\Throwable $e) {
            Log::debug('SkillCandidateLifecycle: state update failed', [
                'item_id' => $itemId,
                'state' => $state,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function candidateText(array $candidate): string
    {
        return json_encode($candidate, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @return array<string, string>
     */
    private function dangerousCommandPatterns(): array
    {
        return [
            '/\brm\s+-rf\s+\/(?:\s|$)/i' => 'recursive root delete',
            '/\bgit\s+reset\s+--hard\b/i' => 'destructive git reset',
            '/\b(?:curl|wget)\b[^\n|;]*\|\s*(?:sh|bash)\b/i' => 'pipe remote script to shell',
            '/\bchmod\s+777\b/i' => 'world-writable chmod',
            '/\bDROP\s+DATABASE\b/i' => 'drop database command',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function secretPatterns(): array
    {
        return [
            '/-----BEGIN\s+(?:RSA\s+|EC\s+|OPENSSH\s+)?PRIVATE\s+KEY-----/i' => 'private key material',
            '/\b(?:api[_-]?key|secret|password|token)\s*[:=]\s*[\'"]?[A-Za-z0-9_\-]{16,}/i' => 'credential-like assignment',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function privateDataPatterns(): array
    {
        return [
            '/\b\d{3}-\d{2}-\d{4}\b/' => 'ssn-like identifier',
            '#/(?:home|Users)/[^/\\s"\']+/(?:[^\\s"\']+)#i' => 'operator-local private path',
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $findings
     */
    private function highestSeverity(array $findings): string
    {
        $order = ['critical' => 3, 'high' => 2, 'medium' => 1, 'low' => 0];
        $highest = 'low';

        foreach ($findings as $finding) {
            $severity = $finding['severity'] ?? 'low';
            if (($order[$severity] ?? 0) > ($order[$highest] ?? 0)) {
                $highest = $severity;
            }
        }

        return $highest;
    }
}
