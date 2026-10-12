<?php

namespace App\Console\Commands;

use App\Services\ColibriResearchPolicyService;
use App\Services\ColibriResearchSidecarService;
use Illuminate\Console\Command;

class ColibriResearchCommand extends Command
{
    protected $signature = 'ai:colibri-research
        {question? : Public-information research question}
        {--question-b64= : Base64-encoded public-information question for local MCP transport}
        {--execute : Run the operator-selected Colibri canary}
        {--public-web : Confirm that the question may be sent to public web-search engines}
        {--local-offline : Use only the local read-only PLOS RAG capability pack}
        {--max-iterations= : Lower the table-owned iteration ceiling}
        {--max-tool-calls= : Lower the table-owned tool-call ceiling}
        {--json : Emit JSON}
        {--compact : Emit compact JSON}';

    protected $description = 'Plan or run a fail-closed Colibri public-web or local-offline read-only research canary';

    public function handle(
        ColibriResearchPolicyService $policy,
        ColibriResearchSidecarService $sidecar,
    ): int {
        $publicWebConfirmed = (bool) $this->option('public-web');
        $localOffline = (bool) $this->option('local-offline');
        if ($publicWebConfirmed && $localOffline) {
            return $this->render([
                'schema' => ColibriResearchSidecarService::SCHEMA,
                'success' => false,
                'status' => 'blocked',
                'error_code' => 'capability_pack_flags_conflict',
                'writes_performed' => false,
                'fallback_suppressed' => true,
            ], self::FAILURE);
        }
        $capabilityPack = $localOffline
            ? ColibriResearchPolicyService::LOCAL_OFFLINE_PACK
            : ColibriResearchPolicyService::PUBLIC_WEB_PACK;
        if (! (bool) $this->option('execute')) {
            $payload = $policy->plan($publicWebConfirmed, $capabilityPack);
            $payload['mode'] = 'plan';
            $payload['network_performed'] = false;
            $payload['model_invoked'] = false;

            return $this->render($payload, self::SUCCESS);
        }

        $question = $this->researchQuestion();
        if ($question === null) {
            return $this->render([
                'schema' => ColibriResearchSidecarService::SCHEMA,
                'success' => false,
                'status' => 'blocked',
                'error_code' => 'question_transport_invalid',
                'writes_performed' => false,
                'fallback_suppressed' => true,
            ], self::FAILURE);
        }
        if ($question === '') {
            return $this->render([
                'schema' => ColibriResearchSidecarService::SCHEMA,
                'success' => false,
                'status' => 'blocked',
                'error_code' => 'question_required_for_execution',
                'writes_performed' => false,
                'fallback_suppressed' => true,
            ], self::FAILURE);
        }
        if (! $localOffline && ! $publicWebConfirmed) {
            return $this->render([
                'schema' => ColibriResearchSidecarService::SCHEMA,
                'success' => false,
                'status' => 'blocked',
                'error_code' => 'public_web_confirmation_required',
                'writes_performed' => false,
                'fallback_suppressed' => true,
            ], self::FAILURE);
        }

        $options = [
            'public_web_confirmed' => $publicWebConfirmed,
            'capability_pack' => $capabilityPack,
            'sidecar_depth' => 0,
        ];
        if (is_numeric($this->option('max-iterations'))) {
            $options['max_iterations'] = (int) $this->option('max-iterations');
        }
        if (is_numeric($this->option('max-tool-calls'))) {
            $options['max_tool_calls'] = (int) $this->option('max-tool-calls');
        }
        $payload = $sidecar->research($question, $options);

        return $this->render($payload, ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE);
    }

    private function researchQuestion(): ?string
    {
        $plain = trim((string) ($this->argument('question') ?? ''));
        $encoded = trim((string) ($this->option('question-b64') ?? ''));
        if ($plain !== '' && $encoded !== '') {
            return null;
        }
        if ($encoded === '') {
            return $plain;
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || ! mb_check_encoding($decoded, 'UTF-8')) {
            return null;
        }

        return trim($decoded);
    }

    /** @param array<string,mixed> $payload */
    private function render(array $payload, int $exit): int
    {
        if ((bool) $this->option('json')) {
            $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
            if (! (bool) $this->option('compact')) {
                $flags |= JSON_PRETTY_PRINT;
            }
            $this->line(json_encode($payload, $flags));

            return $exit;
        }

        if (($payload['mode'] ?? null) === 'plan') {
            $this->info(sprintf(
                'Colibri research canary: %s; execution=%s; tools=%d; writes=false',
                (string) ($payload['status'] ?? 'unknown'),
                ($payload['execution_allowed'] ?? false) ? 'allowed' : 'blocked',
                (int) ($payload['tool_count'] ?? 0),
            ));

            return $exit;
        }
        if (($payload['success'] ?? false) === true) {
            $this->line((string) ($payload['answer'] ?? ''));
            $this->newLine();
            $this->info(sprintf(
                'Colibri research completed: tools=%d citations=%d duration_ms=%d writes=false',
                (int) ($payload['tool_calls_made'] ?? 0),
                (int) ($payload['citation_count'] ?? 0),
                (int) ($payload['duration_ms'] ?? 0),
            ));

            return $exit;
        }

        $this->error('Colibri research blocked: '.(string) ($payload['error_code'] ?? 'unknown'));

        return $exit;
    }
}
