<?php

namespace App\Services;

use App\Jobs\ExecuteWorkflow;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WebhookTriggerService
 *
 * Manages webhook triggers for workflows with HMAC signature validation,
 * IP filtering, rate limiting, and comprehensive logging.
 */
class WebhookTriggerService
{
    /**
     * Create a new webhook trigger for a workflow
     *
     * @param int $workflowId The workflow ID to trigger
     * @param string $name Human-readable name for the trigger
     * @param array|null $options Optional configuration (allowed_ips, input_schema, rate_limit)
     * @return array Contains token and secret for the webhook
     */
    public function createTrigger(int $workflowId, string $name, ?array $options = null): array
    {
        // Verify workflow exists
        $workflow = DB::selectOne('SELECT id, name FROM workflows WHERE id = ?', [$workflowId]);
        if (!$workflow) {
            throw new Exception("Workflow not found: {$workflowId}");
        }

        $token = $this->generateSecureToken();
        $secret = $this->generateSecureToken();

        $allowedIps = $options['allowed_ips'] ?? null;
        $inputSchema = $options['input_schema'] ?? null;
        $rateLimit = $options['rate_limit'] ?? 60;
        $description = $options['description'] ?? null;

        DB::insert("
            INSERT INTO webhook_triggers
            (workflow_id, token, name, description, secret_key, allowed_ips, input_schema, rate_limit, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ", [
            $workflowId,
            $token,
            $name,
            $description,
            $secret,
            $allowedIps ? json_encode($allowedIps) : null,
            $inputSchema ? json_encode($inputSchema) : null,
            $rateLimit,
        ]);

        $triggerId = (int) DB::getPdo()->lastInsertId();

        return [
            'id' => $triggerId,
            'token' => $token,
            'secret' => $secret,
            'workflow_id' => $workflowId,
            'workflow_name' => $workflow->name,
            'webhook_url' => url('/api/webhooks/' . $token),
        ];
    }

    /**
     * Validate an incoming webhook request
     *
     * @param string $token The webhook token from URL
     * @param Request $request The incoming HTTP request
     * @return bool True if request is valid
     */
    public function validateRequest(string $token, Request $request): bool
    {
        $trigger = $this->getTriggerByToken($token);

        if (!$trigger) {
            return false;
        }

        if (!$trigger->is_active) {
            return false;
        }

        // Check IP whitelist if configured
        if ($trigger->allowed_ips) {
            $allowedIps = json_decode($trigger->allowed_ips, true);
            if (!empty($allowedIps) && !in_array($request->ip(), $allowedIps)) {
                return false;
            }
        }

        // Verify HMAC signature
        if (!$this->verifySignature($request, $trigger->secret_key)) {
            return false;
        }

        // Check rate limit
        if (!$this->checkRateLimit($trigger->id, $trigger->rate_limit)) {
            return false;
        }

        return true;
    }

