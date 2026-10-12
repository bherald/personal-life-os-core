<?php

namespace App\Console\Commands;

use App\Services\RagTraceHygieneRuntimePolicyService;
use App\Services\RAGTracingService;
use Illuminate\Console\Command;

class RagTraceHygieneCommand extends Command
{
    protected $signature = 'rag:trace-hygiene
        {--limit= : Maximum legacy trace rows to process; defaults from system_configs}
        {--execute : Replace raw legacy query text with keyed fingerprints}
        {--confirm : Required with --execute}
        {--json : Emit machine-readable JSON}
        {--compact : Emit aggregate-only JSON; requires --json}';

    protected $description = 'Dry-run-first replacement of legacy raw RAG trace queries with HMAC fingerprints';

    public function handle(
        RAGTracingService $tracing,
        RagTraceHygieneRuntimePolicyService $runtimePolicy,
    ): int {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('The --compact option requires --json.');

            return self::FAILURE;
        }
        if ($this->option('execute') && ! $this->option('confirm')) {
            $this->error('Use --confirm with --execute to sanitize legacy trace rows.');

            return self::FAILURE;
        }

        try {
            $policy = $runtimePolicy->required();
            $limit = $runtimePolicy->resolveLimit($this->option('limit'), $policy);
            $payload = $tracing->sanitizeLegacyQueries(
                $limit,
                (bool) $this->option('execute'),
                (bool) $this->option('confirm'),
                $policy['batch_size'],
            );
        } catch (\Throwable $e) {
            $this->error('RAG trace hygiene failed: '.$e::class);

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $flags = $this->option('compact') ? JSON_UNESCAPED_SLASHES : JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
            $json = json_encode($payload, $flags);
            if ($json === false) {
                $this->error('Failed to encode RAG trace hygiene JSON.');

                return self::FAILURE;
            }
            $this->line($json);
        } else {
            $this->line(sprintf(
                'RAG trace hygiene: %s dry_run=%s eligible=%d selected=%d sanitized=%d remaining=%d',
                strtoupper((string) ($payload['status'] ?? 'unknown')),
                ($payload['dry_run'] ?? true) ? 'true' : 'false',
                (int) ($payload['eligible'] ?? 0),
                (int) ($payload['selected'] ?? 0),
                (int) ($payload['sanitized'] ?? 0),
                (int) ($payload['remaining'] ?? 0),
            ));
        }

        return self::SUCCESS;
    }
}
