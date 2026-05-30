<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgentActionabilityGateService
{
    public const STATE_SILENT_AUDIT = 'silent_audit';

    public const STATE_OBSERVE_ONLY = 'observe_only';

    public const STATE_REVIEW_READY = 'review_ready';

    public const STATE_OPERATOR_ACTIONABLE = 'operator_actionable';

    private const STATES = [
        self::STATE_SILENT_AUDIT,
        self::STATE_OBSERVE_ONLY,
        self::STATE_REVIEW_READY,
        self::STATE_OPERATOR_ACTIONABLE,
    ];

    /** @var array<string, array<string, mixed>|null> */
    private array $policyCache = [];

    private const OPERATOR_REVIEW_TYPES = [
        'genealogy_finding',
        'genealogy_merge',
        'tool_proposal',
        'skill_optimization',
    ];

    public function classifyReviewSubmission(array $params, array $details): array
    {
        $reviewType = strtolower((string) ($params['review_type'] ?? 'finding'));
        $findingType = strtolower((string) ($params['finding_type'] ?? ''));
        $agentId = strtolower((string) ($params['agent_id'] ?? ''));
        $confidence = $this->numericConfidence($params['confidence'] ?? null);
        $text = $this->contextText($params, $details);
        $hardFailReasons = $this->qualityHardFailReasons($details);

        if ($this->hasPromptInjectionMarker($text)) {
            return $this->envelope(
                self::STATE_SILENT_AUDIT,
                ['prompt_injection_marker'],
                'Injected instructions are retained in logs only until a human-safe packet is assembled.'
            );
        }

        if ($this->hasExplicitState($params, $details)) {
            return $this->envelope(
                $this->explicitState($params, $details),
                ['explicit_actionability_state'],
                'Caller supplied an actionability state.'
            );
        }

        if ($text === '' && $details === []) {
            return $this->envelope(
                self::STATE_SILENT_AUDIT,
                ['empty_submission'],
                'Empty agent output has no operator action.'
            );
        }

        if ($hardFailReasons !== []) {
            return $this->envelope(
                self::STATE_OPERATOR_ACTIONABLE,
                ['quality_gate_hard_fail'],
                'Quality gate hard failures require operator attention.'
            );
        }

        if ($this->isNonActionableGenealogyProgress($reviewType, $details)) {
            return $this->envelope(
                self::STATE_OBSERVE_ONLY,
                ['genealogy_progress_acknowledgement'],
                'Genealogy progress acknowledgements are task history, not review work.'
            );
        }

        if ($this->isRoutineStatus($reviewType, $text)) {
            return $this->envelope(
                self::STATE_OBSERVE_ONLY,
                ['routine_status'],
                'Routine status output should be observed through digests and logs.'
            );
        }

        $policy = $this->policyFor($reviewType) ?? $this->policyFor($findingType);
        if ($policy !== null) {
            $policyState = (string) ($policy['state'] ?? self::STATE_REVIEW_READY);
            $policyReasons = ['review_type_registry_policy'];
            $summary = 'Review type registry supplied the actionability policy.';
            $minConfidence = $policy['min_confidence'] ?? null;

            if ($confidence !== null && $minConfidence !== null && $confidence < $minConfidence) {
                $policyState = self::STATE_REVIEW_READY;
                $policyReasons[] = 'below_actionability_confidence';
                $summary = 'Confidence is below the review-type operator-action threshold.';
            }

            if ($policyState === self::STATE_OPERATOR_ACTIONABLE && ! $this->hasMinimumActionableContext($params, $details)) {
                $policyState = self::STATE_REVIEW_READY;
                $policyReasons[] = 'missing_actionable_context';
                $summary = 'Operator-actionable review type is missing summary, evidence, or proposal context.';
            }

            return $this->envelope(
                $policyState,
                $policyReasons,
                $summary,
                [
                    'pushover_allowed' => $policy['pushover_allowed'] ?? null,
                    'min_confidence' => $minConfidence,
                    'policy_source' => 'review_type_registry',
                    'policy_review_type' => $policy['review_type'] ?? $reviewType,
                ]
            );
        }

        if ($this->requiresOperatorDecision($reviewType, $findingType, $agentId)) {
            if (! $this->hasMinimumActionableContext($params, $details)) {
                return $this->envelope(
                    self::STATE_REVIEW_READY,
                    ['operator_decision_type', 'missing_actionable_context'],
                    'Operator-actionable review type is missing summary, evidence, or proposal context.'
                );
            }

            return $this->envelope(
                self::STATE_OPERATOR_ACTIONABLE,
                ['operator_decision_type'],
                'Review type requires an explicit operator decision.'
            );
        }

        return $this->envelope(
            self::STATE_REVIEW_READY,
            ['reviewable_but_not_paging'],
            'Reviewable output can be retained without paging the operator.'
        );
    }

    public function shouldCreateReviewRow(array $classification): bool
    {
        return (bool) ($classification['review_hub'] ?? false);
    }

    public function shouldNotifyPushover(array $classification): bool
    {
        return (bool) ($classification['pushover_allowed'] ?? false);
    }

    private function envelope(string $state, array $reasons, string $summary, array $policy = []): array
    {
        $state = in_array($state, self::STATES, true) ? $state : self::STATE_REVIEW_READY;
        $pushoverAllowed = $state === self::STATE_OPERATOR_ACTIONABLE;
        if (array_key_exists('pushover_allowed', $policy) && $policy['pushover_allowed'] !== null) {
            $pushoverAllowed = $pushoverAllowed && (bool) $policy['pushover_allowed'];
        }

        $envelope = [
            'state' => $state,
            'review_hub' => in_array($state, [self::STATE_REVIEW_READY, self::STATE_OPERATOR_ACTIONABLE], true),
            'pushover_allowed' => $pushoverAllowed,
            'reasons' => array_values(array_unique($reasons)),
            'summary' => $summary,
            'gate_version' => 'hwr-013-2026-05-25',
        ];

        if (($policy['policy_source'] ?? null) === 'review_type_registry') {
            $envelope['policy_source'] = 'review_type_registry';
            $envelope['policy_review_type'] = $policy['policy_review_type'] ?? null;
            $envelope['min_confidence'] = $policy['min_confidence'] ?? null;
        }

        return $envelope;
    }

    private function contextText(array $params, array $details): string
    {
        $parts = [
            $params['agent_id'] ?? null,
            $params['review_type'] ?? null,
            $params['finding_type'] ?? null,
            $params['title'] ?? null,
            $params['summary'] ?? null,
        ];

        $encoded = json_encode($details, JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            $parts[] = $encoded;
        }

        return trim(implode("\n", array_filter(array_map(
            static fn ($value): string => is_scalar($value) ? (string) $value : '',
            $parts
        ), static fn (string $value): bool => trim($value) !== '')));
    }

    private function hasPromptInjectionMarker(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        $patterns = [
            '/\b(ignore|disregard)\s+(all\s+)?(previous|prior|above)\s+(instructions|prompts|directions)\b/i',
            '/\byou\s+are\s+now\s+(in\s+)?(system|developer|admin|root)\s+mode\b/i',
            '/\breveal\s+(the\s+)?(system|developer)\s+prompt\b/i',
            '/\bdo\s+not\s+(tell|mention|show)\s+(the\s+)?(user|operator|administrator)\b/i',
            '/\bBEGIN\s+(SYSTEM|DEVELOPER)\s+PROMPT\b/i',
            '/\bEND\s+(SYSTEM|DEVELOPER)\s+PROMPT\b/i',
            '/\bjailbreak\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function hasExplicitState(array $params, array $details): bool
    {
        return $this->explicitState($params, $details) !== '';
    }

    private function explicitState(array $params, array $details): string
    {
        $candidates = [
            $params['actionability_state'] ?? null,
            $details['actionability_state'] ?? null,
            is_array($details['actionability_gate'] ?? null) ? ($details['actionability_gate']['state'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $candidate = strtolower(trim($candidate));
            if (in_array($candidate, self::STATES, true)) {
                return $candidate;
            }
        }

        return '';
    }

    private function qualityHardFailReasons(array $details): array
    {
        $qualityGate = $details['quality_gate'] ?? null;
        if (! is_array($qualityGate)) {
            return [];
        }

        $hardFailReasons = $qualityGate['hard_fail_reasons'] ?? [];
        if (! is_array($hardFailReasons)) {
            return trim((string) $hardFailReasons) === '' ? [] : [(string) $hardFailReasons];
        }

        return array_values(array_filter(
            array_map(static fn ($reason): string => is_scalar($reason) ? trim((string) $reason) : '', $hardFailReasons),
            static fn (string $reason): bool => $reason !== ''
        ));
    }

    private function isNonActionableGenealogyProgress(string $reviewType, array $details): bool
    {
        if ($reviewType !== 'genealogy_finding') {
            return false;
        }

        $proposals = $details['proposals'] ?? null;
        if (! is_array($proposals) || $proposals === []) {
            return false;
        }

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                return false;
            }

            $changeType = strtolower((string) ($proposal['change_type'] ?? $proposal['relationship_type'] ?? ''));
            if (! in_array($changeType, ['search_complete'], true)) {
                return false;
            }
        }

        return true;
    }

    private function isRoutineStatus(string $reviewType, string $text): bool
    {
        if (! in_array($reviewType, ['status', 'status_change', 'alert', 'suggestion'], true)) {
            return false;
        }

        return preg_match('/\b(no\s+(issues|findings|action|required|changes)|all\s+(clear|healthy|operational)|routine|status\s+only)\b/i', $text) === 1;
    }

    private function requiresOperatorDecision(string $reviewType, string $findingType, string $agentId): bool
    {
        return in_array($reviewType, self::OPERATOR_REVIEW_TYPES, true)
            || in_array($findingType, self::OPERATOR_REVIEW_TYPES, true)
            || str_starts_with($reviewType, 'genealogy_')
            || str_starts_with($agentId, 'genealogy-');
    }

    private function hasMinimumActionableContext(array $params, array $details): bool
    {
        $summary = trim((string) ($params['summary'] ?? ''));
        if (strlen($summary) >= 12) {
            return true;
        }

        return $this->hasMeaningfulDetailPayload($details);
    }

    private function hasMeaningfulDetailPayload(mixed $value, ?string $key = null): bool
    {
        $ignoredKeys = [
            'actionability_gate',
            'created_at',
            'ds_governance',
            'gate_version',
            'hard_fail_reasons',
            'priority',
            'quality_gate',
            'review_type',
            'updated_at',
        ];

        if ($key !== null && in_array(strtolower($key), $ignoredKeys, true)) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                if ($this->hasMeaningfulDetailPayload($childValue, is_string($childKey) ? $childKey : null)) {
                    return true;
                }
            }

            return false;
        }

        if (is_string($value)) {
            return strlen(trim($value)) >= 3;
        }

        return false;
    }

    /**
     * @return array{review_type:string,state:string,min_confidence:?float,pushover_allowed:?bool}|null
     */
    private function policyFor(string $reviewType): ?array
    {
        $reviewType = strtolower(trim($reviewType));
        if ($reviewType === '') {
            return null;
        }

        if (array_key_exists($reviewType, $this->policyCache)) {
            return $this->policyCache[$reviewType];
        }

        try {
            foreach ([
                'review_type_registry',
                'actionability_state',
                'actionability_min_confidence',
                'actionability_pushover_allowed',
            ] as $required) {
                if ($required === 'review_type_registry') {
                    if (! Schema::hasTable($required)) {
                        return $this->policyCache[$reviewType] = null;
                    }

                    continue;
                }

                if (! Schema::hasColumn('review_type_registry', $required)) {
                    return $this->policyCache[$reviewType] = null;
                }
            }

            $row = DB::table('review_type_registry')
                ->select('name', 'actionability_state', 'actionability_min_confidence', 'actionability_pushover_allowed')
                ->where('name', $reviewType)
                ->where('enabled', 1)
                ->first();
        } catch (\Throwable) {
            return $this->policyCache[$reviewType] = null;
        }

        if ($row === null) {
            return $this->policyCache[$reviewType] = null;
        }

        $state = strtolower(trim((string) ($row->actionability_state ?? '')));
        if (! in_array($state, self::STATES, true)) {
            return $this->policyCache[$reviewType] = null;
        }

        return $this->policyCache[$reviewType] = [
            'review_type' => (string) $row->name,
            'state' => $state,
            'min_confidence' => $this->numericConfidence($row->actionability_min_confidence ?? null),
            'pushover_allowed' => $row->actionability_pushover_allowed === null
                ? null
                : (bool) $row->actionability_pushover_allowed,
        ];
    }

    private function numericConfidence(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }
}
