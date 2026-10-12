<?php

use App\Services\SkillLoaderService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $inserted = DB::table('workflows')->insertOrIgnore(['name' => 'cyber_qualified_suite_checks', 'description' => 'Three exact approved synthetic security probes. No model calls, arbitrary targets, edits or finding closure. Qualify artifact and endpoint before activation.', 'active' => false, 'error_handling' => 'stop']);
            if ($inserted === 1) {
                $workflowId = (int) DB::table('workflows')->where('name', 'cyber_qualified_suite_checks')->value('id');
                foreach (['billing_scope', 'reference_lookup', 'profile_update'] as $index => $case) {
                    $nodeId = DB::table('workflow_nodes')->insertGetId(['workflow_id' => $workflowId, 'node_type' => 'Cyber\\QualifiedSuiteCheck', 'node_order' => $index + 1, 'timeout_seconds' => 45]);
                    DB::table('workflow_node_configs')->insert(['workflow_node_id' => $nodeId, 'config_key' => 'case_id', 'config_value' => $case]);
                }
            }
            $content = file_get_contents(SkillLoaderService::configuredSkillsBasePath().'/cyber-durable-workflows/SKILL.md');
            $parts = preg_split('/^---\s*$/m', $content, 3);
            $frontmatter = Yaml::parse($parts[1]);
            DB::table('skill_versions')->insertOrIgnore(['skill_name' => 'cyber-durable-workflows', 'version' => '1.0.0', 'content_hash' => hash('sha256', $content), 'frontmatter' => json_encode($frontmatter, JSON_THROW_ON_ERROR), 'body_text' => trim($parts[2]), 'full_content' => $content, 'change_summary' => 'Owner-bound durable synthetic CYBER jobs with existing CLI confirmation and scoped API.', 'changed_by' => 'migration', 'is_active' => true, 'tools_count' => 4, 'tool_phases_count' => 0, 'permissions' => json_encode($frontmatter['permissions'], JSON_THROW_ON_ERROR), 'created_at' => now()]);
        });
    }

    public function down(): void
    {
        // Do not discard workflow runs or receipts when retiring the integration.
        DB::table('workflows')->where('name', 'cyber_qualified_suite_checks')->update(['active' => false]);
        DB::table('skill_versions')->where('skill_name', 'cyber-durable-workflows')->where('content_hash', hash_file('sha256', SkillLoaderService::configuredSkillsBasePath().'/cyber-durable-workflows/SKILL.md'))->update(['is_active' => false]);
    }
};
