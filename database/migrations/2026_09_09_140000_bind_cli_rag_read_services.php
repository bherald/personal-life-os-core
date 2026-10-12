<?php

use App\Services\CommunityDetectionService;
use App\Services\KnowledgeGraphService;
use App\Services\RAGEvaluationService;
use App\Services\RAGService;
use App\Services\RaptorSummarizationService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SERVICES = [
        RAGService::class => ['rag_search' => 'search'],
        RaptorSummarizationService::class => ['raptor_get_pending' => 'getPendingDocuments', 'raptor_get_hierarchy' => 'getHierarchy'],
        RAGEvaluationService::class => ['rag_eval_stats' => 'getStats', 'rag_eval_history' => 'getEvaluationHistory'],
        KnowledgeGraphService::class => ['graph_entity_search' => 'searchEntities', 'graph_stats' => 'getStatistics',
            'graph_query_temporal' => 'findRelationshipsAsOf', 'graph_edge_history' => 'getEdgeHistory', 'graph_temporal_stats' => 'getTemporalStats'],
        CommunityDetectionService::class => ['graph_community_stats' => 'getStatistics', 'graph_community_report' => 'getCommunity'],
    ];

    public function up(): void
    {
        $this->change(true);
    }

    public function down(): void
    {
        // Keep corrected shared parameter contracts and all RAG/graph data.
        $this->change(false);
    }

    private function change(bool $install): void
    {
        $changed = DB::transaction(function () use ($install): bool {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $before = $policy;
            $rule = ['target' => 'workflow', 'tree_scoped' => false, 'references' => []];
            foreach (self::SERVICES as $class => $methods) {
                foreach ($methods as $name => $method) {
                    if (! $install) {
                        if (($policy['tools'][$name] ?? null) === $rule) {
                            unset($policy['tools'][$name]);
                        }

                        continue;
                    }
                    $tool = DB::table('agent_tool_registry')->where('name', $name)->where('service_class', $class)
                        ->where('method', $method)->lockForUpdate()->first();
                    if ($tool === null || ! $this->align($tool)) {
                        continue;
                    }
                    if (! array_key_exists($name, $policy['tools'])) {
                        $policy['tools'][$name] = $rule;
                    }
                }
            }
            if ($policy === $before) {
                return false;
            }
            if (DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('RAG service bindings changed during migration.');
            }

            return true;
        });
        if ($changed) {
            app(SystemConfigService::class)->forget('cli.framework_services');
        }
    }

    private function align(object $tool): bool
    {
        if (! in_array($tool->name, ['graph_query_temporal', 'graph_entity_search'], true)) {
            return true;
        }
        $parameters = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
        $before = $parameters;
        if (isset($parameters['type'])) {
            return false; // Operator-owned custom contract, not the legacy flat definition.
        }
        if ($tool->name === 'graph_query_temporal') {
            if (! isset($parameters['entity'], $parameters['as_of_date'], $parameters['direction'], $parameters['min_confidence'])) {
                return false;
            }
            if (! array_key_exists('options', $parameters)) {
                $parameters['options'] = ['type' => 'object', 'x_context_only' => true,
                    'x_parameter_bundle' => ['direction', 'min_confidence']];
            }
        } elseif (($parameters['options']['type'] ?? null) === 'array'
            && ($parameters['options']['default'] ?? null) === []
            && ! isset($parameters['options']['items']) && ! isset($parameters['options']['properties'])) {
            // PHP associative options are a JSON object, not a list. Preserve
            // custom schemas and use the method's [] default when omitted.
            $parameters['options']['type'] = 'object';
            unset($parameters['options']['default']);
            $parameters['options']['properties'] = ['types' => ['type' => 'array', 'items' => ['type' => 'string']],
                'limit' => ['type' => 'integer', 'minimum' => 1]];
        }
        if ($parameters !== $before && DB::table('agent_tool_registry')->where('id', $tool->id)
            ->whereRaw('CAST(parameters AS CHAR) = ?', [$tool->parameters])
            ->update(['parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
            throw new RuntimeException('RAG tool parameters changed during migration.');
        }

        return true;
    }
};
