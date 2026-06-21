<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setCandidateCountGuard(true);
    }

    public function down(): void
    {
        $this->setCandidateCountGuard(false);
    }

    private function setCandidateCountGuard(bool $enabled): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'faces')
            ->where('config_key', 'face_person_source_repair_plan')
            ->first();

        if ($row === null) {
            return;
        }

        $config = json_decode((string) ($row->config_value ?? ''), true);
        if (! is_array($config) || ! is_array($config['refill_policy'] ?? null)) {
            return;
        }

        $config['refill_policy']['candidate_count_guard_enabled'] = $enabled;
        $config['feedback'] = is_array($config['feedback'] ?? null) ? $config['feedback'] : [];

        if ($enabled) {
            $config['feedback']['candidate_count_guard'] =
                'Refill uses read-only provenance aggregate counts to avoid planning empty packet offsets for underfilled statuses.';
        } else {
            unset($config['feedback']['candidate_count_guard']);
        }

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
