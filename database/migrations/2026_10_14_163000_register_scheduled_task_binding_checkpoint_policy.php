<?php

use App\Console\Commands\FileEnrichmentCommand;
use App\Services\AIAutoTagService;
use App\Services\AIService;
use App\Services\ContentExtractionProvenanceService;
use App\Services\LlmRoutePolicyService;
use App\Services\ModelHostControllerResourcePolicyService;
use App\Services\NextcloudFileApiService;
use App\Services\ScheduledJobService;
use App\Services\UnifiedCli\CliOllamaRuntimePolicy;
use App\Services\UnifiedCli\CliWorkflowModelSelection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }
        DB::table('system_configs')->insertOrIgnore([
            'section' => 'scheduler', 'config_key' => 'unknown_task_binding_checkpoint_policy',
            'data_type' => 'json', 'config_value' => json_encode([
                'schema' => 'plos.scheduled_task_binding_checkpoint_policy.v1', 'enabled' => true,
                'bindings' => ['file_ai_analysis' => [
                    'command' => 'files:enrich --type=ai --limit=1',
                    'command_class' => FileEnrichmentCommand::class,
                    'selection_key' => 'files.ai_auto_tag_vision_model_selection', 'role' => 'vision',
                    'native_tools_configured' => false, 'required_input_modalities' => ['text', 'image'],
                    'required_source_classes' => [ScheduledJobService::class, AIAutoTagService::class,
                        AIService::class, NextcloudFileApiService::class,
                        ContentExtractionProvenanceService::class,
                        CliWorkflowModelSelection::class,
                        CliOllamaRuntimePolicy::class,
                        LlmRoutePolicyService::class,
                        ModelHostControllerResourcePolicyService::class],
                ]],
            ], JSON_THROW_ON_ERROR),
            'description' => 'Narrow current native task-binding checkpoint after independently proved physical UNKNOWN closure. No release, transport, inference or historical replay authority.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Retain operator policy and UNKNOWN history; source rollback holds this checkpoint type.
    }
};
