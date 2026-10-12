<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('llm_instances')->where('instance_id', 'colibri_87')->lockForUpdate()->first();
            if ($row !== null) {
                $config = json_decode($row->config, true, flags: JSON_THROW_ON_ERROR);
                $runtime = $config['cli_agent_runtime'] ?? [];
                if (($runtime['model'] ?? null) === 'glm-5.2-colibri'
                    && ($runtime['controller_target'] ?? null) === 'glm52_87'
                    && ($runtime['max_output_tokens'] ?? null) === 256) {
                    $config['cli_agent_runtime']['max_output_tokens'] = 2048;
                    $config['cli_agent_runtime']['temperature'] = 0;
                    DB::table('llm_instances')->where('id', $row->id)->update([
                        'config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    ]);
                }
            }

            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('offline-assistant');
            if ($skill === null) {
                throw new RuntimeException('The deployed CLI assistant skill is unavailable.');
            }
            app(SkillVersionService::class)->trackVersion('offline-assistant', $skill['frontmatter'], $skill['body'], $skill['full_content']);
            DB::table('system_configs')->insertOrIgnore([
                'section' => 'cli', 'config_key' => 'default_skill', 'config_value' => 'offline-assistant',
                'data_type' => 'string', 'description' => 'CLI startup skill when neither agent nor skill is selected. Explicit selections and saved/session overrides take precedence.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('system_configs')->insertOrIgnore([
                'section' => 'cli', 'config_key' => 'policy_profile', 'config_value' => 'hybrid_dev_assist',
                'data_type' => 'string', 'description' => 'Existing PLOS policy used by CLI sessions and their scoped workflow calls. Explicit routing.active_profile overrides take precedence; global routing stays unchanged.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'workflow_services')->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Existing CLI workflow service bindings are required.');
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            // The existing MCP router API and operation-target selector provide
            // the transport. Preserve operator server/endpoint definitions.
            if (! array_key_exists('searxng', $policy['servers'] ?? [])) {
                $policy['servers']['searxng'] = ['exclude_tools' => ['searxng_reset_circuit'], 'tree_scoped' => false];
                DB::table('system_configs')->where('id', $row->id)->update([
                    'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Keep complete skill versions and operator settings. An explicit
        // table change can select another startup skill or output allowance.
    }
};
