<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class WorkflowTemplateService
{
    public function createTemplate(string $name, ?string $description = null, ?string $category = null, ?int $workflowId = null): int
    {
        $definition = '{}';
        $nodes = '[]';
        $defaultConfig = null;

        if ($workflowId) {
            $workflow = DB::selectOne("SELECT * FROM workflows WHERE id = ?", [$workflowId]);
            if ($workflow) {
                $definition = json_encode([
                    'name' => $workflow->name,
                    'description' => $workflow->description ?? '',
                ]);

                $workflowNodes = DB::select(
                    "SELECT node_type, node_order
                     FROM workflow_nodes WHERE workflow_id = ? ORDER BY node_order ASC",
                    [$workflowId]
                );
                $nodes = json_encode($workflowNodes);

                $defaultConfig = $workflow->config ?? null;
            }
        }

        DB::insert(
            "INSERT INTO workflow_templates (name, description, category, template_definition, template_nodes, default_config, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$name, $description, $category, $definition, $nodes, $defaultConfig]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    public function instantiateTemplate(int $templateId, ?array $overrides = null): array
    {
        $template = DB::selectOne("SELECT * FROM workflow_templates WHERE id = ?", [$templateId]);
        if (!$template) {
            return ['success' => false, 'error' => 'Template not found'];
        }

        $definition = json_decode($template->template_definition, true) ?? [];
        $nodes = json_decode($template->template_nodes, true) ?? [];

        $workflowName = $overrides['name'] ?? $definition['name'] ?? $template->name;
        $workflowDesc = $overrides['description'] ?? $definition['description'] ?? $template->description;

        DB::insert(
            "INSERT INTO workflows (name, description, active, created_at, updated_at)
             VALUES (?, ?, 0, NOW(), NOW())",
            [$workflowName . ' (from template)', $workflowDesc]
        );

        $workflowId = (int) DB::getPdo()->lastInsertId();

        foreach ($nodes as $i => $node) {
            DB::insert(
                "INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at)
                 VALUES (?, ?, ?, NOW())",
                [
                    $workflowId,
                    $node['node_type'] ?? 'unknown',
                    $node['node_order'] ?? $i,
                ]
            );
        }

        // Increment usage count
        DB::update("UPDATE workflow_templates SET usage_count = usage_count + 1 WHERE id = ?", [$templateId]);

        Log::info('WorkflowTemplate: Instantiated', [
            'template_id' => $templateId,
            'workflow_id' => $workflowId,
            'nodes_created' => count($nodes),
        ]);

        return ['success' => true, 'workflow_id' => $workflowId, 'nodes_created' => count($nodes)];
    }

    public function listTemplates(?string $category = null): array
    {
        $params = [];
        $where = '';
        if ($category) {
            $where = 'WHERE category = ?';
            $params[] = $category;
        }

        return DB::select(
            "SELECT id, name, description, category, usage_count, created_at, updated_at
             FROM workflow_templates {$where} ORDER BY usage_count DESC, name ASC",
            $params
        );
    }

    public function getTemplate(int $id): ?object
    {
        return DB::selectOne("SELECT * FROM workflow_templates WHERE id = ?", [$id]) ?: null;
    }

    public function deleteTemplate(int $id): bool
    {
        return DB::delete("DELETE FROM workflow_templates WHERE id = ?", [$id]) > 0;
    }

    public function createSampleTemplates(): array
    {
        $samples = [
            [
                'name' => 'Data Pipeline',
                'description' => 'Extract, transform, and load data from external sources',
                'category' => 'data',
                'definition' => json_encode(['trigger_type' => 'scheduled']),
                'nodes' => json_encode([
                    ['node_type' => 'ContentExtraction', 'node_config' => '{}', 'sort_order' => 1],
                    ['node_type' => 'AI', 'node_config' => '{"prompt":"Summarize the extracted content"}', 'sort_order' => 2],
                    ['node_type' => 'JoplinWrite', 'node_config' => '{}', 'sort_order' => 3],
                ]),
            ],
            [
                'name' => 'Notification Alert',
                'description' => 'Monitor a condition and send notifications when triggered',
                'category' => 'notification',
                'definition' => json_encode(['trigger_type' => 'scheduled']),
                'nodes' => json_encode([
                    ['node_type' => 'Conditional', 'node_config' => '{}', 'sort_order' => 1],
                    ['node_type' => 'Pushover', 'node_config' => '{}', 'sort_order' => 2],
                ]),
            ],
            [
                'name' => 'Research Workflow',
                'description' => 'Research a topic, verify facts, and store results',
                'category' => 'research',
                'definition' => json_encode(['trigger_type' => 'manual']),
                'nodes' => json_encode([
                    ['node_type' => 'ResearchTopicRunner', 'node_config' => '{}', 'sort_order' => 1],
                    ['node_type' => 'AI', 'node_config' => '{"prompt":"Analyze and summarize findings"}', 'sort_order' => 2],
                    ['node_type' => 'RAGIndex', 'node_config' => '{}', 'sort_order' => 3],
                ]),
            ],
        ];

        $created = 0;
        foreach ($samples as $sample) {
            $existing = DB::selectOne("SELECT id FROM workflow_templates WHERE name = ?", [$sample['name']]);
            if (!$existing) {
                DB::insert(
                    "INSERT INTO workflow_templates (name, description, category, template_definition, template_nodes, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                    [$sample['name'], $sample['description'], $sample['category'], $sample['definition'], $sample['nodes']]
                );
                $created++;
            }
        }

        return ['created' => $created, 'total_samples' => count($samples)];
    }
}
