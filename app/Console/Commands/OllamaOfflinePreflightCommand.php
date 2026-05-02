<?php

namespace App\Console\Commands;

use App\Services\LLMPoolManagerService;
use App\Services\OfflinePolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * 3b P03d — Offline readiness pre-flight.
 *
 * Runs the 3b plan's pre-flight checklist:
 *   1. Both Ollama hosts reachable (via LLMPoolManagerService availability)
 *   2. Each local role has at least one advertised model
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

    public function handle(LLMPoolManagerService $pool, OfflinePolicyService $policy): int
    {
        $emitJson = (bool) $this->option('json');
        $strict = (bool) $this->option('strict');

        $this->checkHosts($pool);
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

    private function checkHosts(LLMPoolManagerService $pool): void
    {
        $avail = $pool->describeLocalAvailability();
        $this->checks[] = [
            'name' => 'local_hosts',
            'ok' => $avail['state'] === 'all_locals_up',
            'detail' => sprintf(
                'state=%s primary=%s secondary=%s',
                $avail['state'],
                $avail['primary_up'] ? 'up' : 'DOWN',
                $avail['secondary_up'] ? 'up' : 'DOWN',
            ),
        ];
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
            $row = DB::connection('pgsql')->selectOne('SELECT 1 AS ok');
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
                "SELECT name, last_pid FROM scheduled_jobs WHERE last_run_status = 'running'"
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
