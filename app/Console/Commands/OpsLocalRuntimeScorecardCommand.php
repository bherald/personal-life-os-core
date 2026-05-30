<?php

namespace App\Console\Commands;

use App\Services\Ops\LocalRuntimeScorecardService;
use Illuminate\Console\Command;

class OpsLocalRuntimeScorecardCommand extends Command
{
    protected $signature = 'ops:local-runtime-scorecard
        {--json : Emit machine-readable JSON}
        {--compact : Emit redacted compact JSON; requires --json}';

    protected $description = 'Observe-only local/runtime LLM scorecard for privacy posture, routing readiness, and Ollama eval coverage';

    public function handle(LocalRuntimeScorecardService $service): int
    {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('Use --compact with --json.');

            return self::INVALID;
        }

        $payload = $service->collect();

        if ($this->option('json')) {
            $jsonPayload = $this->option('compact') ? $service->compactPayload($payload) : $payload;
            $json = json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode local runtime scorecard JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $this->renderText($payload);

        return self::SUCCESS;
    }

    private function renderText(array $payload): void
    {
        $summary = (array) ($payload['summary'] ?? []);
        $privacy = (array) ($payload['privacy_posture'] ?? []);

        $this->line(sprintf(
            'local runtime scorecard: %s active=%d local_ready=%d external_active=%d codex_private=%d issues=%d',
            strtoupper((string) ($payload['status'] ?? 'unknown')),
            (int) ($summary['active'] ?? 0),
            (int) ($summary['active_local_allowed_healthy'] ?? 0),
            (int) ($summary['active_external'] ?? 0),
            (int) ($summary['active_codex_private_allowed'] ?? 0),
            count((array) ($payload['issues'] ?? [])),
        ));

        $mode = (array) ($payload['mode'] ?? []);
        $this->line(sprintf(
            'mode: read_only=%s no_write=%s network_probes_executed=%s external_llm_invoked=%s routing_change_allowed=%s private_data_shared=%s',
            $this->boolText($mode['read_only'] ?? false),
            $this->boolText($mode['no_write'] ?? false),
            $this->boolText($mode['network_probes_executed'] ?? true),
            $this->boolText($mode['external_llm_invoked'] ?? true),
            $this->boolText($mode['routing_change_allowed'] ?? true),
            $this->boolText($mode['private_data_shared'] ?? true),
        ));

        $this->line(sprintf(
            'privacy: reviewed_active=%d unreviewed_active=%d public_external_routable=%d private_external_non_codex=%d privacy_unknown=%d',
            (int) ($privacy['active_privacy_reviewed'] ?? 0),
            (int) ($privacy['active_privacy_unreviewed'] ?? 0),
            (int) ($summary['public_external_routable_active'] ?? 0),
            (int) ($summary['private_external_non_codex_active'] ?? 0),
            (int) ($summary['active_external_privacy_unknown'] ?? 0),
        ));

        foreach ((array) ($payload['issues'] ?? []) as $issue) {
            $this->warn(sprintf(
                'issue=%s severity=%s count=%d',
                (string) ($issue['code'] ?? 'unknown'),
                (string) ($issue['severity'] ?? 'watch'),
                (int) ($issue['count'] ?? 1),
            ));
        }

        $this->line('next commands:');
        foreach ((array) ($payload['next_commands'] ?? []) as $command) {
            $this->line('  '.$command);
        }
    }

    private function boolText(mixed $value): string
    {
        return $value ? 'true' : 'false';
    }
}
