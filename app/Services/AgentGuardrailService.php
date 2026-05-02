<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\DTOs\PolicyDecision;

/**
 * Agent Guardrail Service
 *
 * Pre-tool validation layer for AI agent operations. Implements the OpenAI Agents SDK
 * guardrails pattern with configurable rules via database.
 *
 * Features:
 * - Pre-execution validation before potentially dangerous operations
 * - Configurable rules (database or config-based)
 * - Confirmation requirements for risky actions
 * - Hard blocks for dangerous operations (file deletion, system commands)
 * - Integration with AIService and WorkflowEngine
 *
 * Rule Types:
 * - block: Completely prevent operation
 * - confirm: Require explicit confirmation before proceeding
 * - log: Allow but log the action
 * - allow: Explicitly allow (whitelist)
 *
 * Usage:
 * ```php
 * $guardrail = app(AgentGuardrailService::class);
 *
 * // Validate a tool call before execution
 * $result = $guardrail->validate('file_delete', ['path' => '/etc/passwd']);
 * if (!$result['allowed']) {
 *     throw new GuardrailException($result['reason']);
 * }
 *
 * // Check if confirmation is required
 * if ($result['requires_confirmation']) {
 *     // Prompt user for confirmation
 * }
 * ```
 */
class AgentGuardrailService
{
    private const CACHE_KEY = 'guardrail_rules';

    private const CACHE_TTL = 300; // 5 minutes

    // Default dangerous patterns (always blocked unless explicitly allowed)
    private const DEFAULT_BLOCKED_OPERATIONS = [
        'system_command',
        'shell_exec',
        'process_kill',
        'env_modify',
        'credential_access',
    ];

    // Default operations requiring confirmation
    private const DEFAULT_CONFIRM_OPERATIONS = [
        'file_delete',
        'file_overwrite',
        'database_drop',
        'database_truncate',
        'email_send_bulk',
        'workflow_delete',
        'user_delete',
    ];

    // Dangerous file paths (regex patterns)
    private const DANGEROUS_PATH_PATTERNS = [
        '#^/etc/#',
        '#^/sys/#',
        '#^/proc/#',
        '#^/dev/#',
        '#^/boot/#',
        '#^/root/#',
        '#^~/.ssh/#',
        '#^/home/[^/]+/\.ssh/#',
        '#\.env$#',
        '#\.htaccess$#',
        '#/\.git/#',
        '#credentials#i',
        '#password#i',
        '#secret#i',
    ];

    // Dangerous command patterns
    private const DANGEROUS_COMMAND_PATTERNS = [
        '#rm\s+-rf\s+/#',
        '#rm\s+-r\s+/#',
        '#dd\s+if=#',
        '#mkfs#',
        '#fdisk#',
        '#parted#',
        '#chmod\s+777#',
        '#chown\s+root#',
        '#sudo\s+#',
        '#su\s+-#',
        '#curl\s+.*\|\s*(ba)?sh#',
        '#wget\s+.*\|\s*(ba)?sh#',
        '#eval\s*\(#',
        '#exec\s*\(#',
    ];

    /** @var array Cached rules from database */
    private ?array $cachedRules = null;

    /** @var bool Whether to use database rules (false = config only) */
    private bool $useDatabaseRules;

    public function __construct()
    {
        $this->useDatabaseRules = config('services.guardrails.use_database', true);
    }

