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
            'folder_scan_job_runtime_policy' => [
                'description' => 'Runtime policy for queued genealogy media folder scans.',
                'policy' => [
                    'timeout_seconds' => 7200,
                    'tries' => 1,
                    'policy_notes' => [
                        'timeout_seconds' => 'Horizon timeout for one queued genealogy media folder scan.',
                        'tries' => 'Retry attempts for one queued genealogy media folder scan.',
                        'queue_owner' => 'Queue placement remains explicit in the job constructor as long-running.',
                        'scan_arguments' => 'Tree, folder, recursive mode, and match filtering stay owned by the dispatch caller.',
                    ],
                ],
            ],
            'media_path_sync_job_runtime_policy' => [
                'description' => 'Runtime policy for queued genealogy GEDCOM media path sync.',
                'policy' => [
                    'timeout_seconds' => 3600,
                    'tries' => 1,
                    'policy_notes' => [
                        'timeout_seconds' => 'Horizon timeout for one queued GEDCOM media path sync.',
                        'tries' => 'Retry attempts for one queued GEDCOM media path sync.',
                        'queue_owner' => 'Queue placement remains explicit in the job constructor as long-running.',
                        'sync_arguments' => 'Tree and GEDCOM path stay owned by the dispatch caller.',
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
            ->where('section', 'genealogy')
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
            ['section' => 'genealogy', 'config_key' => $configKey],
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
            ->where('section', 'genealogy')
            ->whereIn('config_key', array_keys($this->policies()))
            ->delete();
    }
};
