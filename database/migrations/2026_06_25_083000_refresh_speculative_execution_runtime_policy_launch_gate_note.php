<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_LAUNCH_GATES_NOTE = 'Variance/uplift thresholds and queue-depth gates remain owned by config/agents.php until a separate service-policy slice moves them.';

    private const LAUNCH_GATES_NOTE = 'Queue-depth, benchmark-variance, and low-uplift gates are owned by this system_configs policy.';

    public function up(): void
    {
        $this->replaceLaunchGateNote(self::LEGACY_LAUNCH_GATES_NOTE, self::LAUNCH_GATES_NOTE);
    }

    public function down(): void
    {
        $this->replaceLaunchGateNote(self::LAUNCH_GATES_NOTE, self::LEGACY_LAUNCH_GATES_NOTE);
    }

    private function replaceLaunchGateNote(string $from, string $to): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'agents')
            ->where('config_key', 'speculative_execution_runtime_policy')
            ->first(['config_value']);

        if ($row === null) {
            return;
        }

        $policy = json_decode((string) $row->config_value, true);
        if (! is_array($policy)) {
            return;
        }

        $policyNotes = $policy['policy_notes'] ?? null;
        if (! is_array($policyNotes) || ($policyNotes['launch_gates'] ?? null) !== $from) {
            return;
        }

        $policy['policy_notes']['launch_gates'] = $to;

        DB::table('system_configs')
            ->where('section', 'agents')
            ->where('config_key', 'speculative_execution_runtime_policy')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