    /**
     * Trigger a workflow via webhook
     *
     * @param string $token The webhook token
     * @param array $payload The request payload to pass to workflow
     * @return array Execution result with run_id or error
     */
    public function triggerWorkflow(string $token, array $payload): array
    {
        $startTime = microtime(true);
        $trigger = $this->getTriggerByToken($token);

        if (!$trigger) {
            return [
                'success' => false,
                'error' => 'Invalid webhook token',
            ];
        }

        try {
            // Get workflow
            $workflow = DB::selectOne('SELECT * FROM workflows WHERE id = ?', [$trigger->workflow_id]);

            if (!$workflow) {
                return [
                    'success' => false,
                    'error' => 'Workflow not found',
                ];
            }

            if (!$workflow->active) {
                return [
                    'success' => false,
                    'error' => 'Workflow is not active',
                ];
            }

            // Validate payload against input schema if defined
            if ($trigger->input_schema) {
                $schema = json_decode($trigger->input_schema, true);
                $validationError = $this->validatePayloadSchema($payload, $schema);
                if ($validationError) {
                    return [
                        'success' => false,
                        'error' => $validationError,
                    ];
                }
            }

            ExecuteWorkflow::dispatch($workflow->name, $workflow->id, $payload);

            // Update trigger stats
            DB::update("
                UPDATE webhook_triggers
                SET last_triggered_at = NOW(), trigger_count = trigger_count + 1, updated_at = NOW()
                WHERE id = ?
            ", [$trigger->id]);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            return [
                'success' => true,
                'run_id' => null,
                'execution_id' => null,
                'status' => 'queued',
                'workflow_id' => $workflow->id,
                'workflow_name' => $workflow->name,
                'response_time_ms' => $responseTimeMs,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate HMAC-SHA256 signature for a payload
     *
     * @param string $payload The raw request body
     * @param string $secret The secret key
     * @return string The signature
     */
    public function generateSignature(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Verify HMAC signature from request header
     *
     * @param Request $request The incoming request
     * @param string $secret The secret key
     * @return bool True if signature is valid
     */
    public function verifySignature(Request $request, string $secret): bool
    {
        $providedSignature = $request->header('X-Webhook-Signature');

        if (!$providedSignature) {
            return false;
        }

        $rawBody = $request->getContent();
        $expectedSignature = $this->generateSignature($rawBody, $secret);

        return hash_equals($expectedSignature, $providedSignature);
    }

    /**
     * Log a webhook trigger attempt
     *
     * @param int $triggerId The trigger ID
     * @param Request $request The incoming request
     * @param int|null $runId The workflow run ID (if successful)
     * @param string $status 'success', 'rejected', or 'error'
     * @param string|null $error Error message if applicable
     * @param int|null $responseTimeMs Response time in milliseconds
     */
    public function logTrigger(
        int $triggerId,
        Request $request,
        ?int $runId,
        string $status,
        ?string $error = null,
        ?int $responseTimeMs = null
    ): void {
        // Sanitize headers - remove sensitive data
        $headers = $request->headers->all();
        unset($headers['x-webhook-signature']); // Don't log the signature
        unset($headers['authorization']);

        DB::insert("
            INSERT INTO webhook_trigger_logs
            (trigger_id, request_ip, request_headers, request_body, workflow_run_id, status, error_message, response_time_ms, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ", [
            $triggerId,
            $request->ip(),
            json_encode($headers),
            json_encode($request->all()),
            $runId,
            $status,
            $error,
            $responseTimeMs,
        ]);
    }

    /**
     * Get statistics for a webhook trigger
     *
     * @param int $triggerId The trigger ID
     * @return array Statistics including counts and recent activity
     */
    public function getTriggerStats(int $triggerId): array
    {
        $trigger = DB::selectOne('SELECT * FROM webhook_triggers WHERE id = ?', [$triggerId]);

        if (!$trigger) {
            throw new Exception("Trigger not found: {$triggerId}");
        }

        // Get status counts
        $statusCounts = DB::select("
            SELECT status, COUNT(*) as count
            FROM webhook_trigger_logs
            WHERE trigger_id = ?
            GROUP BY status
        ", [$triggerId]);

        $counts = [
            'success' => 0,
            'rejected' => 0,
            'error' => 0,
        ];
        foreach ($statusCounts as $row) {
            $counts[$row->status] = (int) $row->count;
        }

        // Get 24h counts
        $last24h = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors,
                AVG(response_time_ms) as avg_response_time
            FROM webhook_trigger_logs
            WHERE trigger_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ", [$triggerId]);

        // Get recent logs
        $recentLogs = DB::select("
            SELECT id, request_ip, status, error_message, response_time_ms, created_at
            FROM webhook_trigger_logs
            WHERE trigger_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ", [$triggerId]);

        return [
            'trigger_id' => $triggerId,
            'name' => $trigger->name,
            'is_active' => (bool) $trigger->is_active,
            'total_triggers' => $trigger->trigger_count,
            'last_triggered_at' => $trigger->last_triggered_at,
            'all_time' => $counts,
            'last_24h' => [
                'total' => (int) ($last24h->total ?? 0),
                'success' => (int) ($last24h->success ?? 0),
                'rejected' => (int) ($last24h->rejected ?? 0),
                'errors' => (int) ($last24h->errors ?? 0),
                'avg_response_time_ms' => $last24h->avg_response_time ? round($last24h->avg_response_time) : null,
            ],
            'recent_logs' => array_map(function ($log) {
                return [
                    'id' => $log->id,
                    'ip' => $log->request_ip,
                    'status' => $log->status,
                    'error' => $log->error_message,
                    'response_time_ms' => $log->response_time_ms,
                    'created_at' => $log->created_at,
                ];
            }, $recentLogs),
        ];
    }

    /**
     * Regenerate token for an existing trigger
     *
     * @param int $triggerId The trigger ID
     * @return string The new token
     */
    public function regenerateToken(int $triggerId): string
    {
        $trigger = DB::selectOne('SELECT id FROM webhook_triggers WHERE id = ?', [$triggerId]);

        if (!$trigger) {
            throw new Exception("Trigger not found: {$triggerId}");
        }

        $newToken = $this->generateSecureToken();

        DB::update("
            UPDATE webhook_triggers
            SET token = ?, updated_at = NOW()
            WHERE id = ?
        ", [$newToken, $triggerId]);

        return $newToken;
    }

    /**
     * Regenerate secret key for an existing trigger
     *
     * @param int $triggerId The trigger ID
     * @return string The new secret key
     */
    public function regenerateSecret(int $triggerId): string
    {
        $trigger = DB::selectOne('SELECT id FROM webhook_triggers WHERE id = ?', [$triggerId]);

        if (!$trigger) {
            throw new Exception("Trigger not found: {$triggerId}");
        }

        $newSecret = $this->generateSecureToken();

        DB::update("
            UPDATE webhook_triggers
            SET secret_key = ?, updated_at = NOW()
            WHERE id = ?
        ", [$newSecret, $triggerId]);

        return $newSecret;
    }

    /**
     * Deactivate a webhook trigger
     *
     * @param int $triggerId The trigger ID
     * @return bool True if successful
     */
    public function deactivateTrigger(int $triggerId): bool
    {
        $affected = DB::update("
            UPDATE webhook_triggers
            SET is_active = FALSE, updated_at = NOW()
            WHERE id = ?
        ", [$triggerId]);

        return $affected > 0;
    }

    /**
     * Activate a webhook trigger
     *
     * @param int $triggerId The trigger ID
     * @return bool True if successful
     */
    public function activateTrigger(int $triggerId): bool
    {
        $affected = DB::update("
            UPDATE webhook_triggers
            SET is_active = TRUE, updated_at = NOW()
            WHERE id = ?
        ", [$triggerId]);

        return $affected > 0;
    }

    /**
     * Get trigger by ID
     *
     * @param int $triggerId The trigger ID
     * @return object|null The trigger or null
     */
    public function getTrigger(int $triggerId): ?object
    {
        return DB::selectOne('SELECT * FROM webhook_triggers WHERE id = ?', [$triggerId]);
    }

    /**
     * Get all triggers for a workflow
     *
     * @param int $workflowId The workflow ID
     * @return array List of triggers
     */
    public function getWorkflowTriggers(int $workflowId): array
    {
        return DB::select("
            SELECT * FROM webhook_triggers
            WHERE workflow_id = ?
            ORDER BY created_at DESC
        ", [$workflowId]);
    }

    /**
     * Delete a webhook trigger
     *
     * @param int $triggerId The trigger ID
     * @return bool True if deleted
     */
    public function deleteTrigger(int $triggerId): bool
    {
        $affected = DB::delete('DELETE FROM webhook_triggers WHERE id = ?', [$triggerId]);
        return $affected > 0;
    }

    /**
     * Update trigger configuration
     *
     * @param int $triggerId The trigger ID
     * @param array $data Fields to update
     * @return bool True if updated
     */
    public function updateTrigger(int $triggerId, array $data): bool
    {
        $allowedFields = ['name', 'description', 'allowed_ips', 'input_schema', 'rate_limit', 'is_active'];
        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if (in_array($field, ['allowed_ips', 'input_schema']) && is_array($value)) {
                    $value = json_encode($value);
                }
                $updates[] = "{$field} = ?";
                $params[] = $value;
            }
        }

        if (empty($updates)) {
            return false;
        }

        $updates[] = 'updated_at = NOW()';
        $params[] = $triggerId;

        $sql = 'UPDATE webhook_triggers SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $affected = DB::update($sql, $params);

        return $affected > 0;
    }

    /**
     * Get trigger by token
     *
     * @param string $token The webhook token
     * @return object|null The trigger or null
     */
    private function getTriggerByToken(string $token): ?object
    {
        return DB::selectOne('SELECT * FROM webhook_triggers WHERE token = ?', [$token]);
    }

    /**
     * Generate a secure random token
     *
     * @return string 64-character hex string
     */
    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Check rate limit for a trigger
     *
     * @param int $triggerId The trigger ID
     * @param int $maxPerMinute Maximum requests per minute
     * @return bool True if within limit
     */
    private function checkRateLimit(int $triggerId, int $maxPerMinute): bool
    {
        $cacheKey = "webhook_rate_limit:{$triggerId}";
        $currentCount = Cache::get($cacheKey, 0);

        if ($currentCount >= $maxPerMinute) {
            return false;
        }

        Cache::put($cacheKey, $currentCount + 1, 60); // 60 seconds TTL

        return true;
    }

    /**
     * Validate payload against JSON schema (simple validation)
     *
     * @param array $payload The request payload
     * @param array $schema The expected schema
     * @return string|null Error message or null if valid
     */
    private function validatePayloadSchema(array $payload, array $schema): ?string
    {
        // Check required fields
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (!array_key_exists($field, $payload)) {
                    return "Missing required field: {$field}";
                }
            }
        }

        // Check field types
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $field => $fieldSchema) {
                if (!array_key_exists($field, $payload)) {
                    continue;
                }

                $value = $payload[$field];
                $expectedType = $fieldSchema['type'] ?? null;

                if ($expectedType) {
                    $actualType = gettype($value);
                    $typeMap = [
                        'string' => 'string',
                        'integer' => 'integer',
                        'number' => ['integer', 'double'],
                        'boolean' => 'boolean',
                        'array' => 'array',
                        'object' => 'array',
                    ];

                    $expectedTypes = (array) ($typeMap[$expectedType] ?? $expectedType);
                    if (!in_array($actualType, $expectedTypes)) {
                        return "Field '{$field}' must be of type {$expectedType}";
                    }
                }
            }
        }

        return null;
    }
}
