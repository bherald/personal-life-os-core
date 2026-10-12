<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CodexOfflineImprovementCommand extends Command
{
    protected $signature = 'codex:offline-improvement
        {--summary= : Task outcome or lesson text}
        {--summary-b64= : Task outcome or lesson text encoded as base64}
        {--area=offline_dev : Improvement area, such as offline_dev, genealogy, media, ops, or ai_pipeline}
        {--lesson= : Privacy-safe, concrete lesson for a saved report}
        {--evidence-ref= : Stable local evidence reference for a saved report}
        {--target= : Exact skill, command, tool, or test target for a saved report}
        {--proposal-type= : One proposed change type for a saved report}
        {--example : Run a built-in proposal example}
        {--write-report : Persist the proposal JSON under storage/app/offline-improvement-proposals}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Create review-gated offline skill, memory, tool, and test improvement proposals';

    public function handle(): int
    {
        $summary = $this->summaryInput();
        if ($summary['error'] !== null) {
            return $this->finish([
                'schema' => 'plos_codex_offline_improvement.v1',
                'status' => 'invalid_input',
                'mode' => 'observe',
                'read_only' => true,
                'error' => $summary['error'],
            ], self::FAILURE);
        }

        $area = $this->normalizeArea((string) $this->option('area'));
        $writeReport = (bool) $this->option('write-report');
        $text = $summary['text'];
        $actionable = $this->actionableInput();
        if ($writeReport && $actionable['error'] !== null) {
            return $this->finish([
                'schema' => 'plos_codex_offline_improvement.v1',
                'status' => 'invalid_input',
                'mode' => 'observe',
                'read_only' => true,
                'error' => $actionable['error'],
            ], self::FAILURE);
        }

        $payload = [
            'schema' => 'plos_codex_offline_improvement.v1',
            'status' => 'pass',
            'mode' => $writeReport ? 'write_report' : 'observe',
            'read_only' => ! $writeReport,
            'generated_at' => now()->toIso8601String(),
            'source' => [
                'pattern_reference' => 'NousResearch/hermes-agent review-gated skill and memory improvement loop',
                'implementation' => 'PLOS-native proposal report; no autonomous skill or memory mutation',
            ],
            'area' => $area,
            'summary_sha256' => hash('sha256', $text),
            'summary_bytes' => strlen($text),
            'review_policy' => [
                'skill_updates' => 'must pass agents:skill-audit before use',
                'memory_updates' => 'operator-reviewed memory note or existing PLOS memory path only',
                'tool_changes' => 'code review, tests, and MCP allowlist review required',
                'production_writes' => 'not allowed by this command',
            ],
            'proposals' => $writeReport
                ? [$this->proposal($actionable['type'], 'Review '.$actionable['target'].': '.$actionable['lesson'], $actionable['target'], $this->reviewGate($actionable['type']), $area)]
                : $this->proposals($text, $area),
        ];

        if ($writeReport) {
            $payload['actionable'] = [
                'lesson' => $actionable['lesson'],
                'evidence_ref' => $actionable['evidence_ref'],
                'target' => $actionable['target'],
                'proposal_type' => $actionable['type'],
            ];
            $payload['report_path'] = $this->writeReport($payload);
        }

        return $this->finish($payload);
    }

    /**
     * @return array{text:string,error:?string}
     */
    private function summaryInput(): array
    {
        if ((bool) $this->option('example')) {
            return [
                'text' => 'The offline model solved a Laravel command task after receiving a smaller context, but it missed the MCP allowlist until prompted. Add a skill reminder and a smoke test for new offline tools.',
                'error' => null,
            ];
        }

        $encoded = trim((string) ($this->option('summary-b64') ?: ''));
        if ($encoded !== '') {
            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                return ['text' => '', 'error' => '--summary-b64 is not valid base64.'];
            }

            return ['text' => trim($decoded), 'error' => trim($decoded) === '' ? 'Summary is required.' : null];
        }

        $summary = trim((string) ($this->option('summary') ?: ''));

        return ['text' => $summary, 'error' => $summary === '' ? 'Summary is required.' : null];
    }

    private function normalizeArea(string $area): string
    {
        $area = strtolower(trim($area));
        $area = preg_replace('/[^a-z0-9_-]/', '_', $area) ?: 'offline_dev';

        return mb_substr($area, 0, 80);
    }

    /** @return array{lesson:string,evidence_ref:string,target:string,type:string,error:?string} */
    private function actionableInput(): array
    {
        $lesson = trim((string) $this->option('lesson'));
        $evidenceRef = trim((string) $this->option('evidence-ref'));
        $target = trim((string) $this->option('target'));
        $type = trim((string) $this->option('proposal-type'));
        $validType = in_array($type, ['skill_update', 'memory_note', 'tool_surface', 'test_guard', 'prompt_candidate_eval'], true);
        $safeReference = static fn (string $value, int $max): bool => strlen($value) <= $max
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]{2,}$/', $value) === 1
            && ! str_contains($value, '..') && ! str_contains($value, '://');
        $validLesson = mb_strlen($lesson) >= 20 && mb_strlen($lesson) <= 300
            && ! preg_match('/(?:password|api[_-]?key|secret|bearer)\s*[:=]/i', $lesson);

        return [
            'lesson' => $lesson,
            'evidence_ref' => $evidenceRef,
            'target' => $target,
            'type' => $type,
            'error' => $validLesson && $safeReference($evidenceRef, 200) && $safeReference($target, 160) && $validType
                ? null : 'Saving a report requires a concrete privacy-safe --lesson (20-300 chars), --evidence-ref, --target, and one valid --proposal-type.',
        ];
    }

    private function reviewGate(string $type): string
    {
        return match ($type) {
            'skill_update' => 'agents:skill-audit before enabling',
            'memory_note' => 'operator review before persistence',
            'tool_surface' => 'read-only default, tests and MCP allowlist review',
            'test_guard' => 'focused local test pass before commit',
            'prompt_candidate_eval' => 'codex:offline-prompt-candidate-eval held-out qualification before operator review',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function proposals(string $summary, string $area): array
    {
        $lower = mb_strtolower($summary);
        $proposals = [];

        if (str_contains($lower, 'skill') || str_contains($lower, 'prompt') || str_contains($lower, 'workflow') || str_contains($lower, 'instruction')) {
            $proposals[] = $this->proposal(
                'skill_update',
                'Review whether an existing PLOS skill should capture this workflow',
                'resources/agents/skills or Codex skill review note',
                'agents:skill-audit --format=json before enabling',
                $area,
            );
        }

        if (str_contains($lower, 'prompt') || str_contains($lower, 'instruction')) {
            $proposals[] = $this->proposal(
                'prompt_candidate_eval',
                'Evaluate the instruction candidate against disjoint multi-metric held-out cases',
                'storage/app/offline-prompt-candidates hashes-only JSON artifact',
                'codex:offline-prompt-candidate-eval --artifact=<relative-path> --fail-on-ineligible before operator review',
                $area,
            );
        }

        if (str_contains($lower, 'remember') || str_contains($lower, 'lesson') || str_contains($lower, 'missed') || str_contains($lower, 'worked') || str_contains($lower, 'failed')) {
            $proposals[] = $this->proposal(
                'memory_note',
                'Create an operator-reviewed memory note from the task lesson',
                'PLOS/Codex memory note proposal',
                'operator review before persistence',
                $area,
            );
        }

        if (str_contains($lower, 'mcp') || str_contains($lower, 'tool') || str_contains($lower, 'command') || str_contains($lower, 'artisan')) {
            $proposals[] = $this->proposal(
                'tool_surface',
                'Check whether a safe MCP/artisan surface should expose this repeatable step',
                'PLOS MCP tool or exact artisan allowlist entry',
                'read-only default plus tests and allowlist review',
                $area,
            );
        }

        if (str_contains($lower, 'test') || str_contains($lower, 'smoke') || str_contains($lower, 'guard') || str_contains($lower, 'regression')) {
            $proposals[] = $this->proposal(
                'test_guard',
                'Add a focused smoke or regression test for the failure mode',
                'tests/Unit or tests/Feature',
                'local test pass before commit',
                $area,
            );
        }

        if (empty($proposals)) {
            $proposals[] = $this->proposal(
                'follow_up',
                'Triage this task lesson into the existing TODO or watchlist flow',
                'docs/TODO.md or existing monitor list',
                'operator review before scheduling',
                $area,
            );
        }

        return $proposals;
    }

    /**
     * @return array<string, mixed>
     */
    private function proposal(string $type, string $title, string $target, string $gate, string $area): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'area' => $area,
            'artifact_target' => $target,
            'review_gate' => $gate,
            'autonomous_write_allowed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeReport(array $payload): string
    {
        $directory = storage_path('app/offline-improvement-proposals');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.now()->format('Ymd_His').'_'.substr((string) ($payload['summary_sha256'] ?? 'proposal'), 0, 12).'.json';
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, int $exit = self::SUCCESS): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $this->line(($payload['schema'] ?? 'codex:offline-improvement').': '.($payload['status'] ?? 'unknown'));
        foreach ($payload['proposals'] ?? [] as $proposal) {
            $this->line('- '.$proposal['type'].': '.$proposal['title']);
        }
        if (isset($payload['error'])) {
            $this->error((string) $payload['error']);
        }

        return $exit;
    }
}
