<?php

namespace App\Services;

class AgentDSGovernanceService
{
    public const SCHEMA = 'plos.agent_ds_governance.v1';

    public const PROFILE_DEFAULT = 'default_runtime';

    public const PROFILE_SIDECAR_READ_ONLY = 'sidecar_read_only';

    public const PROFILE_DS_BOUNDED_WRITE = 'ds_bounded_write';

    public const PROFILE_DS_GENEALOGY = 'ds_genealogy';

    public function buildMarker(array $context): array
    {
        $profile = $this->profileFromContext($context);
        $lane = $this->laneFor(
            strtolower((string) ($context['agent_id'] ?? '')),
            strtolower((string) ($context['review_type'] ?? ''))
        );
        $depth = max(0, (int) ($context['delegation_depth'] ?? $context['depth'] ?? 0));
        $maxDepth = $this->maxDepth($profile);
        $runtimeBudget = (int) ($context['runtime_budget_seconds'] ?? config('agents.delegation_governance.runtime_budget_seconds', 900));
        $maxIterations = (int) ($context['max_iterations'] ?? config('agents.delegation_governance.max_iterations', 8));

        return [
            'schema' => self::SCHEMA,
            'gate_version' => 'hwr-003-2026-05-25',
            'parent_session_id' => $this->stringOrNull($context['parent_session_id'] ?? null),
            'depth' => min($depth, $maxDepth),
            'max_depth' => $maxDepth,
            'runtime_budget_seconds' => max(60, min($runtimeBudget, (int) config('agents.delegation_governance.max_runtime_budget_seconds', 3600))),
            'max_iterations' => max(1, min($maxIterations, (int) config('agents.delegation_governance.max_iterations_ceiling', 20))),
            'write_scope' => $this->writeScopeFor($lane, $context),
            'ds_profile' => $profile,
            'ds_lane' => $lane,
            'approval_ref' => $this->stringOrNull($context['approval_ref'] ?? null),
            'operator_confirmed' => $this->boolValue($context['ds_confirmed'] ?? null) || $this->stringOrNull($context['approval_ref'] ?? null) !== null,
            'sidecar' => $this->boolValue($context['sidecar'] ?? null),
            'allowed_tool_classes' => $this->allowedToolClasses($profile),
        ];
    }

    public function classifyReviewSubmission(array $params, array $details): array
    {
        $agentId = strtolower((string) ($params['agent_id'] ?? ''));
        $reviewType = strtolower((string) ($params['review_type'] ?? ''));
        $profile = $this->profileFromContext($params + $details);
        $lane = $this->laneFor($agentId, $reviewType);
        $operatorConfirmed = $this->boolValue($params['ds_confirmed'] ?? $details['ds_confirmed'] ?? null)
            || $this->stringOrNull($params['approval_ref'] ?? $details['approval_ref'] ?? null) !== null;
        $marker = $this->buildMarker($params + $details);

        return array_merge($marker, [
            'profile' => $profile,
            'lane' => $lane,
            'operator_confirmed' => $operatorConfirmed,
            'writeback_allowed' => $operatorConfirmed && in_array($profile, [self::PROFILE_DS_BOUNDED_WRITE, self::PROFILE_DS_GENEALOGY], true),
            'delegation_depth' => max(0, (int) ($params['delegation_depth'] ?? $details['delegation_depth'] ?? $marker['depth'] ?? 0)),
            'requires_operator_confirmation_for_write' => in_array($profile, [self::PROFILE_DS_BOUNDED_WRITE, self::PROFILE_DS_GENEALOGY], true),
            'governance_version' => 'hwr-003-2026-05-25',
        ]);
    }

