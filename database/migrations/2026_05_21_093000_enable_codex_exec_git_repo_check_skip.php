<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->first(['config']);

        if ($row === null) {
            return;
        }

        $config = $this->decodeConfig($row->config ?? null);
        $config['skip_git_repo_check'] = true;

        DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->update([
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        $this->forgetLlmCaches();
    }

    public function down(): void
    {
        $row = DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->first(['config']);

        if ($row === null) {
            return;
        }

        $config = $this->decodeConfig($row->config ?? null);
        unset($config['skip_git_repo_check']);

        DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->update([
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        $this->forgetLlmCaches();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function forgetLlmCaches(): void
    {
        Cache::forget('llm_instances_all');
        Cache::forget('llm_instances_healthy');
        Cache::forget('external_api_providers');
    }
};
