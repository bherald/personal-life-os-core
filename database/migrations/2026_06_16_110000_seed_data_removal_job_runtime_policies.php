<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,array{description:string,policy:array<string,mixed>}> */
    private function policies(): array
    {
        return [
            'data_removal_scan_job_runtime_policy' => [
                'description' => 'Runtime policy for queued data removal scans.',
                'policy' => [
                    'timeout_seconds' => 3600,
                    'tries' => 1,
                    'policy_notes' => [
                        'timeout_seconds' => 'Horizon timeout for one queued data removal scan.',
                        'tries' => 'Retry attempts for one queued data removal scan.',
                        'queue_owner' => 'Queue placement remains explicit in the job constructor as long-running.',
                        'argument_owner' => 'Scan subject and limit arguments remain owned by the dispatch caller and API validation.',
                    ],
                ],
            ],
            'broker_discovery_job_runtime_policy' => [
                'description' => 'Runtime policy for queued broker discovery.',
                'policy' => [
                    'timeout_seconds' => 1800,
                    'tries' => 1,
                    'policy_notes' => [
                        'timeout_seconds' => 'Horizon timeout for one queued broker discovery run.',
                        'tries' => 'Retry attempts for one queued broker discovery run.',
                        'queue_owner' => 'Queue placement remains explicit in the job constructor as long-running.',
                        'request_owner' => 'Discovery max_results, category, add_to_db, and dry_run options remain owned by API validation and dispatch callers.',
                        'd2_scope' => 'This policy does not reintroduce the dropped broker_discovery_queue table.',
                    ],
                ],
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        foreach ($this->policies() as $configKey => $definition) {
            $this->seedPolicy($configKey, $definition['description'], $definition['policy']);
        }
    }

    /**
     * @param array<string,mixed> $defaultPolicy
     */
    private function seedPolicy(string $configKey, string $description, array $defaultPolicy): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'data_removal')
            ->where('config_key', $configKey)
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

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
            ['section' => 'data_removal', 'config_key' => $configKey],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => $description,
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
            ->where('section', 'data_removal')
            ->whereIn('config_key', array_keys($this->policies()))
            ->delete();
    }
};
