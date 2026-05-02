<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $services = [
            // Phase 5 — P0 (highest impact)
            ['raptor', true, 1, 50000, 600, 0.50, '["hierarchical_summarize"]', 'Phase 5 — RaptorSummarizationService'],
            ['factcheck_pipeline', true, 1, 40000, 300, 0.50, '["evidence_chase","partition_map"]', 'Phase 5 — FactCheckPipelineService'],
            ['claim_decomposition', true, 1, 30000, 300, 0.50, '["partition_map"]', 'Phase 5 — ClaimDecompositionService'],
            ['multi_hop_verification', true, 1, 30000, 300, 0.50, '["evidence_chase"]', 'Phase 5 — MultiHopVerificationService'],
            ['universal_research', true, 1, 60000, 600, 0.50, '["partition_map","hierarchical_summarize"]', 'Phase 5 — UniversalResearchOrchestrator'],

            // Phase 6 — P1 (second wave)
            ['query_decomposition', true, 1, 20000, 120, 0.25, '["quality_gate_retry"]', 'Phase 6 — QueryDecompositionService'],
            ['batch_processor', true, 1, 40000, 300, 0.50, '["partition_map"]', 'Phase 6 — BatchProcessor node'],
            ['multi_agent_debate', true, 1, 40000, 300, 0.50, '["quality_gate_retry"]', 'Phase 6 — MultiAgentDebateService'],
            ['multi_persona_critique', true, 1, 40000, 300, 0.50, '["quality_gate_retry"]', 'Phase 6 — MultiPersonaCritiqueService'],
            ['kg_fact_verification', true, 1, 30000, 300, 0.50, '["evidence_chase"]', 'Phase 6 — KGFactVerificationService'],
            ['research_service', true, 1, 50000, 300, 0.50, '["partition_map"]', 'Phase 6 — ResearchService'],
            ['dynamic_source_discovery', true, 1, 30000, 300, 0.50, '["partition_map"]', 'Phase 6 — DynamicSourceDiscoveryService'],
            ['graph_search', true, 1, 30000, 300, 0.50, '["quality_gate_retry"]', 'Phase 6 — GraphSearchService'],
            ['community_reports', true, 1, 50000, 600, 0.50, '["hierarchical_summarize"]', 'Phase 6 — CommunityReportService'],

            // Phase 7 — P2/P3 (third wave)
            ['genealogy-researcher', true, 1, 50000, 600, 0.50, '["partition_map","evidence_chase"]', 'Phase 7 — genealogy-researcher agent'],
            ['knowledge_graph', true, 1, 40000, 300, 0.50, '["partition_map"]', 'Phase 7 — KnowledgeGraphService'],
            ['semantic_chunker', true, 1, 20000, 120, 0.25, '["quality_gate_retry"]', 'Phase 7 — SemanticChunkerService'],
            ['web_research', true, 1, 40000, 300, 0.50, '["partition_map"]', 'Phase 7 — WebResearchService'],
            ['crag', true, 1, 30000, 300, 0.50, '["quality_gate_retry"]', 'Phase 7 — CRAGService'],
            ['lazy_graph_rag', true, 1, 30000, 300, 0.50, '["quality_gate_retry"]', 'Phase 7 — LazyGraphRAGService'],
            ['hype', true, 1, 20000, 120, 0.25, '["quality_gate_retry"]', 'Phase 7 — HyPEService'],
            ['llm_knowledge_vetting', true, 1, 40000, 300, 0.50, '["evidence_chase"]', 'Phase 7 — LLMKnowledgeVettingService'],
            ['hyper_graph', true, 1, 30000, 300, 0.50, '["quality_gate_retry"]', 'Phase 7 — HyperGraphService'],
            ['broker_discovery', true, 1, 30000, 300, 0.50, '["partition_map"]', 'Phase 7 — BrokerDiscoveryService'],
            ['sem_dedup', true, 1, 20000, 120, 0.25, '["quality_gate_retry"]', 'Phase 7 — SemDeDupService'],
        ];

        foreach ($services as $s) {
            DB::insert(
                "INSERT IGNORE INTO recursion_config (service_name, enabled, max_depth, max_tokens, max_time_seconds, max_cost_usd, strategies, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                $s
            );
        }
    }

    public function down(): void
    {
        $names = [
            'raptor', 'factcheck_pipeline', 'claim_decomposition', 'multi_hop_verification', 'universal_research',
            'query_decomposition', 'batch_processor', 'multi_agent_debate', 'multi_persona_critique',
            'kg_fact_verification', 'research_service', 'dynamic_source_discovery', 'graph_search', 'community_reports',
            'genealogy-researcher', 'knowledge_graph', 'semantic_chunker', 'web_research', 'crag',
            'lazy_graph_rag', 'hype', 'llm_knowledge_vetting', 'hyper_graph', 'broker_discovery', 'sem_dedup',
        ];
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        DB::delete("DELETE FROM recursion_config WHERE service_name IN ({$placeholders})", $names);
    }
};