    /**
     * Validate an operation before execution
     *
     * @param  string  $operation  Operation type (e.g., 'file_delete', 'shell_exec')
     * @param  array  $context  Operation context (path, command, target, etc.)
     * @param  string|null  $agentId  Optional agent identifier for rule scoping
     * @return array Validation result with keys: allowed, requires_confirmation, reason, rule_id
     */
    public function validate(string $operation, array $context = [], ?string $agentId = null): array
    {
        $result = [
            'allowed' => true,
            'requires_confirmation' => false,
            'reason' => null,
            'rule_id' => null,
            'severity' => 'low',
        ];

        // 0. 3b offline/hybrid policy gate (P02c) — consults the single authority
        //    before any other check so that a non-default profile can refuse an
        //    operation even if the per-rule/per-severity checks would otherwise
        //    allow it. Under the `default` profile this is a no-op; every other
        //    profile layers on class/path/remote-domain restrictions.
        $policyDecision = $this->evaluateOfflinePolicy($operation, $context);
        if ($policyDecision !== null && ! $policyDecision->allowed) {
            $policyResult = [
                'allowed' => false,
                'requires_confirmation' => false,
                'reason' => $policyDecision->reason,
                'rule_id' => 'offline_policy:'.$policyDecision->profile,
                'severity' => 'high',
                'policy_decision' => $policyDecision->toArray(),
            ];
            $this->logGuardrailEvent('blocked', $operation, array_merge($context, [
                'policy_decision' => $policyDecision->toArray(),
            ]), $policyDecision->reason, $agentId);

            return $policyResult;
        }

        // 1. Check hard-coded safety patterns first (highest priority)
        $safetyCheck = $this->checkSafetyPatterns($operation, $context);
        if (! $safetyCheck['allowed']) {
            $this->logGuardrailEvent('blocked', $operation, $context, $safetyCheck['reason'], $agentId);

            return $safetyCheck;
        }

        // 2. Check database/config rules
        $ruleCheck = $this->checkRules($operation, $context, $agentId);
        if (! $ruleCheck['allowed']) {
            $this->logGuardrailEvent('blocked', $operation, $context, $ruleCheck['reason'], $agentId);

            return $ruleCheck;
        }

        // 3. Check if confirmation is required
        if ($ruleCheck['requires_confirmation']) {
            $this->logGuardrailEvent('confirm_required', $operation, $context, $ruleCheck['reason'], $agentId);

            return $ruleCheck;
        }

        // 4. Default operations requiring confirmation
        if (in_array($operation, self::DEFAULT_CONFIRM_OPERATIONS, true)) {
            $result['requires_confirmation'] = true;
            $result['reason'] = "Operation '{$operation}' requires confirmation";
            $result['severity'] = 'medium';
            $this->logGuardrailEvent('confirm_required', $operation, $context, $result['reason'], $agentId);

            return $result;
        }

        // 5. If the offline policy required confirmation but the operation is
        //    otherwise allowed, propagate that flag so downstream callers get
        //    the profile's confirmation contract (P02c).
        if ($policyDecision !== null && $policyDecision->requiresConfirmation) {
            $result['requires_confirmation'] = true;
            $result['reason'] = $result['reason'] ?? $policyDecision->reason;
            $result['severity'] = $result['severity'] === 'low' ? 'medium' : $result['severity'];
            $result['policy_decision'] = $policyDecision->toArray();
            $this->logGuardrailEvent('confirm_required', $operation, array_merge($context, [
                'policy_decision' => $policyDecision->toArray(),
            ]), $policyDecision->reason, $agentId);
        }

        // 6. Log allowed operations if configured
        if ($this->shouldLogOperation($operation)) {
            $this->logGuardrailEvent('allowed', $operation, $context, null, $agentId);
        }

        return $result;
    }

