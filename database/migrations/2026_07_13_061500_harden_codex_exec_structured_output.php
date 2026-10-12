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
            ->first(['config', 'notes']);
        if ($row === null) {
            return;
        }

        $config = json_decode((string) ($row->config ?? '{}'), true);
        $config = is_array($config) ? $config : [];
        $structuredOutput = is_array($config['structured_output'] ?? null)
            ? $config['structured_output']
            : [];

        $config['strict_config'] = true;
        $config['structured_output'] = array_replace($structuredOutput, [
            'enabled' => true,
            'local_validation' => true,
            'fail_closed' => true,
            'max_schema_bytes' => 262144,
            'policy_source' => 'llm_instances.config.structured_output',
            'upstream_constraint' => 'Validate output_last_message locally because Codex tool/MCP trajectories can weaken schema conformance.',
        ]);

        $notes = trim((string) ($row->notes ?? ''));
        $marker = 'Strict config loading and fail-closed local JSON Schema validation are required for structured Codex output.';
        if (! str_contains($notes, $marker)) {
            $notes = trim($notes.' '.$marker);
        }

        DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->update([
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => $notes,
                'updated_at' => now(),
            ]);

        $this->forgetProviderCaches();
    }

    public function down(): void
    {
        $row = DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->first(['config', 'notes']);
        if ($row === null) {
            return;
        }

        $config = json_decode((string) ($row->config ?? '{}'), true);
        $config = is_array($config) ? $config : [];
        unset($config['strict_config']);

        $structuredOutput = is_array($config['structured_output'] ?? null)
            ? $config['structured_output']
            : [];
        unset(
            $structuredOutput['local_validation'],
            $structuredOutput['fail_closed'],
            $structuredOutput['max_schema_bytes'],
            $structuredOutput['policy_source'],
            $structuredOutput['upstream_constraint'],
        );
        $config['structured_output'] = $structuredOutput;

        $marker = 'Strict config loading and fail-closed local JSON Schema validation are required for structured Codex output.';
        $notes = trim(str_replace($marker, '', (string) ($row->notes ?? '')));

        DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->update([
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => $notes,
                'updated_at' => now(),
            ]);

        $this->forgetProviderCaches();
    }

    private function forgetProviderCaches(): void
    {
        Cache::forget('llm_instances_all');
        Cache::forget('llm_instances_healthy');
        Cache::forget('external_api_providers');
    }
};