    public function evaluateToolExecution(string $toolName, array $toolDef, array $params, array $context): array
    {
        $context = $this->flattenGovernanceContext($context);
        $profile = $this->profileFromContext($context);
        $riskLevel = strtolower((string) ($toolDef['risk_level'] ?? 'read'));
        $toolClass = $this->toolClassForRisk($riskLevel);
        $delegationDepth = max(0, (int) ($context['delegation_depth'] ?? 0));
        $maxDepth = $this->maxDepth($profile);

        if ($profile === self::PROFILE_DEFAULT && ! $this->boolValue($context['sidecar'] ?? null)) {
            return $this->decision(true, $profile, $toolClass, 'default runtime profile');
        }

        if ($delegationDepth > $maxDepth) {
            return $this->decision(false, $profile, $toolClass, "delegation depth {$delegationDepth} exceeds max {$maxDepth}");
        }

        if (! in_array($toolClass, $this->allowedToolClasses($profile), true)) {
            return $this->decision(false, $profile, $toolClass, "tool class {$toolClass} is not allowed for {$profile}");
        }

        if ($profile === self::PROFILE_DS_BOUNDED_WRITE && $toolClass === 'bounded-write' && ! $this->boolValue($context['ds_confirmed'] ?? null)) {
            return $this->decision(false, $profile, $toolClass, 'bounded write requires ds_confirmed=true');
        }

        if ($profile === self::PROFILE_DS_GENEALOGY && $toolClass === 'bounded-write') {
            if (! $this->isGenealogyTool($toolName, $toolDef)) {
                return $this->decision(false, $profile, $toolClass, 'genealogy bounded profile only allows genealogy-scoped writes');
            }

            if ((int) ($context['tree_id'] ?? $params['tree_id'] ?? 0) <= 0) {
                return $this->decision(false, $profile, $toolClass, 'genealogy bounded write requires a positive tree_id');
            }

            $dryRun = $params['dry_run'] ?? $context['dry_run'] ?? true;
            $confirmed = $this->boolValue($params['confirm'] ?? null)
                || $this->boolValue($context['ds_confirmed'] ?? null);
            if ($dryRun === false && ! $confirmed) {
                return $this->decision(false, $profile, $toolClass, 'genealogy bounded write requires dry_run=true or explicit confirmation');
            }
        }

        return $this->decision(true, $profile, $toolClass, 'bounded governance allowed');
    }

    private function profileFromContext(array $context): string
    {
        $context = $this->flattenGovernanceContext($context);
        $profile = strtolower(trim((string) ($context['ds_profile'] ?? '')));
        if (in_array($profile, [self::PROFILE_SIDECAR_READ_ONLY, self::PROFILE_DS_BOUNDED_WRITE, self::PROFILE_DS_GENEALOGY], true)) {
            return $profile;
        }

        if ($this->boolValue($context['sidecar'] ?? null)) {
            return self::PROFILE_SIDECAR_READ_ONLY;
        }

        return self::PROFILE_DEFAULT;
    }

    private function flattenGovernanceContext(array $context): array
    {
        $marker = $context['delegation_governance'] ?? $context['ds_governance'] ?? null;
        if (! is_array($marker)) {
            return $context;
        }

        return array_merge($marker, $context);
    }

    private function laneFor(string $agentId, string $reviewType): string
    {
        if (str_starts_with($agentId, 'genealogy-') || str_starts_with($reviewType, 'genealogy_')) {
            return 'genea_ds';
        }

        if (in_array($reviewType, ['tool_proposal', 'skill_optimization'], true)) {
            return 'agent_dev_ds';
        }

        return 'general_ds';
    }

    private function writeScopeFor(string $lane, array $context): string
    {
        $explicit = trim((string) ($context['write_scope'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return match ($lane) {
            'genea_ds' => 'tree_scoped_confirmed_writes',
            'agent_dev_ds' => 'repo_bounded_reviewed_writes',
            default => 'read_first_reviewed_writes',
        };
    }

    private function maxDepth(string $profile): int
    {
        return match ($profile) {
            self::PROFILE_SIDECAR_READ_ONLY => (int) config('agents.delegation_governance.max_sidecar_depth', 1),
            self::PROFILE_DS_BOUNDED_WRITE, self::PROFILE_DS_GENEALOGY => (int) config('agents.delegation_governance.max_depth', 2),
            default => 0,
        };
    }

    private function allowedToolClasses(string $profile): array
    {
        return match ($profile) {
            self::PROFILE_SIDECAR_READ_ONLY => ['read'],
            self::PROFILE_DS_BOUNDED_WRITE, self::PROFILE_DS_GENEALOGY => ['read', 'bounded-write'],
            default => ['read', 'bounded-write', 'command-dangerous'],
        };
    }

    private function toolClassForRisk(string $riskLevel): string
    {
        return match ($riskLevel) {
            'write' => 'bounded-write',
            'destructive', 'blocked' => 'command-dangerous',
            default => 'read',
        };
    }

    private function isGenealogyTool(string $toolName, array $toolDef): bool
    {
        return str_starts_with($toolName, 'genealogy_')
            || ($toolDef['category'] ?? null) === 'genealogy'
            || ($toolDef['mcp_server'] ?? null) === 'genealogy';
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'confirmed'], true);
        }

        return (bool) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function decision(bool $allowed, string $profile, string $toolClass, string $reason): array
    {
        return [
            'allowed' => $allowed,
            'profile' => $profile,
            'tool_class' => $toolClass,
            'reason' => $reason,
            'allowed_tool_classes' => $this->allowedToolClasses($profile),
            'governance_version' => 'hwr-003-2026-05-25',
        ];
    }
}
