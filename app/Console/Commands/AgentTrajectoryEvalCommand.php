<?php

namespace App\Console\Commands;

use App\Services\AgentTrajectoryService;
use App\Services\Ops\AgentTrajectoryFixtureEvalService;
use Illuminate\Console\Command;

class AgentTrajectoryEvalCommand extends Command
{
    protected $signature = 'agent:trajectory-eval
        {--reference= : storage/app-relative sanitized reference fixture JSON}
        {--actual=* : storage/app-relative actual fixture JSON; repeat for consistency evaluation}
        {--agent= : Agent id filter when evaluating the current retained trajectory}
        {--session= : Session id filter when evaluating the current retained trajectory}
        {--hours=168 : Lookback window in hours for the current retained trajectory}
        {--limit=50 : Maximum current trajectory steps}
        {--no-reviews : Omit current review outcome rows}
        {--mode=strict : strict, unordered, superset, or subset}
        {--scope=contracts : contracts or tools}
        {--json : Emit machine-readable JSON}
        {--compact : Emit only the deterministic evaluation payload}
        {--fail-on-mismatch : Return failure unless all repeats match}';

    protected $description = 'Compare sanitized agent trajectory fixtures without rerunning agents or exposing private content';

    public function handle(AgentTrajectoryFixtureEvalService $evaluator, AgentTrajectoryService $trajectories): int
    {
        $reference = $this->readArtifact((string) ($this->option('reference') ?? ''));
        if ($reference['error'] !== null) {
            return $this->finish($this->invalidPayload($reference['error']), self::FAILURE);
        }

        $actuals = [];
        $actualPaths = array_values(array_filter((array) $this->option('actual'), static fn (mixed $path): bool => trim((string) $path) !== ''));
        if ($actualPaths === []) {
            try {
                $actuals[] = $trajectories->exportEvalFixture([
                    'agent_id' => $this->option('agent') ?: null,
                    'session_id' => $this->option('session') ?: null,
                    'hours' => (int) $this->option('hours'),
                    'limit' => (int) $this->option('limit'),
                    'include_reviews' => ! $this->option('no-reviews'),
                    'scenario' => 'agent_trajectory_evaluation',
                ]);
            } catch (\Throwable) {
                return $this->finish($this->invalidPayload('current_trajectory_unavailable'), self::FAILURE);
            }
        } else {
            foreach ($actualPaths as $path) {
                $artifact = $this->readArtifact((string) $path);
                if ($artifact['error'] !== null) {
                    return $this->finish($this->invalidPayload($artifact['error']), self::FAILURE);
                }
                $actuals[] = $artifact['artifact'];
            }
        }

        $payload = $evaluator->evaluate(
            $actuals,
            $reference['artifact'],
            (string) $this->option('mode'),
            (string) $this->option('scope'),
        );
        $status = (string) ($payload['status'] ?? 'invalid');
        $exit = $status === 'invalid' || ($this->option('fail-on-mismatch') && $status !== 'match')
            ? self::FAILURE
            : self::SUCCESS;

        return $this->finish($payload, $exit);
    }

    /** @return array{artifact:array<string,mixed>,error:?string} */
    private function readArtifact(string $relative): array
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            return ['artifact' => [], 'error' => 'artifact_path_invalid'];
        }
        $root = realpath(storage_path('app'));
        $path = storage_path('app/'.trim($relative, '/'));
        $realPath = realpath($path);
        if ($root === false || $realPath === false || ! str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)) {
            return ['artifact' => [], 'error' => 'artifact_path_invalid'];
        }
        $size = is_file($realPath) ? filesize($realPath) : false;
        if ($size === false || $size > 1_048_576 || ! is_readable($realPath)) {
            return ['artifact' => [], 'error' => 'artifact_unreadable_or_too_large'];
        }
        $decoded = json_decode((string) file_get_contents($realPath), true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            return ['artifact' => [], 'error' => 'artifact_json_object_invalid'];
        }

        return ['artifact' => $decoded, 'error' => null];
    }

    /** @return array<string,mixed> */
    private function invalidPayload(string $violation): array
    {
        return [
            'schema' => AgentTrajectoryFixtureEvalService::SCHEMA,
            'status' => 'invalid',
            'read_only' => true,
            'all_repeats_match' => false,
            'violations' => [$violation],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function finish(array $payload, int $exit): int
    {
        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode trajectory evaluation JSON.');

                return self::FAILURE;
            }
            $this->line($json);

            return $exit;
        }

        $this->line(sprintf(
            'Agent trajectory eval: %s mode=%s scope=%s repeats=%d passed=%d failed=%d',
            strtoupper((string) ($payload['status'] ?? 'invalid')),
            (string) ($payload['mode'] ?? $this->option('mode')),
            (string) ($payload['scope'] ?? $this->option('scope')),
            (int) ($payload['repeat_count'] ?? 0),
            (int) ($payload['passed_repeat_count'] ?? 0),
            (int) ($payload['failed_repeat_count'] ?? 0),
        ));

        return $exit;
    }
}
