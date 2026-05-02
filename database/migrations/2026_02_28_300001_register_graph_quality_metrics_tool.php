<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Register graph_quality_metrics agent tool
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, enabled, risk_level, category, source, notes, created_at, updated_at)
            VALUES
                ('graph_quality_metrics', 'App\\\\Services\\\\KnowledgeGraphService', 'getQualityMetrics',
                 'Measure knowledge graph quality: accuracy (sample triples vs source docs), freshness (stale triples), coverage (extraction completeness). Returns scores 0-1 for each dimension plus composite.',
                 '{\"sample_size\": {\"type\": \"integer\", \"required\": false, \"description\": \"Number of triples to sample for accuracy (default 50)\"}, \"persist\": {\"type\": \"boolean\", \"required\": false, \"description\": \"Save results to kg_quality_runs table (default false)\"}}',
                 '[]', 1, 'read', 'rag', 'manual',
                 'Read-only quality assessment. Accuracy samples random triples and checks if subject/object appear in source document. Freshness checks doc updates vs triple creation. Coverage checks extraction rate.',
                 NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                risk_level = VALUES(risk_level),
                notes = VALUES(notes),
                updated_at = NOW()
        ");

        // Register scheduled job: kg_quality_check, daily at 6 AM
        DB::statement("
            INSERT INTO scheduled_jobs
                (name, command, cron_expression, enabled, category, description, timeout_minutes, created_at, updated_at)
            VALUES
                ('kg_quality_check', 'graph:quality-metrics --run', '0 6 * * *', 1, 'Maintenance',
                 'Daily KG quality assessment — measures accuracy, freshness, coverage and persists to kg_quality_runs',
                 10, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                command = VALUES(command),
                cron_expression = VALUES(cron_expression),
                description = VALUES(description),
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM agent_tool_registry WHERE name = 'graph_quality_metrics'");
        DB::statement("DELETE FROM scheduled_jobs WHERE name = 'kg_quality_check'");
    }
};
