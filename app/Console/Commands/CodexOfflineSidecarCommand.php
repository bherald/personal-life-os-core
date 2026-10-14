<?php

namespace App\Console\Commands;

use App\Services\ModelHardwareAdmissionService;
use App\Services\OfflineCodingRouteService;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class CodexOfflineSidecarCommand extends Command
{
    protected $signature = 'codex:offline-sidecar
        {prompt?* : Local/offline task to split across read-only model lanes}
        {--json : Emit machine-readable JSON}
        {--execute : Run the read-only sidecar lanes instead of only printing the plan}
        {--dry-run : Force plan-only mode}
        {--prompt-b64= : Base64-encoded UTF-8 prompt override for MCP/shell-safe calls}
        {--primary-profile= : Optional profile override for the admitted primary lane}
        {--primary-model= : Optional installed-model override for the admitted primary lane}
        {--sidecar-profile= : Optional admitted Codex profile for a second review lane}
        {--sidecar-model= : Optional admitted model for a second review lane}
        {--effort= : Optional Codex reasoning effort override for both lanes}
        {--cwd= : Working directory override}
        {--timeout=900 : Per-lane timeout in seconds}';

    protected $description = 'Plan or run a read-only multi-model offline Codex sidecar split';

    public function handle(
        ModelHardwareAdmissionService $admission,
        OfflineCodingRouteService $offlineRoute,
    ): int {
        $task = $this->decodedPromptOverride();
        if ($task === false) {
            return $this->failWithPayload('Invalid --prompt-b64. Use base64-encoded UTF-8 text.');
        }
        if ($task === null) {
            $task = trim(implode(' ', (array) $this->argument('prompt')));
        }
        if ($task === '') {
            $task = 'Inspect this repository and suggest one small next step. Do not modify files.';
        }

        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT);
        if ($timeout === false || $timeout < 1) {
            return $this->failWithPayload('Invalid --timeout. Use a positive integer.');
        }

        $cwd = $this->option('cwd') ?: base_path();
        if (! is_string($cwd) || $cwd === '' || ! is_dir($cwd)) {
            return $this->failWithPayload('Invalid --cwd. Directory does not exist.');
        }

        $sidecarProfile = trim((string) $this->option('sidecar-profile'));
        $sidecarModel = trim((string) $this->option('sidecar-model'));
        if (($sidecarProfile === '') !== ($sidecarModel === '')) {
            return $this->failWithPayload('Provide both --sidecar-profile and --sidecar-model, or neither.');
        }

        $execute = (bool) $this->option('execute') && ! (bool) $this->option('dry-run');
        $primaryRoute = $offlineRoute->resolve(
            $this->optionalString('primary-profile'),
            $this->optionalString('primary-model'),
            'coding',
            $this->optionalString('effort'),
        );
        if (($primaryRoute['execution_allowed'] ?? false) !== true) {
            return $this->failWithPayload('Primary offline coding route is blocked: '.($primaryRoute['reason'] ?? 'unknown'));
        }
        if ($execute && $sidecarProfile !== '') {
            try {
                $bindings = app(SystemConfigService::class)->llmRoutingBinding('offline_sidecar');
                $instanceId = $bindings['profiles'][$sidecarProfile] ?? null;
                $instance = is_string($instanceId) ? DB::table('llm_instances')->where('instance_id', $instanceId)->first() : null;
                $assessment = $instance ? $admission->assess($instance, $sidecarModel, 'coding') : null;
            } catch (Throwable) {
                $assessment = null;
            }
            if (! (bool) ($assessment['route_allowed'] ?? false)) {
                return $this->failWithPayload(
                    'Sidecar execution is blocked by registered model hardware admission: '.($assessment['decision'] ?? 'instance unavailable')
                );
            }
        }
        $lanes = $this->lanes($task, $cwd, $timeout, $primaryRoute);

        if ($execute) {
            $lanes = $this->runLanes($lanes, $cwd, $timeout);
        }

        $payload = [
            'schema' => 'plos_codex_offline_sidecar.v1',
            'status' => $this->status($lanes, $execute),
            'mode' => [
                'execute' => $execute,
                'dry_run' => ! $execute,
                'writes_allowed' => false,
                'sandbox' => 'read-only',
                'approval_policy' => 'never',
            ],
            'prompt_hash' => hash('sha256', $task),
            'primary_route' => $primaryRoute,
            'coordination' => [
                'strategy' => count($lanes) > 1 ? 'read_only_parallel_sidecar' : 'read_only_primary_lane',
                'primary_lane' => 'primary_planner',
                'sidecar_lane' => count($lanes) > 1 ? 'optional_reviewer' : null,
                'merge_rule' => 'Use the primary lane for synthesis, use the sidecar lane for independent risk/test checks, and let only one approved editor apply changes.',
                'write_gate' => 'No sidecar lane may edit files; use ./offline and its PLOS approval flow for the final approved edit.',
            ],
            'lanes' => $lanes,
            'next_step' => $execute
                ? 'Review lane output, choose the smallest verified edit, then run one approved write session.'
                : 'Re-run with --execute, or add an explicitly admitted sidecar profile/model for a second read-only lane.',
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return in_array($payload['status'], ['pass', 'planned', 'partial'], true) ? self::SUCCESS : self::FAILURE;
        }

        $this->info($execute ? 'Offline sidecar result' : 'Offline sidecar plan');
        foreach ($lanes as $lane) {
            $this->line(sprintf('  %s: %s / %s / %s', $lane['id'], $lane['profile'], $lane['model'], $lane['status']));
        }
        $this->line($payload['next_step']);

        return in_array($payload['status'], ['pass', 'planned', 'partial'], true) ? self::SUCCESS : self::FAILURE;
    }

    private function decodedPromptOverride(): string|false|null
    {
        $encoded = $this->option('prompt-b64');
        if (! is_string($encoded) || trim($encoded) === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            return false;
        }

        return trim($decoded);
    }

    private function failWithPayload(string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'schema' => 'plos_codex_offline_sidecar.v1',
                'status' => 'fail',
                'error' => $message,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    private function optionalString(string $option): ?string
    {
        $value = trim((string) $this->option($option));

        return $value === '' ? null : $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lanes(string $task, string $cwd, int $timeout, array $primaryRoute): array
    {
        $effort = (string) $primaryRoute['reasoning_effort'];

        $lanes = [
            $this->lane(
                'primary_planner',
                'Primary .87 planning/synthesis lane',
                (string) $primaryRoute['profile'],
                (string) $primaryRoute['model'],
                $effort,
                $cwd,
                $timeout,
                $this->primaryPrompt($task),
            ),
        ];

        $sidecarProfile = trim((string) $this->option('sidecar-profile'));
        $sidecarModel = trim((string) $this->option('sidecar-model'));
        if ($sidecarProfile !== '' && $sidecarModel !== '') {
            $lanes[] = $this->lane(
                'jetson_reviewer',
                'Optional independent review/check lane',
                $sidecarProfile,
                $sidecarModel,
                $effort,
                $cwd,
                $timeout,
                $this->sidecarPrompt($task),
            );
        }

        return $lanes;
    }

    /**
     * @return array<string, mixed>
     */
    private function lane(
        string $id,
        string $purpose,
        string $profile,
        string $model,
        string $effort,
        string $cwd,
        int $timeout,
        string $prompt,
    ): array {
        $command = [
            'codex',
            'exec',
            '-C',
            $cwd,
            '--skip-git-repo-check',
            '-m',
            $model,
            '-s',
            'read-only',
            '--color',
            'never',
            '-c',
            'approval_policy="never"',
            '-c',
            'model_reasoning_effort="'.$effort.'"',
            '-p',
            $profile,
            '--json',
            '--ephemeral',
            '-',
        ];

        return [
            'id' => $id,
            'purpose' => $purpose,
            'profile' => $profile,
            'model' => $model,
            'reasoning_effort' => $effort,
            'cwd' => $cwd,
            'timeout_seconds' => $timeout,
            'status' => 'planned',
            'prompt_hash' => hash('sha256', $prompt),
            'command' => $command,
            'prompt' => $prompt,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lanes
     * @return list<array<string, mixed>>
     */
    private function runLanes(array $lanes, string $cwd, int $timeout): array
    {
        $processes = [];
        foreach ($lanes as $index => $lane) {
            $process = new Process($lane['command'], $cwd, null, (string) $lane['prompt'], $timeout);
            $process->setTimeout($timeout);
            $process->start();
            $processes[$index] = $process;
            $lanes[$index]['status'] = 'running';
            unset($lanes[$index]['prompt']);
        }

        foreach ($processes as $index => $process) {
            $started = microtime(true);
            $timedOut = false;
            try {
                $process->wait();
            } catch (ProcessTimedOutException) {
                $timedOut = true;
                $process->stop(0);
            }

            $summary = $this->summarizeJsonEvents($process->getOutput());
            $ok = ! $timedOut && $process->isSuccessful();

            $lanes[$index]['status'] = $ok ? 'pass' : ($timedOut ? 'timeout' : 'fail');
            $lanes[$index]['exit_code'] = $process->getExitCode();
            $lanes[$index]['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
            $lanes[$index]['command_execution_events'] = $summary['command_execution_events'];
            $lanes[$index]['response'] = $summary['response'];
            $lanes[$index]['errors'] = $summary['errors'];
            if ($timedOut) {
                $lanes[$index]['errors'][] = "lane timed out after {$timeout}s";
            }
            if (! $ok && $process->getErrorOutput() !== '') {
                $lanes[$index]['stderr'] = mb_substr($process->getErrorOutput(), 0, 1000);
            }
        }

        return $lanes;
    }

    /**
     * @return array{command_execution_events:int,response:?string,errors:list<string>}
     */
    private function summarizeJsonEvents(string $output): array
    {
        $commandEvents = 0;
        $response = null;
        $errors = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }

            if (($event['type'] ?? null) === 'command_execution') {
                $commandEvents++;
            }

            $item = is_array($event['item'] ?? null) ? $event['item'] : [];
            if (($item['type'] ?? null) === 'command_execution') {
                $commandEvents++;
            }

            if (($item['type'] ?? null) === 'agent_message') {
                $response = (string) ($item['text'] ?? '');
            }

            if (($event['type'] ?? null) === 'error') {
                $errors[] = mb_substr((string) ($event['message'] ?? 'unknown error'), 0, 500);
            }

            if (($item['type'] ?? null) === 'error') {
                $errors[] = mb_substr((string) ($item['message'] ?? 'unknown error'), 0, 500);
            }
        }

        return [
            'command_execution_events' => $commandEvents,
            'response' => $response,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lanes
     */
    private function status(array $lanes, bool $execute): string
    {
        if (! $execute) {
            return 'planned';
        }

        $passed = count(array_filter($lanes, fn (array $lane): bool => ($lane['status'] ?? null) === 'pass'));
        if ($passed === count($lanes)) {
            return 'pass';
        }

        return $passed > 0 ? 'partial' : 'fail';
    }

    private function primaryPrompt(string $task): string
    {
        return implode("\n\n", [
            'You are the primary offline PLOS development planner running on the stronger local coding host.',
            'Read-only lane: inspect as needed, do not modify files, and do not ask another model to edit.',
            'Decompose the operator task into the smallest safe implementation step, likely files/commands to inspect, expected verification, and explicit risks.',
            'Return concise bullets under: plan, files_to_inspect, verification, handoff_to_single_editor.',
            'Operator task: '.$task,
        ]);
    }

    private function sidecarPrompt(string $task): string
    {
        return implode("\n\n", [
            'You are the Jetson offline sidecar reviewer for PLOS development.',
            'Read-only lane: independently inspect or reason about the task, do not modify files, and do not duplicate the primary planner.',
            'Focus on missed risks, tests, rollback concerns, and one bounded subtask that the primary editor can use.',
            'Return concise bullets under: risks, checks, suggested_subtask, confidence.',
            'Operator task: '.$task,
        ]);
    }
}
