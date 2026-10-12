<?php

namespace App\Console\Commands;

use App\Services\RagSourceIdentityDedupRuntimePolicyService;
use App\Services\RagSourceIdentityDedupService;
use Illuminate\Console\Command;

class RagSourceIdentityDedupCommand extends Command
{
    protected $signature = 'rag:source-identity-dedup
        {--limit= : Maximum exact-content source groups; execution requires an explicit limit}
        {--execute : Consolidate selected exact-content groups}
        {--confirm : Required with --execute}
        {--json : Emit machine-readable JSON}
        {--compact : Emit compact JSON; requires --json}';

    protected $description = 'Audit or consolidate exact-content duplicate file RAG rows by stable source identity';

    public function handle(
        RagSourceIdentityDedupService $service,
        RagSourceIdentityDedupRuntimePolicyService $runtimePolicy,
    ): int {
        $execute = (bool) $this->option('execute');
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('The --compact option requires --json.');

            return self::FAILURE;
        }
        if ($execute && ! $this->option('confirm')) {
            $this->error('Use --confirm with --execute to consolidate exact-content source identities.');

            return self::FAILURE;
        }

        try {
            $policy = $runtimePolicy->required();
            $limit = $runtimePolicy->resolveLimit($this->option('limit'), $policy, $execute);
            $payload = $service->run($limit, $execute, $policy['lock_timeout_seconds']);
            $payload['policy_source'] = 'system_configs.'.RagSourceIdentityDedupRuntimePolicyService::CONFIG_KEY;
        } catch (\Throwable $e) {
            $this->error('RAG source-identity dedup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $flags = $this->option('compact')
                ? JSON_UNESCAPED_SLASHES
                : JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
            $this->line((string) json_encode($payload, $flags | JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf(
                'RAG source identity: %s selected=%d consolidated=%d removed=%d exact_remaining=%d divergent_untouched=%d',
                $payload['dry_run'] ? 'DRY RUN' : 'EXECUTED',
                $payload['selection']['selected_groups'],
                $payload['actions']['groups_consolidated'],
                $payload['actions']['documents_removed'],
                $payload['after']['exact_content_extra_documents'],
                $payload['after']['divergent_or_unproven_extra_documents'],
            ));
        }

        return self::SUCCESS;
    }
}