    /**
     * Consult OfflinePolicyService for a 3b profile-aware decision. Returns
     * null when the service is unavailable (test harness without the binding)
     * so existing behavior is preserved for callers outside the 3b flow.
     */
    private function evaluateOfflinePolicy(string $operation, array $context): ?PolicyDecision
    {
        try {
            $service = app(\App\Services\OfflinePolicyService::class);
        } catch (\Throwable $e) {
            return null;
        }

        try {
            return $service->evaluateOperation($operation, $context);
        } catch (\Throwable $e) {
            Log::warning('AgentGuardrailService: offline policy evaluation failed — passing through', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check hard-coded safety patterns
     */
    private function checkSafetyPatterns(string $operation, array $context): array
    {
        $result = [
            'allowed' => true,
            'requires_confirmation' => false,
            'reason' => null,
            'rule_id' => 'builtin',
            'severity' => 'critical',
        ];

        // Block default dangerous operations
        if (in_array($operation, self::DEFAULT_BLOCKED_OPERATIONS, true)) {
            $result['allowed'] = false;
            $result['reason'] = "Operation '{$operation}' is blocked by security policy";

            return $result;
        }

        // Check file path patterns
        $path = $context['path'] ?? $context['file'] ?? $context['target'] ?? null;
        if ($path) {
            foreach (self::DANGEROUS_PATH_PATTERNS as $pattern) {
                if (preg_match($pattern, $path)) {
                    $result['allowed'] = false;
                    $result['reason'] = "Access to path '{$path}' is blocked by security policy";

                    return $result;
                }
            }
        }

        // Check command patterns
        $command = $context['command'] ?? $context['cmd'] ?? $context['script'] ?? null;
        if ($command) {
            foreach (self::DANGEROUS_COMMAND_PATTERNS as $pattern) {
                if (preg_match($pattern, $command)) {
                    $result['allowed'] = false;
                    $result['reason'] = 'Command contains dangerous pattern blocked by security policy';

                    return $result;
                }
            }
        }

        return $result;
    }

    /**
     * Check database/config rules
     */
    private function checkRules(string $operation, array $context, ?string $agentId): array
    {
        $rules = $this->getRules();
        $result = [
            'allowed' => true,
            'requires_confirmation' => false,
            'reason' => null,
            'rule_id' => null,
            'severity' => 'low',
        ];

        foreach ($rules as $rule) {
            // Check if rule applies to this operation
            if (! $this->ruleMatchesOperation($rule, $operation)) {
                continue;
            }

            // Check if rule applies to this agent
            if (! empty($rule['agent_scope']) && $agentId !== $rule['agent_scope']) {
                continue;
            }

            // Check if rule conditions match context
            if (! $this->ruleConditionsMatch($rule, $context)) {
                continue;
            }

            // Apply rule action
            $result['rule_id'] = $rule['id'];
            $result['severity'] = $rule['severity'] ?? 'medium';

            switch ($rule['action']) {
                case 'block':
                    $result['allowed'] = false;
                    $result['reason'] = $rule['reason'] ?? "Operation blocked by rule #{$rule['id']}";

                    return $result;

                case 'confirm':
                    $result['requires_confirmation'] = true;
                    $result['reason'] = $rule['reason'] ?? "Operation requires confirmation per rule #{$rule['id']}";
                    // Don't return - continue checking for blocks
                    break;

                case 'allow':
                    // Explicit allow - skip further rule checking
                    $result['allowed'] = true;
                    $result['requires_confirmation'] = false;

                    return $result;

                case 'log':
                    // Just log, continue checking
                    break;
            }
        }

        return $result;
    }

    /**
     * Check if a rule matches the operation
     */
    private function ruleMatchesOperation(array $rule, string $operation): bool
    {
        $pattern = $rule['operation_pattern'] ?? $rule['operation'] ?? null;

        if (! $pattern) {
            return false;
        }

        // Support wildcard patterns
        if (str_contains($pattern, '*')) {
            $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'$#';

            return (bool) preg_match($regex, $operation);
        }

        return $pattern === $operation;
    }

    /**
     * Check if rule conditions match context
     */
    private function ruleConditionsMatch(array $rule, array $context): bool
    {
        $conditions = $rule['conditions'] ?? [];

        if (empty($conditions)) {
            return true; // No conditions = always matches
        }

        if (is_string($conditions)) {
            $conditions = json_decode($conditions, true) ?? [];
        }

        foreach ($conditions as $key => $expectedValue) {
            $actualValue = $context[$key] ?? null;

            // Support regex patterns in conditions
            if (is_string($expectedValue) && str_starts_with($expectedValue, '/') && str_ends_with($expectedValue, '/')) {
                if (! preg_match($expectedValue, (string) $actualValue)) {
                    return false;
                }
            } elseif ($actualValue !== $expectedValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get rules from database or config
     */
    private function getRules(): array
    {
        if ($this->cachedRules !== null) {
            return $this->cachedRules;
        }

        $cacheKey = self::CACHE_KEY;

        $this->cachedRules = Cache::remember($cacheKey, self::CACHE_TTL, function () {
            $rules = [];

            // Load from database if enabled
            if ($this->useDatabaseRules && $this->tableExists()) {
                $dbRules = DB::select('
                    SELECT id, name, operation_pattern, action, conditions, reason,
                           severity, agent_scope, priority, is_active
                    FROM guardrail_rules
                    WHERE is_active = 1
                    ORDER BY priority DESC, id ASC
                ');

                foreach ($dbRules as $rule) {
                    $rules[] = [
                        'id' => $rule->id,
                        'name' => $rule->name,
                        'operation_pattern' => $rule->operation_pattern,
                        'action' => $rule->action,
                        'conditions' => $rule->conditions ? json_decode($rule->conditions, true) : [],
                        'reason' => $rule->reason,
                        'severity' => $rule->severity,
                        'agent_scope' => $rule->agent_scope,
                        'priority' => $rule->priority,
                    ];
                }
            }

            // Merge with config rules
            $configRules = config('services.guardrails.rules', []);
            foreach ($configRules as $index => $rule) {
                $rules[] = array_merge([
                    'id' => 'config_'.$index,
                    'priority' => $rule['priority'] ?? 0,
                ], $rule);
            }

            // Sort by priority (higher first)
            usort($rules, fn ($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

            return $rules;
        });

        return $this->cachedRules;
    }

    /**
     * Check if guardrail_rules table exists
     */
    private function tableExists(): bool
    {
        try {
            $result = DB::selectOne("SHOW TABLES LIKE 'guardrail_rules'");

            return $result !== null;
        } catch (Exception $e) {
            Log::debug('AgentGuardrailService: guardrail_rules table check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Create a pending confirmation request
     *
     * @param  string  $operation  Operation type
     * @param  array  $context  Operation context
     * @param  string|null  $agentId  Agent identifier
     * @return string Confirmation token
     */
    public function requestConfirmation(string $operation, array $context, ?string $agentId = null): string
    {
        $token = bin2hex(random_bytes(16));

        $confirmationData = [
            'operation' => $operation,
            'context' => $context,
            'agent_id' => $agentId,
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ];

        // Store in cache (5 minute TTL)
        Cache::put("guardrail_confirm:{$token}", $confirmationData, 300);

        // Log the confirmation request
        if ($this->useDatabaseRules && $this->tableExists()) {
            DB::insert("
                INSERT INTO guardrail_confirmations (token, operation, context, agent_id, status, created_at)
                VALUES (?, ?, ?, ?, 'pending', NOW())
            ", [$token, $operation, json_encode($context), $agentId]);
        }

        Log::info('GuardrailService: Confirmation requested', [
            'token' => substr($token, 0, 8).'...',
            'operation' => $operation,
            'agent_id' => $agentId,
        ]);

        // Send Pushover notification to human for confirmation
        $this->sendConfirmationNotification($token, $operation, $context, $agentId);

        return $token;
    }

    /**
     * Confirm a pending operation
     *
     * @param  string  $token  Confirmation token
     * @param  bool  $approved  Whether to approve or deny
     * @param  string|null  $confirmedBy  User/system that confirmed
     * @return array Original operation context if approved, or error
     */
    public function confirm(string $token, bool $approved, ?string $confirmedBy = null): array
    {
        $cacheKey = "guardrail_confirm:{$token}";
        $confirmationData = Cache::get($cacheKey);

        if (! $confirmationData) {
            return [
                'success' => false,
                'error' => 'Confirmation token not found or expired',
            ];
        }

        // Check expiration
        if (now()->isAfter($confirmationData['expires_at'])) {
            Cache::forget($cacheKey);

            return [
                'success' => false,
                'error' => 'Confirmation token expired',
            ];
        }

        // Update database record
        if ($this->useDatabaseRules && $this->tableExists()) {
            DB::update('
                UPDATE guardrail_confirmations
                SET status = ?, confirmed_by = ?, confirmed_at = NOW()
                WHERE token = ?
            ', [$approved ? 'approved' : 'denied', $confirmedBy, $token]);
        }

        // Remove from cache
        Cache::forget($cacheKey);

        $this->logGuardrailEvent(
            $approved ? 'confirmed' : 'denied',
            $confirmationData['operation'],
            $confirmationData['context'],
            $approved ? "Confirmed by {$confirmedBy}" : "Denied by {$confirmedBy}",
            $confirmationData['agent_id']
        );

        return [
            'success' => true,
            'approved' => $approved,
            'operation' => $confirmationData['operation'],
            'context' => $confirmationData['context'],
        ];
    }

    /**
     * Add a new guardrail rule
     *
     * @param  array  $rule  Rule configuration
     * @return int|string Rule ID
     */
    public function addRule(array $rule): int|string
    {
        if (! $this->useDatabaseRules) {
            throw new Exception('Database rules are disabled. Add rules to config instead.');
        }

        DB::insert(
            'INSERT INTO guardrail_rules (name, operation_pattern, action, conditions, reason, severity, agent_scope, priority, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $rule['name'] ?? 'Unnamed Rule',
                $rule['operation_pattern'] ?? $rule['operation'],
                $rule['action'] ?? 'log',
                isset($rule['conditions']) ? json_encode($rule['conditions']) : null,
                $rule['reason'] ?? null,
                $rule['severity'] ?? 'medium',
                $rule['agent_scope'] ?? null,
                $rule['priority'] ?? 0,
                $rule['is_active'] ?? true,
            ]
        );
        $ruleId = (int) DB::getPdo()->lastInsertId();

        // Clear cache
        $this->clearCache();

        Log::info('GuardrailService: Rule added', [
            'rule_id' => $ruleId,
            'name' => $rule['name'] ?? 'Unnamed Rule',
            'operation_pattern' => $rule['operation_pattern'] ?? $rule['operation'],
        ]);

        return $ruleId;
    }

    /**
     * Update an existing rule
     */
    public function updateRule(int $ruleId, array $updates): bool
    {
        $fields = [];
        $params = [];

        $allowedFields = ['name', 'operation_pattern', 'action', 'conditions', 'reason', 'severity', 'agent_scope', 'priority', 'is_active'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $updates)) {
                $value = $updates[$field];
                if ($field === 'conditions' && is_array($value)) {
                    $value = json_encode($value);
                }
                $fields[] = "{$field} = ?";
                $params[] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = ?';
        $params[] = now();
        $params[] = $ruleId;

        $affected = DB::update(
            'UPDATE guardrail_rules SET '.implode(', ', $fields).' WHERE id = ?',
            $params
        );

        $this->clearCache();

        return $affected > 0;
    }

    /**
     * Delete a rule
     */
    public function deleteRule(int $ruleId): bool
    {
        $affected = DB::delete('DELETE FROM guardrail_rules WHERE id = ?', [$ruleId]);
        $this->clearCache();

        return $affected > 0;
    }

    /**
     * Get all rules
     */
    public function getAllRules(): array
    {
        return $this->getRules();
    }

    /**
     * Get guardrail event log
     */
    public function getEventLog(int $limit = 100, ?string $operation = null, ?string $eventType = null): array
    {
        $query = 'SELECT * FROM guardrail_events WHERE 1=1';
        $params = [];

        if ($operation) {
            $query .= ' AND operation = ?';
            $params[] = $operation;
        }

        if ($eventType) {
            $query .= ' AND event_type = ?';
            $params[] = $eventType;
        }

        $query .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = $limit;

        try {
            return array_map(function ($event) {
                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'operation' => $event->operation,
                    'context' => json_decode($event->context, true),
                    'reason' => $event->reason,
                    'agent_id' => $event->agent_id,
                    'created_at' => $event->created_at,
                ];
            }, DB::select($query, $params));
        } catch (Exception $e) {
            Log::warning('AgentGuardrailService: events query failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Get statistics on guardrail events
     */
    public function getStats(): array
    {
        try {
            $stats = [
                'total_validations' => 0,
                'blocked' => 0,
                'confirmed' => 0,
                'allowed' => 0,
                'by_operation' => [],
                'by_severity' => [],
                'rules_count' => count($this->getRules()),
            ];

            // Event counts by type
            $eventCounts = DB::select('
                SELECT event_type, COUNT(*) as count
                FROM guardrail_events
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY event_type
            ');

            foreach ($eventCounts as $row) {
                $stats[$row->event_type] = $row->count;
                $stats['total_validations'] += $row->count;
            }

            // By operation (last 24h)
            $opCounts = DB::select('
                SELECT operation, event_type, COUNT(*) as count
                FROM guardrail_events
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY operation, event_type
            ');

            foreach ($opCounts as $row) {
                if (! isset($stats['by_operation'][$row->operation])) {
                    $stats['by_operation'][$row->operation] = [];
                }
                $stats['by_operation'][$row->operation][$row->event_type] = $row->count;
            }

            return $stats;

        } catch (Exception $e) {
            return [
                'error' => 'Statistics unavailable',
                'rules_count' => count($this->getRules()),
            ];
        }
    }

    /**
     * Log a guardrail event
     */
    private function logGuardrailEvent(
        string $eventType,
        string $operation,
        array $context,
        ?string $reason,
        ?string $agentId
    ): void {
        Log::info("GuardrailService: {$eventType}", [
            'operation' => $operation,
            'agent_id' => $agentId,
            'reason' => $reason,
        ]);

        // Store in database if table exists
        try {
            if ($this->tableExists()) {
                DB::insert('
                    INSERT INTO guardrail_events (event_type, operation, context, reason, agent_id, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ', [$eventType, $operation, json_encode($context), $reason, $agentId]);
            }

            // INF-3: Structured audit trail for security-relevant events
            if (in_array($eventType, ['blocked', 'denied', 'confirmation_required'], true)) {
                app(AgentAuditService::class)->recordGuardrail(
                    sessionId: $context['session_id'] ?? '',
                    agentName: $agentId ?? 'unknown',
                    detail: $operation.': '.($reason ?? $eventType),
                    outcome: $eventType === 'confirmation_required' ? 'skipped' : 'denied',
                    context: is_array($context) ? $context : null,
                );
            }
        } catch (Exception $e) {
            Log::debug('AgentGuardrailService: guardrail event DB insert failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if operation should be logged
     */
    private function shouldLogOperation(string $operation): bool
    {
        $logOperations = config('services.guardrails.log_operations', []);

        if (in_array('*', $logOperations, true)) {
            return true;
        }

        return in_array($operation, $logOperations, true);
    }

    /**
     * Send Pushover notification for confirmation request
     */
    private function sendConfirmationNotification(string $token, string $operation, array $context, ?string $agentId): void
    {
        try {
            $agentLabel = $agentId ?? 'unknown agent';
            $contextSummary = '';

            if (isset($context['path'])) {
                $contextSummary = " on {$context['path']}";
            } elseif (isset($context['target'])) {
                $contextSummary = " targeting {$context['target']}";
            }

            $confirmUrl = url("/api/agent/guardrail/confirm/{$token}/approve");
            $message = "Agent '{$agentLabel}' requests confirmation for: {$operation}{$contextSummary}";
            $message .= "\n\nToken: ".substr($token, 0, 8).'...';
            $message .= "\nExpires in 5 minutes";

            // Confirmation requests logged — agents auto-deny after timeout
            Log::info('GuardrailService: Confirmation requested (Pushover suppressed)', [
                'agent' => $agentId ?? 'unknown',
                'operation' => $operation,
                'token' => substr($token, 0, 8),
            ]);
        } catch (Exception $e) {
            Log::warning('GuardrailService: Failed to send Pushover confirmation', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear cached rules
     */
    public function clearCache(): void
    {
        $this->cachedRules = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Validate a batch of operations
     *
     * @param  array  $operations  Array of ['operation' => string, 'context' => array]
     * @param  string|null  $agentId  Agent identifier
     * @return array Results keyed by operation index
     */
    public function validateBatch(array $operations, ?string $agentId = null): array
    {
        $results = [];

        foreach ($operations as $index => $op) {
            $results[$index] = $this->validate(
                $op['operation'],
                $op['context'] ?? [],
                $agentId
            );
        }

        return $results;
    }

    /**
     * Check if all operations in a batch are allowed
     */
    public function batchAllowed(array $operations, ?string $agentId = null): bool
    {
        foreach ($operations as $op) {
            $result = $this->validate(
                $op['operation'],
                $op['context'] ?? [],
                $agentId
            );

            if (! $result['allowed']) {
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // OWASP Agentic Top 10 Controls (2026-03-22)
    // =========================================================================

    /**
     * Slice D (2026-04-18): the injection-pattern corpus moved to
     * config/injection_patterns.php as the single source of truth
     * shared with TrustBoundaryFormatterService. The previous
     * private const UNTRUSTED_TEXT_PATTERNS is retired; new patterns
     * land in config and are consumed by both services at runtime.
     */
    protected function getInjectionPatterns(): array
    {
        return (array) config('injection_patterns.patterns', []);
    }

    public function sanitizeUntrustedText(string $text, string $replacement = '[REDACTED_UNTRUSTED_INSTRUCTION]'): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        $patterns = array_keys($this->getInjectionPatterns());
        if (empty($patterns)) {
            return $trimmed;
        }

        return trim((string) preg_replace($patterns, $replacement, $trimmed));
    }

    /**
     * OWASP #1: Objective Hijacking — detect prompt injection in retrieved content.
     *
     * Scans RAG-retrieved text for patterns that attempt to override agent instructions:
     * instruction injection, role reassignment, system prompt leaks, tool-call injection.
     *
     * @param  string  $retrievedContent  Content from RAG/external sources
     * @return array [clean: bool, threats: string[], severity: string]
     */
    public function detectContentContamination(string $retrievedContent): array
    {
        $threats = [];

        foreach ($this->getInjectionPatterns() as $pattern => $label) {
            if (preg_match($pattern, $retrievedContent)) {
                $threats[] = $label;
            }
        }

        $severity = match (true) {
            count($threats) >= 3 => 'critical',
            count($threats) >= 1 => 'high',
            default => 'none',
        };

        if (! empty($threats)) {
            Log::warning('AgentGuardrail: Content contamination detected', [
                'threats' => $threats,
                'severity' => $severity,
                'content_preview' => mb_substr($retrievedContent, 0, 200),
            ]);
        }

        return [
            'clean' => empty($threats),
            'threats' => $threats,
            'severity' => $severity,
        ];
    }

    /**
     * OWASP #5: Identity Spoofing — verify agent identity in handoff.
     *
     * Generates and verifies HMAC-signed identity tokens for agent-to-agent
     * communication. Prevents agents from impersonating other agents during handoff.
     *
     * @param  string  $agentId  Agent requesting identity
     * @param  string  $sessionId  Current session ID
     * @return string Signed identity token (HMAC-SHA256)
     */
    public function generateAgentIdentityToken(string $agentId, string $sessionId): string
    {
        $payload = "{$agentId}:{$sessionId}:".now()->timestamp;
        $secret = config('app.key', '');

        return $payload.':'.hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Verify an agent identity token.
     *
     * @param  string  $token  Token to verify
     * @param  string  $expectedAgentId  Expected agent ID
     * @param  int  $maxAgeSec  Maximum token age in seconds (default 5 min)
     * @return array [valid: bool, reason: string|null]
     */
    public function verifyAgentIdentityToken(string $token, string $expectedAgentId, int $maxAgeSec = 300): array
    {
        $parts = explode(':', $token);
        if (count($parts) !== 4) {
            return ['valid' => false, 'reason' => 'Malformed token'];
        }

        [$agentId, $sessionId, $timestamp, $hmac] = $parts;

        if ($agentId !== $expectedAgentId) {
            Log::warning('AgentGuardrail: Identity spoofing attempt', [
                'claimed' => $agentId,
                'expected' => $expectedAgentId,
            ]);

            return ['valid' => false, 'reason' => 'Agent ID mismatch'];
        }

        $payload = "{$agentId}:{$sessionId}:{$timestamp}";
        $secret = config('app.key', '');
        $expectedHmac = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expectedHmac, $hmac)) {
            return ['valid' => false, 'reason' => 'Invalid signature'];
        }

        if ((now()->timestamp - (int) $timestamp) > $maxAgeSec) {
            return ['valid' => false, 'reason' => 'Token expired'];
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * OWASP #8: Insecure Output Handling — validate LLM output against expected schema.
     *
     * Checks tool-call output for structural validity, PII leakage, and anomalies
     * before passing to downstream consumers.
     *
     * @param  mixed  $output  LLM output to validate
     * @param  string  $expectedType  Expected output type: 'json', 'text', 'tool_call'
     * @param  array  $schema  Optional JSON schema keys to validate
     * @return array [valid: bool, issues: string[], sanitized: mixed]
     */
    public function validateOutput($output, string $expectedType = 'text', array $schema = []): array
    {
        $issues = [];

        // Type validation
        if ($expectedType === 'json') {
            if (is_string($output)) {
                $decoded = json_decode($output, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $issues[] = 'Invalid JSON: '.json_last_error_msg();
                } else {
                    $output = $decoded;
                }
            }

            // Schema key validation
            if (is_array($output) && ! empty($schema)) {
                foreach ($schema as $key) {
                    if (! array_key_exists($key, $output)) {
                        $issues[] = "Missing required key: {$key}";
                    }
                }
            }
        }

        $textToCheck = is_string($output) ? $output : json_encode($output);

        // PII detection in output (should not leak to non-sensitive-safe providers)
        $piiPatterns = [
            '/\b\d{3}-\d{2}-\d{4}\b/' => 'SSN pattern detected',
            '/\b\d{16}\b/' => 'Credit card number pattern',
            '/\bpassword\s*[:=]\s*\S+/i' => 'Password in output',
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z]{2,}\b/i' => 'Email address in output',
        ];

        foreach ($piiPatterns as $pattern => $label) {
            if (preg_match($pattern, $textToCheck)) {
                $issues[] = $label;
            }
        }

        // Sanitize: strip any embedded system prompts or instructions
        if (is_string($output)) {
            $sanitized = preg_replace('/\[INST\].*?\[\/INST\]/s', '[REDACTED]', $output);
            $sanitized = preg_replace('/<\|im_start\|>.*?<\|im_end\|>/s', '[REDACTED]', $sanitized);
            $sanitized = $this->sanitizeUntrustedText($sanitized, '[REDACTED]');
        } else {
            $sanitized = $output;
        }

        if (! empty($issues)) {
            Log::info('AgentGuardrail: Output validation issues', [
                'type' => $expectedType,
                'issues' => $issues,
            ]);
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'sanitized' => $sanitized,
        ];
    }

    /**
     * OWASP compliance summary — return coverage status for all 10 controls.
     */
    public function getOwaspComplianceSummary(): array
    {
        return [
            ['control' => 'Objective Hijacking', 'status' => 'covered', 'method' => 'detectContentContamination()'],
            ['control' => 'Tool Misuse', 'status' => 'covered', 'method' => 'validate() + DANGEROUS_COMMAND_PATTERNS'],
            ['control' => 'Privilege Escalation', 'status' => 'covered', 'method' => 'Permission filtering per agent SKILL.md'],
            ['control' => 'Data Leakage', 'status' => 'covered', 'method' => 'sensitive_safe routing in AIService'],
            ['control' => 'Identity Spoofing', 'status' => 'covered', 'method' => 'generateAgentIdentityToken() + HMAC'],
            ['control' => 'Excessive Agency', 'status' => 'covered', 'method' => 'Max iterations + tool phase gating'],
            ['control' => 'Uncontrolled Autonomy', 'status' => 'covered', 'method' => 'Human review gate for genealogy_finding'],
            ['control' => 'Insecure Output', 'status' => 'covered', 'method' => 'validateOutput() + PII detection'],
            ['control' => 'Improper Error Handling', 'status' => 'partial', 'method' => 'Errors logged, redaction in validateOutput()'],
            ['control' => 'Supply Chain', 'status' => 'partial', 'method' => 'Circuit breaker per provider, MCP tools unverified'],
        ];
    }
}
