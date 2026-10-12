<?php

namespace App\Console\Commands;

use App\Services\OfflinePolicyService;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * 3b P03d — Offline readiness pre-flight.
 *
 * Runs the 3b plan's pre-flight checklist:
 *   1. Every table-defined offline assistant mode has a healthy local route
 *   2. Each active local instance has at least one advertised model
 *   3. Redis is reachable
 *   4. MySQL + PostgreSQL are reachable
 *   5. routing.offline_mode / routing.active_profile reported
 *   6. No long-running jobs currently mid-run that require external APIs
 *   7. routing.offline_mode is enabled (when --strict is passed)
 *
 * Exits non-zero if any required check fails. Use --json for programmatic
 * consumption. Use --strict to require offline_mode=enabled (default lets
 * the operator see the state without enforcing the toggle).
 */
class OllamaOfflinePreflightCommand extends Command
{
    protected $signature = 'ollama:offline-preflight
        {--json : Emit JSON instead of human-readable output}
        {--strict : Require routing.offline_mode to be enabled; fail otherwise}';

    protected $description = '3b offline readiness pre-flight — verifies hosts/roles/deps before going offline.';

    /** @var array<int,array{name:string,ok:bool,detail:string}> */
    private array $checks = [];

    public function handle(OfflinePolicyService $policy, SystemConfigService $systemConfig): int
    {
        $emitJson = (bool) $this->option('json');
        $strict = (bool) $this->option('strict');

        $this->checkHosts($systemConfig);
        $this->checkRoleCoverage();
        $this->checkRedis();
        $this->checkMysql();
        $this->checkPostgres();
        $this->checkRunningJobs();
        $this->checkOfflineMode($policy, $strict);

        $allOk = ! in_array(false, array_column($this->checks, 'ok'), true);

        $payload = [
            'overall' => $allOk ? 'ready' : 'not_ready',
            'checks' => $this->checks,
            'active_profile' => $policy->activeProfile(),
            'offline_mode_active' => $policy->isOfflineModeActive(),
        ];

        if ($emitJson) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('3b Offline Pre-Flight');
            foreach ($this->checks as $c) {
                $mark = $c['ok'] ? '✓' : '✗';
                $this->line("  [{$mark}] {$c['name']}: {$c['detail']}");
            }
            $this->line('');
            $this->line('Active profile   : '.$payload['active_profile']);
            $this->line('Offline mode     : '.($payload['offline_mode_active'] ? 'enabled' : 'disabled'));
            $this->line('Overall          : '.strtoupper($payload['overall']));
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    private function checkHosts(SystemConfigService $systemConfig): void
    {
        try {
            $policy = $systemConfig->get('ai.offline_dev_assist_runtime_policy');
            $modes = is_array($policy['modes'] ?? null) ? $policy['modes'] : [];
            if (($policy['version'] ?? null) !== 1 || $modes === []) {
                $this->checks[] = [
                    'name' => 'local_hosts',
                    'ok' => false,
                    'detail' => 'ai.offline_dev_assist_runtime_policy is unavailable or invalid',
                ];

                return;
            }

            $rows = DB::select(
                "SELECT instance_id, is_healthy, circuit_state, config
                 FROM llm_instances
                 WHERE instance_type = 'ollama' AND is_active = 1 AND routability = 'allowed'"
            );
            $summaries = [];
            $gaps = [];

            foreach ($modes as $mode => $definition) {
                if (! is_array($definition)) {
                    $gaps[] = "{$mode}=invalid";

                    continue;
                }

                $role = trim((string) ($definition['model_role'] ?? $policy['model_role'] ?? ''));
                $allowed = array_values(array_filter(
                    $definition['allowed_ollama_instance_ids'] ?? [],
                    static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
                ));
                $ready = [];

                foreach ($rows as $row) {
                    if ($allowed !== [] && ! in_array((string) $row->instance_id, $allowed, true)) {
                        continue;
                    }
                    if ((int) ($row->is_healthy ?? 0) !== 1 || ($row->circuit_state ?? 'closed') === 'open') {
                        continue;
                    }

                    $config = json_decode((string) ($row->config ?? '{}'), true);
                    $model = is_array($config)
                        ? trim((string) ($config['models'][$role] ?? ''))
                        : '';
                    if ($role !== '' && $model !== '') {
                        $ready[] = (string) $row->instance_id;
                    }
                }

                if ($ready === []) {
                    $gaps[] = "{$mode}=no healthy {$role} route";
                } else {
                    $summaries[] = "{$mode}=".implode('|', $ready);
                }
            }

            $this->checks[] = [
                'name' => 'local_hosts',
                'ok' => $gaps === [],
                'detail' => $gaps === []
                    ? 'table runtime modes ready: '.implode(', ', $summaries)
                    : 'runtime gaps: '.implode(', ', $gaps),
            ];
        } catch (\Throwable $e) {
            $this->checks[] = [
                'name' => 'local_hosts',
                'ok' => false,
                'detail' => 'error: '.$e->getMessage(),
            ];
        }
    }

    private function checkRoleCoverage(): void
    {
        // Every local ollama instance should advertise at least one role in
        // config.models. Missing role = gap in offline capability.
        $rows = DB::select(
            "SELECT instance_id, config
             FROM llm_instances
             WHERE instance_type = 'ollama' AND is_active = 1 AND routability = 'allowed'"
        );
        $gaps = [];
        foreach ($rows as $row) {
            $config = json_decode($row->config ?? '{}', true);
            $models = is_array($config['models'] ?? null) ? $config['models'] : [];
            if ($models === []) {
                $gaps[] = "{$row->instance_id} (no role models)";
            }
        }
        $this->checks[] = [
            'name' => 'role_coverage',
            'ok' => $gaps === [],
            'detail' => $gaps === []
                ? 'all active ollama instances advertise at least one role'
                : 'gaps: '.implode(', ', $gaps),
        ];
    }

    private function checkRedis(): void
    {
        try {
            $ping = Redis::connection()->ping();
            $this->checks[] = [
                'name' => 'redis',
                'ok' => $ping !== false,
                'detail' => 'ping='.var_export($ping, true),
            ];
        } catch (\Throwable $e) {
            $this->checks[] = [
                'name' => 'redis',
                'ok' => false,
                'detail' => 'error: '.$e->getMessage(),
            ];
        }
    }

    private function checkMysql(): void
    {
        try {
            $row = DB::connection('mysql')->selectOne('SELECT 1 AS ok');
            $this->checks[] = [
                'name' => 'mysql',
                'ok' => (int) ($row->ok ?? 0) === 1,
                'detail' => 'SELECT 1 → '.(int) ($row->ok ?? 0),
            ];
        } catch (\Throwable $e) {
            $this->checks[] = [
                'name' => 'mysql',
                'ok' => false,
                'detail' => 'error: '.$e->getMessage(),
            ];
        }
    }

    private function checkPostgres(): void
    {
        try {
            $row = DB::connection('pgsql_rag')->selectOne('SELECT 1 AS ok');
            $this->checks[] = [
                'name' => 'postgres',
                'ok' => (int) ($row->ok ?? 0) === 1,
                'detail' => 'SELECT 1 → '.(int) ($row->ok ?? 0),
            ];
        } catch (\Throwable $e) {
            $this->checks[] = [
                'name' => 'postgres',
                'ok' => false,
                'detail' => 'error: '.$e->getMessage(),
            ];
        }
    }

    private function checkRunningJobs(): void
    {
        // A job mid-run that depends on external APIs (web research, email
        // send, puppeteer) can stall or escalate when we flip offline mode.
        // Surface any running job so the operator can drain before going
        // offline.
        try {
            $rows = DB::select(
                "SELECT name FROM scheduled_jobs WHERE last_run_status = 'running'"
            );
            if ($rows === []) {
                $this->checks[] = [
                    'name' => 'running_jobs',
                    'ok' => true,
                    'detail' => 'no scheduled jobs currently running',
                ];
            } else {
                $names = array_map(static fn ($r) => $r->name, $rows);
                $this->checks[] = [
                    'name' => 'running_jobs',
                    'ok' => false,
                    'detail' => count($rows).' job(s) running: '.implode(', ', $names),
                ];
            }
        } catch (\Throwable $e) {
            $this->checks[] = [
                'name' => 'running_jobs',
                'ok' => false,
                'detail' => 'error: '.$e->getMessage(),
            ];
        }
    }

    private function checkOfflineMode(OfflinePolicyService $policy, bool $strict): void
    {
        $enabled = $policy->isOfflineModeActive();
        $this->checks[] = [
            'name' => 'offline_mode',
            'ok' => $strict ? $enabled : true,
            'detail' => $enabled
                ? 'routing.offline_mode = enabled'
                : 'routing.offline_mode = disabled'.($strict ? ' (FAIL under --strict)' : ' (informational)'),
        ];
    }
}
