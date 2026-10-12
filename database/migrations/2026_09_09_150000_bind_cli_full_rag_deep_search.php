<?php

use App\Services\RAGService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->change(true);
    }

    public function down(): void
    {
        // Retain the corrected table contract and all stored documents.
        $this->change(false);
    }

    private function change(bool $install): void
    {
        $changed = DB::transaction(function () use ($install): bool {
            $config = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($config === null) {
                return false;
            }
            $policy = json_decode($config->config_value, true, flags: JSON_THROW_ON_ERROR);
            $before = $policy;
            $rule = ['target' => 'workflow', 'tree_scoped' => false, 'references' => []];
            if ($install) {
                $tool = DB::table('agent_tool_registry')->where('name', 'rag_deep_search')->where('service_class', RAGService::class)
                    ->where('method', 'deepSearch')->lockForUpdate()->first();
                if ($tool === null) {
                    return false;
                }
                $parameters = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
                $original = $parameters;
                if (isset($parameters['type']) || ! isset($parameters['query'], $parameters['limit'])) {
                    return false;
                }
                // Existing parameter aliases retain model-facing `limit` and
                // its operator defaults while supplying the PHP topN argument.
                $parameters += [
                    'top_n' => ['type' => 'integer', 'x_context_only' => true, 'x_context_alias' => 'limit'],
                    'max_content_per_doc' => ['type' => 'integer', 'default' => null, 'x_context_only' => true,
                        'description' => 'Null retains full retrieved document content.'],
                    'decompose_query' => ['type' => 'boolean', 'default' => false, 'x_context_only' => true,
                        'description' => 'Keep the original query unless the operator explicitly selects decomposition.'],
                ];
                if ($parameters !== $original && DB::table('agent_tool_registry')->where('id', $tool->id)
                    ->whereRaw('CAST(parameters AS CHAR) = ?', [$tool->parameters])
                    ->update(['parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
                    throw new RuntimeException('Deep-search contract changed during migration.');
                }
                if (! array_key_exists('rag_deep_search', $policy['tools'])) {
                    $policy['tools']['rag_deep_search'] = $rule;
                }
            } elseif (($policy['tools']['rag_deep_search'] ?? null) === $rule) {
                unset($policy['tools']['rag_deep_search']);
            }
            if ($policy === $before) {
                return false;
            }
            if (DB::table('system_configs')->where('id', $config->id)->where('config_value', $config->config_value)
                ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Deep-search binding changed during migration.');
            }

            return true;
        });
        if ($changed) {
            app(SystemConfigService::class)->forget('cli.framework_services');
        }
    }
};
