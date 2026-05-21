<?php

namespace App\Console\Commands;

use App\Services\OfflineAuditService;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Inspect and switch the active operator routing profile (3b2 minimal slice).
 *
 * Profiles layer a tool/MCP/capability allowlist on top of routing.offline_mode.
 * This command reads and writes `system_configs` rows in the `routing` section.
 * It does NOT enforce profile restrictions at runtime — that is a follow-up
 * slice that will touch LLMPoolManagerService / AIService::buildFallbackChain().
 *
 * Hard whitelist of profile names: default | offline_review | offline_dev_assist |
 * offline_genealogy_assist | hybrid_review | hybrid_dev_assist |
 * cloud_escalation_only.
 * Anything else is rejected with exit code 2.
 *
 * Usage:
 *   php artisan routing:profile status
 *   php artisan routing:profile list
 *   php artisan routing:profile activate offline_review
 *   php artisan routing:profile activate default
 */
class RoutingProfileCommand extends Command
{
    protected $signature = 'routing:profile
        {action : status|activate|list}
        {name? : default|offline_review|offline_dev_assist|offline_genealogy_assist|hybrid_review|hybrid_dev_assist|cloud_escalation_only (required for activate)}';

    protected $description = 'Inspect or switch the active operator routing profile (system_configs routing.* rows). Human-controlled only — connectivity regain never changes the active profile.';

    /**
     * P02e — the 3b mode ladder. Every rung is explicitly human-selected via
     * this command; no other code path writes `routing.active_profile`, so
     * internet regain alone cannot widen permissions. Pinned by
     * `test_only_this_command_writes_active_profile` in the Feature suite.
     */
    private const VALID_PROFILES = [
        'default',
        'offline_review',
        'offline_dev_assist',
        'offline_genealogy_assist',
        'hybrid_review',
        'hybrid_dev_assist',
        'cloud_escalation_only',
    ];

    private const ACTIVE_KEY = 'routing.active_profile';

    private const CACHE_PREFIX = 'syscfg:';

    public function handle(SystemConfigService $config): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        return match ($action) {
            'status' => $this->showStatus($config),
            'list' => $this->listProfiles($config),
            'activate' => $this->activate($config),
            default => $this->fail("Unknown action '{$action}'. Use: status | list | activate", self::FAILURE),
        };
    }

    private function showStatus(SystemConfigService $config): int
    {
        $active = $this->readActiveProfile();
        $payload = [
            'active_profile' => $active,
            'profile_definition' => null,
        ];

        if ($active !== 'default') {
            $payload['profile_definition'] = $this->readProfileDefinition($active);
        }

        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function listProfiles(SystemConfigService $config): int
    {
        $rows = DB::select(
            "SELECT section, config_key, config_value, data_type, description
             FROM system_configs
             WHERE section = ? AND config_key LIKE 'profile.%'
             ORDER BY config_key"
        , ['routing']);

        $profiles = [];
        foreach ($rows as $row) {
            $name = substr($row->config_key, strlen('profile.'));
            $definition = null;
            if ($row->data_type === 'json' && is_string($row->config_value)) {
                $decoded = json_decode($row->config_value, true);
                if (is_array($decoded)) {
                    $definition = $decoded;
                }
            }

            $profiles[$name] = [
                'data_type' => $row->data_type,
                'description' => $row->description,
                'definition' => $definition ?? $row->config_value,
            ];
        }

        $payload = [
            'active_profile' => $this->readActiveProfile(),
            'profiles' => $profiles,
        ];

        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function activate(SystemConfigService $config): int
    {
        $name = (string) ($this->argument('name') ?? '');
        $name = strtolower(trim($name));

        if ($name === '') {
            $this->error('activate requires a profile name: '.implode(' | ', self::VALID_PROFILES));

            return 2;
        }

        if (! in_array($name, self::VALID_PROFILES, true)) {
            $this->error("Unknown profile '{$name}'. Allowed: ".implode(' | ', self::VALID_PROFILES));

            return 2;
        }

        $before = $this->readActiveProfile();

        $existing = DB::selectOne(
            'SELECT id FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
            ['routing', 'active_profile']
        );

        if ($existing === null) {
            DB::insert(
                'INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    'routing',
                    'active_profile',
                    $name,
                    'string',
                    'Active routing profile. default=no restrictions beyond offline_mode. Values: '.implode(' | ', self::VALID_PROFILES).'.',
                ]
            );
        } else {
            DB::update(
                'UPDATE system_configs SET config_value = ?, data_type = ?, updated_at = NOW()
                 WHERE section = ? AND config_key = ?',
                [$name, 'string', 'routing', 'active_profile']
            );
        }

        // Evict SystemConfigService cache so the next read sees the new value.
        try {
            Cache::forget(self::CACHE_PREFIX.self::ACTIVE_KEY);
        } catch (\Throwable $e) {
            // Cache layer unavailable — DB is source of truth, non-fatal.
        }
        $config->forget(self::ACTIVE_KEY);

        Log::info('routing:profile activated', [
            'from' => $before,
            'to' => $name,
            'source' => 'routing:profile',
        ]);

        // P02g — persist an audit receipt for the mode transition. Silent
        // no-op when offline_audit_events table is absent.
        try {
            app(OfflineAuditService::class)->recordModeChange(
                from: $before,
                to: $name,
                actor: 'routing:profile',
                reason: 'Operator profile activation',
                context: ['command' => 'routing:profile', 'argv' => $_SERVER['argv'] ?? []],
            );
        } catch (\Throwable $e) {
            Log::debug('routing:profile: audit write failed', ['error' => $e->getMessage()]);
        }

        return $this->showStatus($config);
    }

    private function readActiveProfile(): string
    {
        $row = DB::selectOne(
            'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
            ['routing', 'active_profile']
        );

        if ($row === null || ! is_string($row->config_value)) {
            return 'default';
        }

        $value = strtolower(trim($row->config_value));

        return $value === '' ? 'default' : $value;
    }

    private function readProfileDefinition(string $name): ?array
    {
        $row = DB::selectOne(
            'SELECT config_value, data_type FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
            ['routing', 'profile.'.$name]
        );

        if ($row === null || ! is_string($row->config_value)) {
            return null;
        }

        if ($row->data_type === 'json') {
            $decoded = json_decode($row->config_value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return ['raw' => $row->config_value];
    }
}
