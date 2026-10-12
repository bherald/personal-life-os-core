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
            'enabled' => true,
            'stability_delay_seconds' => 5.0,
            'policy_notes' => [
                'enabled' => 'Apply the intake stability gate to file registry scan candidates.',
                'stability_delay_seconds' => 'Seconds a candidate must retain the same size and modification time before registration.',
                'safety_ceiling' => 'Application safety caps the configured delay at 300 seconds.',
                'provenance' => 'Native PLOS adaptation of the Paperless-ngx consumer stability invariant; reviewed revision 536007f42b3649b3db5ae7ed58e5e670dcb13166.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'files')
            ->where('config_key', 'file_intake_stability_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);
        $policy['policy_notes'] = array_replace(
            $defaultPolicy['policy_notes'],
            is_array($existing['policy_notes'] ?? null) ? $existing['policy_notes'] : []
        );

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'files', 'config_key' => 'file_intake_stability_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Stability gate for file registry scan intake.',
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
            ->where('section', 'files')
            ->where('config_key', 'file_intake_stability_policy')
            ->delete();
    }
};
