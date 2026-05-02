<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $toolNames = [
        'workflow_dlq_stats',
        'workflow_dlq_pending',
        'workflow_retry_dlq',
        'workflow_resolve_dlq',
    ];

    public function up(): void
    {
        $placeholders = implode(',', array_fill(0, count($this->toolNames), '?'));
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ($placeholders)", $this->toolNames);
    }

    public function down(): void
    {
        $tools = [
            [
                'name' => 'workflow_dlq_stats',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getDlqStats',
                'description' => 'Get dead letter queue statistics — pending item count, counts by status (pending/resolved/retried/dismissed) and job type, last 24h activity, and oldest pending item age.',
                'parameters' => '[]',
                'returns_description' => 'Array with DLQ counts by status/type, 24h metrics, and oldest pending item details',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_dlq_pending',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'getDlqPending',
                'description' => 'Get pending dead letter queue items requiring review. Shows job type, error context, retry count, and age for each item.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 20, 'description' => 'Maximum number of pending items to return'],
                ]),
                'returns_description' => 'Array of pending DLQ items with ID, job type, error message, context, retry count, and created timestamp',
                'permissions' => '["workflow:read"]',
                'risk_level' => 'read',
                'category' => 'workflow',
            ],
            [
                'name' => 'workflow_retry_dlq',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'retryDlqItem',
                'description' => 'Retry a specific dead letter queue item. Use when the underlying issue is likely resolved (e.g., transient error, service recovered). LIMIT TO 3 PER RUN.',
                'parameters' => json_encode([
                    'dlq_id' => ['type' => 'integer', 'required' => true, 'description' => 'Dead letter queue item ID to retry'],
                ]),
                'returns_description' => 'Array with dlq_id, retried status, and result message',
                'permissions' => '["workflow:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'workflow',
                'max_calls_per_run' => 3,
            ],
            [
                'name' => 'workflow_resolve_dlq',
                'service_class' => 'App\\Services\\WorkflowOpsService',
                'method' => 'resolveDlqItem',
                'description' => 'Resolve a DLQ item as handled. SUBMIT FOR REVIEW FIRST unless item is clearly stale (>7 days same error). Marks item as resolved with resolution notes.',
                'parameters' => json_encode([
                    'dlq_id' => ['type' => 'integer', 'required' => true, 'description' => 'Dead letter queue item ID to resolve'],
                    'resolution' => ['type' => 'string', 'required' => false, 'default' => 'Resolved by workflow-ops agent', 'description' => 'Resolution description'],
                ]),
                'returns_description' => 'Array with dlq_id, resolved status, and resolution text',
                'permissions' => '["workflow:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'workflow',
                'requires_confirmation' => 1,
            ],
        ];

        foreach ($tools as $tool) {
            try {
                $columns = 'name, service_class, method, description, parameters, returns_description, permissions, risk_level, category, enabled, source';
                $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, 1, \'config\'';
                $values = [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['returns_description'],
                    $tool['permissions'],
                    $tool['risk_level'],
                    $tool['category'],
                ];

                if (isset($tool['requires_confirmation'])) {
                    $columns .= ', requires_confirmation';
                    $placeholders .= ', ?';
                    $values[] = $tool['requires_confirmation'];
                }

                if (isset($tool['max_calls_per_run'])) {
                    $columns .= ', max_calls_per_run';
                    $placeholders .= ', ?';
                    $values[] = $tool['max_calls_per_run'];
                }

                DB::insert("
                    INSERT INTO agent_tool_registry ({$columns})
                    VALUES ({$placeholders})
                ", $values);
            } catch (\Exception $e) {
                // Keep rollback idempotent.
            }
        }
    }
};
