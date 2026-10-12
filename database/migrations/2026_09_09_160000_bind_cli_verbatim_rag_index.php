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
        // Keep stored documents and the corrected shared tool contract.
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
                $tool = DB::table('agent_tool_registry')->where('name', 'rag_index')->where('service_class', RAGService::class)
                    ->where('method', 'indexDocument')->lockForUpdate()->first();
                if ($tool === null) {
                    return false;
                }
                $parameters = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
                $original = $parameters;
                if (isset($parameters['type']) || ! isset($parameters['content'], $parameters['title'], $parameters['document_type'])) {
                    return false;
                }
                if (! array_key_exists('default', $parameters['document_type'])) {
                    $parameters['document_type']['default'] = 'genealogy_research';
                }
                $parameters += [
                    'preserve_content' => ['type' => 'boolean', 'default' => true, 'x_context_only' => true],
                    'skip_dedup' => ['type' => 'boolean', 'default' => true, 'x_context_only' => true],
                    'options' => ['type' => 'object', 'x_context_only' => true, 'x_parameter_bundle' => ['preserve_content', 'skip_dedup']],
                ];
                if ($parameters !== $original && DB::table('agent_tool_registry')->where('id', $tool->id)
                    ->whereRaw('CAST(parameters AS CHAR) = ?', [$tool->parameters])
                    ->update(['parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
                    throw new RuntimeException('RAG index contract changed during migration.');
                }
                if (! array_key_exists('rag_index', $policy['tools'])) {
                    $policy['tools']['rag_index'] = $rule;
                }
            } elseif (($policy['tools']['rag_index'] ?? null) === $rule) {
                unset($policy['tools']['rag_index']);
            }
            if ($policy === $before) {
                return false;
            }
            if (DB::table('system_configs')->where('id', $config->id)->where('config_value', $config->config_value)
                ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('RAG index binding changed during migration.');
            }

            return true;
        });
        if ($changed) {
            app(SystemConfigService::class)->forget('cli.framework_services');
        }
    }
};
