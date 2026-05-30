<?php

namespace App\Console\Commands;

use App\Services\AgentTrajectoryService;
use Illuminate\Console\Command;

class AgentTrajectoryCommand extends Command
{
    protected $signature = 'agent:trajectory
        {--agent= : Agent id filter}
        {--session= : Session id filter}
        {--hours=168 : Lookback window in hours}
        {--limit=50 : Maximum steps}
        {--no-reviews : Omit review outcome rows}
        {--fixture : Emit sanitized regression fixture instead of trajectory}
        {--scenario=agent_trajectory_regression : Fixture scenario name}
        {--output= : Optional path to write JSON payload}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Build a redacted agent trajectory or sanitized regression fixture from retained audit evidence';

    public function handle(AgentTrajectoryService $trajectories): int
    {
        $params = [
            'agent_id' => $this->option('agent') ?: null,
            'session_id' => $this->option('session') ?: null,
            'hours' => (int) $this->option('hours'),
            'limit' => (int) $this->option('limit'),
            'include_reviews' => ! $this->option('no-reviews'),
            'scenario' => (string) $this->option('scenario'),
        ];

        $payload = $this->option('fixture')
            ? $trajectories->exportEvalFixture($params)
            : $trajectories->build($params);

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->error('Failed to encode trajectory JSON.');

            return self::FAILURE;
        }

        $output = trim((string) ($this->option('output') ?? ''));
        if ($output !== '') {
            $dir = dirname($output);
            if ($dir !== '' && $dir !== '.' && ! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
                $this->error("Unable to create output directory: {$dir}");

                return self::FAILURE;
            }

            if (@file_put_contents($output, $json.PHP_EOL, LOCK_EX) === false) {
                $this->error("Unable to write output file: {$output}");

                return self::FAILURE;
            }
        }

        if ($this->option('json')) {
            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('fixture')) {
            $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
            $this->line(sprintf(
                'Agent trajectory fixture: cases=%d failed_or_denied=%d reviews=%d raw_text_included=%s',
                (int) ($summary['case_count'] ?? 0),
                (int) ($summary['failed_or_denied_cases'] ?? 0),
                (int) ($summary['review_cases'] ?? 0),
                ($summary['raw_text_included'] ?? true) ? 'true' : 'false',
            ));
        } else {
            $this->line((string) ($payload['result_text'] ?? 'Agent trajectory built.'));
        }

        if ($output !== '') {
            $this->line("Wrote {$output}");
        }

        return self::SUCCESS;
    }
}
