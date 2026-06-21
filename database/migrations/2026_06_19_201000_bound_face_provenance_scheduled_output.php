<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_COMMAND = 'ops:face-person-source-provenance-report --tree=4 --json --compact --limit=25';

    private const SCHEDULED_COMMAND = 'ops:face-person-source-provenance-report --tree=4 --json --compact --limit=10';

    public function up(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', 'face_person_source_provenance_audit')
                ->update([
                    'command' => self::SCHEDULED_COMMAND,
                    'updated_at' => now(),
                ]);
        }

        $this->replaceCapacityManagerPrefix(self::SCHEDULED_COMMAND);
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', 'face_person_source_provenance_audit')
                ->update([
                    'command' => self::OLD_COMMAND,
                    'updated_at' => now(),
                ]);
        }

        $this->replaceCapacityManagerPrefix(self::OLD_COMMAND);
    }

    private function replaceCapacityManagerPrefix(string $command): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        $prefixes = is_array($policy['allowed_command_prefixes'] ?? null)
            ? $policy['allowed_command_prefixes']
            : [];
        $prefixes['face_person_source_provenance_audit'] = [$command];
        $policy['allowed_command_prefixes'] = $prefixes;
        $policy['feedback'] = array_replace(
            is_array($policy['feedback'] ?? null) ? $policy['feedback'] : [],
            [
                'face_provenance_audit_scheduled_output' => 'Scheduled face provenance audit uses a bounded compact limit so retained scheduler JSON stays valid; run the command manually with a higher limit for deeper review.',
            ]
        );

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
