<?php

namespace App\Console\Commands;

use App\Services\OfflineAuditService;
use App\Services\OfflinePolicyService;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 3b P03c — Restart / reconnect reassertion.
 *
 * Invoke at boot (deploy script, systemd unit, Docker healthcheck) to:
 *   1. Surface the active `routing.active_profile` and `routing.offline_mode`
 *      state so an operator can spot a surprise widening after restart.
 *   2. Clean stale pending confirmations from `guardrail_confirmations`
 *      (status='pending' older than `--stale=Nmin`).
 *   3. Write a `mode_change` audit row with reason='reassertion' so the
 *      audit trail records the boot.
 *
 * Does NOT change the active profile. If a widening is required, the
 * operator must call `routing:profile activate <name>` explicitly.
 */
class RoutingReassertCommand extends Command
{
    private const RUNTIME_POLICY_CONFIG_KEY = 'routing.reassert_runtime_policy';

    protected $signature = 'routing:reassert
        {--stale= : Optional stale pending-confirmation threshold override in minutes}
        {--json : Emit JSON instead of human-readable output}';

    protected $description = 'Reassert active 3b profile + offline_mode on boot; clean stale pending confirmations; never auto-widens.';

    public function handle(
        SystemConfigService $config,
        OfflinePolicyService $policy,
        OfflineAuditService $audit,
    ): int {
        $emitJson = (bool) $this->option('json');
        $stalePolicy = $this->staleThreshold($config);
        if (($stalePolicy['status'] ?? 'ok') !== 'ok') {
            return $this->emitPayload([
                'status' => 'error',
                'error' => $stalePolicy['error'] ?? 'reassert_runtime_policy_invalid',
                'policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
                'widened' => false,
            ], $emitJson);
        }

        $staleMinutes = (int) $stalePolicy['minutes'];
        $staleSource = (string) $stalePolicy['source'];

        $profile = $policy->activeProfile();
        $offlineMode = $policy->isOfflineModeActive();

        // Clean stale pending confirmations — these are guardrail requests
        // the operator never responded to. After reassertion they should be
        // expired rather than lingering.
        $staleCleaned = 0;
        if ($this->tableExists('guardrail_confirmations')) {
            $staleCleaned = (int) DB::update(
                "UPDATE guardrail_confirmations
                 SET status = 'expired'
                 WHERE status = 'pending'
                   AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$staleMinutes]
            );
        }

        // Emit an audit row so the boot is traceable and the `from` side
        // shows what the previous run left behind.
        $audit->recordModeChange(
            from: $profile,
            to: $profile,
            actor: 'routing:reassert',
            reason: sprintf(
                'Reassertion at boot: offline_mode=%s, stale_cleaned=%d',
                $offlineMode ? 'enabled' : 'disabled',
                $staleCleaned,
            ),
            context: [
                'stale_threshold_minutes' => $staleMinutes,
                'stale_threshold_source' => $staleSource,
                'stale_cleaned' => $staleCleaned,
                'offline_mode_active' => $offlineMode,
            ],
        );

        Log::info('routing:reassert complete', [
            'profile' => $profile,
            'offline_mode' => $offlineMode,
            'stale_pending_confirmations_cleaned' => $staleCleaned,
            'stale_threshold_minutes' => $staleMinutes,
            'stale_threshold_source' => $staleSource,
        ]);

        $payload = [
            'status' => 'ok',
            'active_profile' => $profile,
            'offline_mode_active' => $offlineMode,
            'policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
            'stale_threshold_minutes' => $staleMinutes,
            'stale_threshold_source' => $staleSource,
            'stale_pending_confirmations_cleaned' => $staleCleaned,
            'widened' => false,  // Pinned by contract — this command never widens.
        ];

        return $this->emitPayload($payload, $emitJson, $profile, $offlineMode, $staleCleaned, $staleMinutes);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitPayload(
        array $payload,
        bool $emitJson,
        ?string $profile = null,
        ?bool $offlineMode = null,
        ?int $staleCleaned = null,
        ?int $staleMinutes = null,
    ): int {
        if ($emitJson) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            if (($payload['status'] ?? 'ok') !== 'ok') {
                $this->error('routing:reassert blocked: '.($payload['error'] ?? 'unknown_error'));
            } else {
                $this->info('3b state reasserted:');
                $this->line("  active profile       : {$profile}");
                $this->line('  offline mode         : '.($offlineMode ? 'enabled' : 'disabled'));
                $this->line("  stale confirmations  : {$staleCleaned} cleaned (threshold={$staleMinutes}min)");
                $this->line('  threshold source     : '.($payload['stale_threshold_source'] ?? 'unknown'));
                $this->line('  widened by this run  : no (never)');
            }
        }

        return ($payload['status'] ?? 'ok') === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{status:string,minutes?:int,source?:string,error?:string}
     */
    private function staleThreshold(SystemConfigService $config): array
    {
        $override = $this->option('stale');
        if ($override !== null && trim((string) $override) !== '') {
            $minutes = $this->positiveMinutes($override);

            return $minutes !== null
                ? ['status' => 'ok', 'minutes' => $minutes, 'source' => 'cli_option.--stale']
                : ['status' => 'error', 'error' => 'invalid_stale_threshold_override'];
        }

        $policy = $config->get(self::RUNTIME_POLICY_CONFIG_KEY);
        if (! is_array($policy)) {
            return ['status' => 'error', 'error' => 'reassert_runtime_policy_missing'];
        }

        $minutes = $this->positiveMinutes($policy['stale_confirmation_minutes'] ?? null);
        if ($minutes === null) {
            return ['status' => 'error', 'error' => 'reassert_runtime_policy_invalid_stale_confirmation_minutes'];
        }

        return [
            'status' => 'ok',
            'minutes' => $minutes,
            'source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY.'.stale_confirmation_minutes',
        ];
    }

    private function positiveMinutes(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $minutes = (int) $value;

        return $minutes > 0 ? $minutes : null;
    }

    private function tableExists(string $name): bool
    {
        // SHOW TABLES LIKE does not accept PDO parameter binding. $name is an
        // internal hardcoded identifier, not user input — safe to interpolate.
        $safeName = preg_replace('/[^a-z0-9_]/i', '', $name);
        try {
            $row = DB::selectOne("SHOW TABLES LIKE '{$safeName}'");

            return $row !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
