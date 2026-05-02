<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->configureCybersecurityNewsBrief();
        $this->configureNewsBrief();
    }

    public function down(): void
    {
        $this->deleteConfigForWorkflow('Cybersecurity News Brief', ['RSSFeedReader', 'ResearchQuery'], [
            'max_total_articles',
            'max_formatted_chars',
        ]);

        $this->deleteConfigForWorkflow('news_brief', ['ParallelRSSFeedReader', 'ResearchQuery'], [
            'max_total_articles',
            'max_formatted_chars',
        ]);
    }

    private function configureCybersecurityNewsBrief(): void
    {
        $workflow = DB::table('workflows')
            ->where('name', 'Cybersecurity News Brief')
            ->first();

        if (!$workflow) {
            return;
        }

        $rssNodes = DB::table('workflow_nodes')
            ->where('workflow_id', $workflow->id)
            ->where('node_type', 'RSSFeedReader')
            ->orderBy('node_order')
            ->get();

        foreach ($rssNodes as $index => $node) {
            $order = $index + 1;
            $limit = $order <= 10 ? '3' : '0';

            $this->upsertNodeConfig((int) $node->id, 'limit', $limit);
            $this->upsertNodeConfig((int) $node->id, 'max_total_articles', '30');
            $this->upsertNodeConfig((int) $node->id, 'max_formatted_chars', '20000');
        }

        $researchNodeIds = DB::table('workflow_nodes')
            ->where('workflow_id', $workflow->id)
            ->where('node_type', 'ResearchQuery')
            ->pluck('id');

        foreach ($researchNodeIds as $nodeId) {
            $this->upsertNodeConfig((int) $nodeId, 'limit', '15');
            $this->upsertNodeConfig((int) $nodeId, 'max_total_articles', '30');
            $this->upsertNodeConfig((int) $nodeId, 'max_formatted_chars', '20000');
        }
    }

    private function configureNewsBrief(): void
    {
        $workflow = DB::table('workflows')
            ->where('name', 'news_brief')
            ->first();

        if (!$workflow) {
            return;
        }

        $parallelNodeIds = DB::table('workflow_nodes')
            ->where('workflow_id', $workflow->id)
            ->where('node_type', 'ParallelRSSFeedReader')
            ->pluck('id');

        foreach ($parallelNodeIds as $nodeId) {
            $this->upsertNodeConfig((int) $nodeId, 'max_total_articles', '40');
            $this->upsertNodeConfig((int) $nodeId, 'max_formatted_chars', '25000');
        }

        $researchNodeIds = DB::table('workflow_nodes')
            ->where('workflow_id', $workflow->id)
            ->where('node_type', 'ResearchQuery')
            ->pluck('id');

        foreach ($researchNodeIds as $nodeId) {
            $this->upsertNodeConfig((int) $nodeId, 'limit', '25');
            $this->upsertNodeConfig((int) $nodeId, 'max_total_articles', '40');
            $this->upsertNodeConfig((int) $nodeId, 'max_formatted_chars', '25000');
        }
    }

    private function upsertNodeConfig(int $nodeId, string $key, string $value): void
    {
        $existing = DB::table('workflow_node_configs')
            ->where('workflow_node_id', $nodeId)
            ->where('config_key', $key)
            ->first();

        if ($existing) {
            DB::table('workflow_node_configs')
                ->where('id', $existing->id)
                ->update(['config_value' => $value]);

            return;
        }

        DB::table('workflow_node_configs')->insert([
            'workflow_node_id' => $nodeId,
            'config_key' => $key,
            'config_value' => $value,
        ]);
    }

    private function deleteConfigForWorkflow(string $workflowName, array $nodeTypes, array $keys): void
    {
        $workflow = DB::table('workflows')
            ->where('name', $workflowName)
            ->first();

        if (!$workflow) {
            return;
        }

        $nodeIds = DB::table('workflow_nodes')
            ->where('workflow_id', $workflow->id)
            ->whereIn('node_type', $nodeTypes)
            ->pluck('id');

        if ($nodeIds->isEmpty()) {
            return;
        }

        DB::table('workflow_node_configs')
            ->whereIn('workflow_node_id', $nodeIds)
            ->whereIn('config_key', $keys)
            ->delete();
    }
};
