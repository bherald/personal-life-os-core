<?php

namespace App\Console\Commands;

use App\Services\Ops\AgentConfidenceCalibrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class OpsAgentConfidenceCalibrationCommand extends Command
{
    protected $signature = 'ops:agent-confidence-calibration
                            {--json : Emit machine-readable JSON}
                            {--compact : Emit the scheduled aggregate-only contract}
                            {--strict : Return non-zero when review is required}';

    protected $description = 'Measure model-authored Genea confidence against content-free operator decision outcomes';

    public function handle(AgentConfidenceCalibrationService $calibration): int
    {
        $payload = [
            'schema' => AgentConfidenceCalibrationService::SCHEMA,
            'scope' => 'agent_confidence_calibration',
            'generated_at' => now()->toIso8601String(),
            'policy_source' => 'system_configs.ops.agent_confidence_calibration',
            'cohort' => null,
            'status' => 'warning',
            'compact' => (bool) $this->option('compact'),
            'read_only' => true,
            'content_free' => true,
            'mutation_allowed' => false,
            'review_reasons' => [],
            'metrics' => [],
            'bins' => [],
            'posture' => [
                'approval_is_operator_decision_proxy' => true,
                'approval_is_not_global_factual_correctness' => true,
                'changes_confidence_thresholds' => false,
                'changes_review_routing' => false,
                'changes_approval_authority' => false,
                'llm_used' => false,
                'network_used' => false,
                'compression' => false,
                'precompaction' => false,
            ],
        ];

        try {
            $config = $this->config();
            $payload['cohort'] = [
                'review_type' => $config['review_type'],
                'window_days' => $config['window_days'],
                'automatic_decisions_excluded' => true,
                'decision_statuses' => ['approved', 'rejected'],
            ];
            $report = $calibration->report($this->samples($config), $config);
            $payload = array_replace($payload, $report);
        } catch (Throwable $e) {
            $payload['error'] = $e instanceof InvalidArgumentException
                ? 'agent_confidence_calibration_policy_invalid'
                : 'agent_confidence_calibration_evidence_unavailable';
        }

        return $this->emitPayload($payload);
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        if (! Schema::hasTable('system_configs') || ! Schema::hasTable('agent_review_queue')) {
            throw new InvalidArgumentException('Required calibration tables are unavailable');
        }

        $row = DB::selectOne(
            'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
            ['ops', 'agent_confidence_calibration']
        );
        $config = $row === null ? null : json_decode((string) $row->config_value, true);
        if (! is_array($config) || ($config['enabled'] ?? null) !== true) {
            throw new InvalidArgumentException('Calibration policy is missing, invalid, or disabled');
        }
        if (($config['review_type'] ?? null) !== 'genealogy_finding'
            || ! is_int($config['window_days'] ?? null)
            || $config['window_days'] < 30
            || $config['window_days'] > 730
            || ! is_int($config['max_samples'] ?? null)
            || $config['max_samples'] < 1
            || $config['max_samples'] > 5000) {
            throw new InvalidArgumentException('Calibration cohort policy is invalid');
        }

        return $config;
    }

    /** @return list<array{confidence:float,approved:bool}> */
    private function samples(array $config): array
    {
        $rows = DB::table('agent_review_queue')
            ->select(['confidence', 'status'])
            ->where('review_type', $config['review_type'])
            ->whereIn('status', ['approved', 'rejected'])
            ->whereNotNull('confidence')
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '>=', now()->subDays($config['window_days']))
            ->where(function ($query): void {
                $query->whereNull('reviewer_notes')->orWhere('reviewer_notes', 'not like', 'Auto-%');
            })
            ->orderByDesc('reviewed_at')
            ->limit($config['max_samples'])
            ->get();

        return $rows->map(static fn (object $row): array => [
            'confidence' => (float) $row->confidence,
            'approved' => $row->status === 'approved',
        ])->values()->all();
    }

    private function emitPayload(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        } else {
            $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
            $this->line(sprintf(
                'agent-confidence-calibration: status=%s samples=%d ece=%s brier=%s threshold_precision=%s',
                $payload['status'] ?? 'warning',
                (int) ($metrics['sample_count'] ?? 0),
                $metrics['expected_calibration_error'] ?? 'n/a',
                $metrics['brier_score'] ?? 'n/a',
                $metrics['threshold_precision'] ?? 'n/a'
            ));
        }

        if (($payload['status'] ?? null) === 'warning') {
            return self::FAILURE;
        }
        if ((bool) $this->option('strict') && ($payload['status'] ?? null) === 'review_required') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
