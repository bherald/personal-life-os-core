<?php

use App\Support\PromptIntegrityPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const POLICY_SECTION = 'ai';

    private const POLICY_KEY = 'prompt_integrity_policy';

    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')->updateOrInsert(
                [
                    'section' => self::POLICY_SECTION,
                    'config_key' => self::POLICY_KEY,
                ],
                [
                    'config_value' => json_encode(PromptIntegrityPolicy::inventory(), JSON_UNESCAPED_SLASHES),
                    'data_type' => 'json',
                    'description' => 'Operator-enforced lossless AI prompt policy; lossy transforms cannot be re-enabled by environment or runtime table overrides.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        if (Schema::hasTable('recursion_config')) {
            $row = DB::table('recursion_config')
                ->where('service_name', 'auto_decompose')
                ->first(['notes']);

            if ($row) {
                DB::table('recursion_config')
                    ->where('service_name', 'auto_decompose')
                    ->update([
                        'enabled' => 0,
                        'disabled_reason' => PromptIntegrityPolicy::DISABLED_REASON,
                        'disabled_at' => now(),
                        'notes' => $this->appendNote(
                            $row->notes ?? null,
                            '2026-07-10 operator policy: automatic prompt decomposition/synthesis is disabled because it can omit material detail.'
                        ),
                        'updated_at' => now(),
                    ]);
            }
        }

        $this->removePromptCompressorRuntimePolicy();
        $this->disablePromptCompressorRegistryTools();

        $this->clearCaches();
    }

    public function down(): void
    {
        if (Schema::hasTable('recursion_config')) {
            DB::table('recursion_config')
                ->where('service_name', 'auto_decompose')
                ->where('disabled_reason', PromptIntegrityPolicy::DISABLED_REASON)
                ->update([
                    'enabled' => 1,
                    'disabled_reason' => null,
                    'disabled_at' => null,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('system_configs')) {
            $row = DB::table('system_configs')
                ->where('section', self::POLICY_SECTION)
                ->where('config_key', self::POLICY_KEY)
                ->first(['config_value']);
            $policy = $row && is_string($row->config_value ?? null)
                ? json_decode((string) $row->config_value, true)
                : null;

            if (($policy['schema'] ?? null) === PromptIntegrityPolicy::SCHEMA) {
                DB::table('system_configs')
                    ->where('section', self::POLICY_SECTION)
                    ->where('config_key', self::POLICY_KEY)
                    ->delete();
            }
        }

        $this->clearCaches();
    }

    private function appendNote(mixed $current, string $note): string
    {
        $current = trim(is_string($current) ? $current : '');
        if ($current === '') {
            return $note;
        }
        if (str_contains($current, $note)) {
            return $current;
        }

        return $current."\n".$note;
    }

    private function removePromptCompressorRuntimePolicy(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'mcp')
            ->where('config_key', 'router_runtime_policy')
            ->first(['config_value']);
        $policy = $row && is_string($row->config_value ?? null)
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($policy)) {
            return;
        }

        $timeouts = is_array($policy['tool_timeouts_seconds'] ?? null)
            ? $policy['tool_timeouts_seconds']
            : [];
        foreach (array_keys($timeouts) as $tool) {
            if (str_starts_with((string) $tool, 'prompt-compressor.')) {
                unset($timeouts[$tool]);
            }
        }
        $policy['tool_timeouts_seconds'] = $timeouts;

        DB::table('system_configs')
            ->where('section', 'mcp')
            ->where('config_key', 'router_runtime_policy')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function disablePromptCompressorRegistryTools(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        $columns = array_flip(Schema::getColumnListing('agent_tool_registry'));
        if (! isset($columns['mcp_server'], $columns['enabled'])) {
            return;
        }

        $updates = [
            'enabled' => 0,
            'updated_at' => now(),
        ];
        if (isset($columns['availability_status'])) {
            $updates['availability_status'] = 'disabled';
        }
        if (isset($columns['last_error'])) {
            $updates['last_error'] = PromptIntegrityPolicy::DISABLED_REASON;
        }

        DB::table('agent_tool_registry')
            ->where('mcp_server', 'prompt-compressor')
            ->update($updates);
    }

    private function clearCaches(): void
    {
        Cache::forget('rlm:config:auto_decompose');
        Cache::forget('syscfg:ai.prompt_integrity_policy');
        Cache::forget('syscfg:mcp.router_runtime_policy');
        Cache::forget('mcp:tool_catalog');
        Cache::forget('mcp:tool_catalog:static');
        Cache::forget('mcp:tool_catalog:dynamic');

        $fingerprint = md5(json_encode(config('mcp.servers', []), JSON_UNESCAPED_SLASHES) ?: '[]');
        Cache::forget("mcp:tool_catalog:static:{$fingerprint}");
        Cache::forget("mcp:tool_catalog:dynamic:{$fingerprint}");
    }
};
