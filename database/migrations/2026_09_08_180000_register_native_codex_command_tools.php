<?php

use App\Services\NativeCodexToolService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->definitions() as $definition) {
            // Existing operator registrations win; do not replace local decisions.
            DB::table('agent_tool_registry')->insertOrIgnore($definition + ['created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('system_configs')->insertOrIgnore($this->permissions() + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach ($this->definitions() as $definition) {
                $row = DB::table('agent_tool_registry')->where('name', $definition['name'])->lockForUpdate()->first();
                if ($row === null) {
                    continue;
                }
                foreach ($definition as $field => $value) {
                    $actual = $row->{$field};
                    $equal = in_array($field, ['parameters', 'permissions'], true)
                        ? json_decode($actual, true, flags: JSON_THROW_ON_ERROR) == json_decode($value, true, flags: JSON_THROW_ON_ERROR)
                        : (string) $actual === (string) (is_bool($value) ? (int) $value : $value);
                    if (! $equal) {
                        continue 2;
                    }
                }
                // Compare JSON semantically: MySQL normalizes its serialization.
                DB::table('agent_tool_registry')->where('id', $row->id)->delete();
            }
            DB::table('system_configs')->where($this->permissions())->delete();
        });
    }

    private function permissions(): array
    {
        return ['section' => 'cli', 'config_key' => 'native_permissions', 'data_type' => 'json',
            'config_value' => json_encode(['type' => 'workspaceWrite', 'writableRoots' => [],
                'networkAccess' => false, 'excludeTmpdirEnvVar' => true, 'excludeSlashTmp' => true], JSON_THROW_ON_ERROR),
            'description' => 'Native Codex per-command OS permissions. Session overrides and optional local saving use /set. Network access requires online mode; LAN-only native shell networking is not provided by this policy. Existing PLOS tool approvals still apply.'];
    }

    private function definitions(): array
    {
        $process = ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]{1,128}$', 'description' => 'Process ID returned by this session; other sessions cannot control it.'];
        $tools = [
            'start' => ['description' => 'Start an approved native Codex OS command on the selected execution host; preserve command text and stream complete output.',
                'risk' => 'destructive', 'confirm' => true, 'permissions' => ['native:execute'],
                'properties' => [
                    'command' => ['type' => 'string', 'minLength' => 1, 'description' => 'Exact shell command to execute, shown for approval.'],
                    'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Working directory; existing PLOS path rules apply.'],
                    'tty' => ['type' => 'boolean', 'default' => false],
                    'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3600000, 'default' => 30000],
                ], 'required' => ['command', 'path']],
            'input' => ['description' => 'Send exact input bytes to this session\'s running native process, optionally closing stdin.',
                'risk' => 'destructive', 'confirm' => true, 'permissions' => ['native:execute'],
                'properties' => ['process_id' => $process, 'input_base64' => ['type' => 'string', 'default' => ''],
                    'close_stdin' => ['type' => 'boolean', 'default' => false]], 'required' => ['process_id']],
            'output' => ['description' => 'Read retained native process output from an explicit chunk offset, including running/completed status and final exit code.',
                'risk' => 'read', 'confirm' => false, 'permissions' => ['native:read'],
                'properties' => ['process_id' => $process, 'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0]], 'required' => ['process_id']],
            'cancel' => ['description' => 'Cancel a native process owned by this CLI session without replaying its command.',
                'risk' => 'write', 'confirm' => false, 'permissions' => ['native:execute'],
                'properties' => ['process_id' => $process], 'required' => ['process_id']],
            'resize' => ['description' => 'Resize the terminal for this session\'s native process.',
                'risk' => 'write', 'confirm' => false, 'permissions' => ['native:execute'],
                'properties' => ['process_id' => $process,
                    'rows' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
                    'columns' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535]], 'required' => ['process_id', 'rows', 'columns']],
        ];

        $rows = [];
        foreach ($tools as $method => $tool) {
            $rows[] = ['name' => 'native_command_'.$method, 'service_class' => NativeCodexToolService::class,
                'method' => $method, 'description' => $tool['description'],
                'parameters' => json_encode(['type' => 'object', 'additionalProperties' => false,
                    'properties' => $tool['properties'], 'required' => $tool['required']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'returns_description' => 'Complete native process status and retained output; no silent truncation or automatic retry.',
                'permissions' => json_encode($tool['permissions'], JSON_THROW_ON_ERROR), 'risk_level' => $tool['risk'],
                'category' => 'coding', 'requires_confirmation' => $tool['confirm'], 'enabled' => true,
                'source' => 'manual', 'notes' => 'PLOS native Codex registration v1; session-bound executor required. Enablement/permissions remain table-controlled.'];
        }

        return $rows;
    }
};
