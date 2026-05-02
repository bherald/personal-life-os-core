<?php

namespace App\Console\Commands;

use App\Services\OfflineConfigEvalService;
use App\Services\OfflineConfigWritebackService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class OfflineEvalConfigCommand extends Command
{
    protected $signature = 'offline:eval-config
        {--profile=* : Optional offline profile(s) to inspect or apply}
        {--apply : Persist recommended llm_model_profiles, llm_instances role maps, and ollama_models guidance}
        {--json : Emit JSON output}';

    protected $description = 'Evaluate and optionally apply offline local Ollama profile recommendations';

    public function __construct(
        private readonly OfflineConfigEvalService $evaluator,
        private readonly OfflineConfigWritebackService $writeback
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $report = $this->evaluator->evaluate(
                $this->option('profile') !== [] ? (array) $this->option('profile') : null
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $applied = null;
        if ($this->option('apply')) {
            $applied = $this->writeback->apply($report);
        }

        if ($this->option('json')) {
            $payload = ['report' => $report];
            if ($applied !== null) {
                $payload['apply'] = $applied;
            }

            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($report['summary']['missing_profiles'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Offline Profiles');
        $this->table(
            ['Profile', 'Current', 'Recommended', 'Instance', 'Action'],
            array_map(static fn (array $row): array => [
                $row['profile'],
                $row['current_model'] ?? '-',
                $row['recommended_model'] ?? '-',
                $row['recommended_instance'] ?? '-',
                $row['action'],
            ], $report['profiles'])
        );

        $this->newLine();
        $this->info('Instance Role Maps');
        $this->table(
            ['Instance', 'Role', 'Current', 'Recommended', 'Action'],
            array_map(static fn (array $row): array => [
                $row['instance_id'],
                $row['role'],
                $row['current_model'] ?? '-',
                $row['recommended_model'] ?? '-',
                $row['action'],
            ], $report['instance_models'])
        );

        $this->newLine();
        $this->info('Model Guidance');
        foreach ($report['model_updates'] as $update) {
            $capabilities = $update['capabilities'] === [] ? 'n/a' : implode(',', $update['capabilities']);
            $expertise = $update['expertise'] === [] ? 'n/a' : implode(',', $update['expertise']);
            $this->line(sprintf(
                '%s:%s | %s | %s | %s',
                $update['instance_id'],
                $update['model'],
                $update['profile'],
                $capabilities,
                $expertise
            ));
        }

        if ($applied !== null) {
            $this->newLine();
            $summary = $applied['summary'] ?? [];
            $this->info(sprintf(
                'Applied profiles=%d instance_roles=%d model_metadata=%d',
                $summary['profiles_written'] ?? 0,
                $summary['instance_roles_written'] ?? 0,
                $summary['model_metadata_written'] ?? 0
            ));
        }

        return ($report['summary']['missing_profiles'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
