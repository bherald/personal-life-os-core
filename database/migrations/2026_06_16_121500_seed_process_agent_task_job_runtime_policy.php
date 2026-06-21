<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'timeout_seconds' => 600,
            'tries' => 2,
            'backoff_seconds' => [30, 60],
            'policy_notes' => [
                'timeout_seconds' => 'Horizon timeout for one distributed agent task bridge job.',
                'tries' => 'Retry attempts for one distributed agent task bridge job.',
                'backoff_seconds' => 'Retry backoff sequence for transient queue failures.',
                'queue_owner' => 'Queue placement remains explicit in the job constructor as long-running.',
                'task_payload_owner' => 'Task payload, selected skill, model, tree_id, notify flag, and depth remain owned by distributed_tasks payload and AgentLoopService.',
                'distributed_task_owner' => 'Distributed task assignment, max_retries, timeout_at, and agent load balancing remain owned by DistributedAgentService and distributed_tasks rows.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'agents')
            ->where('config_key', 'process_agent_task_job_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);
        if (isset($existing['policy_notes']) && is_array($existing['policy_notes'])) {
            $policy['policy_notes'] = array_replace(
                $defaultPolicy['policy_notes'],
                $existing['policy_notes']
            );
        } else {
            $policy['policy_notes'] = $defaultPolicy['policy_notes'];
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'agents', 'config_key' => 'process_agent_task_job_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for the queued distributed agent task bridge job.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'agents')
            ->where('config_key', 'process_agent_task_job_runtime_policy')
            ->delete();
    }
};
