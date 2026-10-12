<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use App\Services\SystemConfigService;
use App\Services\TypeSafeJevDecisionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TOOL_NAME = 'typesafe_jev_decide';

    public function policy(): array
    {
        $tasks = [];
        foreach ([
            'cli_task_skill_selection' => 'Cross-cutting PLOS task and skill suggestion; advisory only.',
            'todo_triage' => 'Classify and prioritize sanitized PLOS work-list items for operator review.',
            'social_state_classification' => 'Classify sanitized social-media project state; never publish or send.',
            'evidence_relevance' => 'Rank supplied retrieved evidence candidates; preserve provenance and source identity.',
            'claim_support' => 'Assess a claim against supplied evidence; uncertainty returns to review.',
            'genealogy_queue_triage' => 'Prioritize sanitized genealogy research tasks for source-backed work; no identity decisions.',
            'pipeline_issue_triage' => 'Classify sanitized AI or workflow symptoms to select the existing diagnostic path.',
        ] as $task => $description) {
            $tasks[$task] = [
                'enabled' => true,
                'schema_version' => 'v1',
                'description' => $description,
                'allowed_data_classifications' => ['public', 'sanitized_internal'],
            ];
        }

        return [
            'schema' => 'plos.typesafe_jev_policy.v1',
            'enabled' => true,
            'endpoint' => 'https://api.typesafe.ai/v1/systemone',
            'model' => 'jev-1.13.0',
            'connect_timeout_seconds' => 2,
            'timeout_seconds' => 8,
            'max_retries' => 1,
            'max_request_bytes' => 32768,
            'circuit_failure_threshold' => 3,
            'circuit_cooldown_seconds' => 60,
            'allowed_data_classifications' => ['public', 'sanitized_internal'],
            'tasks' => $tasks,
            'fallback' => 'continue_existing_route',
        ];
    }

    public function tool(): array
    {
        return [
            'name' => self::TOOL_NAME,
            'service_class' => TypeSafeJevDecisionService::class,
            'method' => 'evaluate',
            'description' => 'Use TypeSafe Jev for online, advisory typed judgments that can speed PLOS work: task/skill selection, sanitized work-item or Genea queue triage, AI/workflow issue triage, social-state classification, ranking retrieved evidence, or comparing a claim with supplied evidence. Build System One questions with unique IDs and type=noul, choice, or score; each needs clear instructions, and choice/score need criteria. Put only the minimum public or sanitized_internal context in state. Read the typed answers/probabilities/confidence; apply explicit code policy and human review, never treat a score as fact or permission. On status=skipped, continue the existing route. Do not use for generation, deterministic facts, private raw data, Genea identity, publishing, or production actions.',
            'parameters' => json_encode([
                'type' => 'object',
                'properties' => [
                    'task_type' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,79}$', 'minLength' => 1, 'maxLength' => 80],
                    'data_classification' => ['type' => 'string', 'enum' => ['public', 'sanitized_internal']],
                    'state' => ['type' => 'object', 'additionalProperties' => true],
                    'questions' => ['type' => 'object', 'minProperties' => 1, 'maxProperties' => 8, 'additionalProperties' => true],
                ],
                'required' => ['task_type', 'data_classification', 'state', 'questions'],
                'additionalProperties' => false,
            ], JSON_THROW_ON_ERROR),
            'returns_description' => 'Validated typed answer map with choice/noul/score fields and probabilities/confidence where applicable, model, token usage, latency, and route trace id; otherwise status=skipped with a safe reason and continue_existing_route fallback.',
            'permissions' => json_encode([], JSON_THROW_ON_ERROR),
            'enabled' => 1,
            'risk_level' => 'read',
            'category' => 'ai_judgment',
            'requires_confirmation' => 0,
            'max_calls_per_run' => 1,
            'source' => 'manual',
            'notes' => 'typesafe-jev-v1: optional external typed API; per-task system_configs policy and existing internet/offline gates; only public/sanitized advisory inputs; no secrets, private raw data, or automatic actions.',
            'availability_status' => 'available',
            'privacy_class' => 'online_sanitized_input',
            'allows_private_data' => 0,
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs') || ! Schema::hasTable('agent_tool_registry') || ! Schema::hasTable('skill_versions')) {
            throw new RuntimeException('TypeSafe Jev registration requires the PLOS system and tool registries.');
        }

        DB::transaction(function (): void {
            if (! DB::table('system_configs')->where('section', 'ai')->where('config_key', 'typesafe_jev_policy')->exists()) {
                DB::table('system_configs')->insert([
                    'section' => 'ai',
                    'config_key' => 'typesafe_jev_policy',
                    'config_value' => json_encode($this->policy(), JSON_THROW_ON_ERROR),
                    'data_type' => 'json',
                    'description' => 'Table-controlled optional TypeSafe Jev typed-judgment route; offline and provider failures skip to existing PLOS behavior.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $definition = $this->tool();
            $existingTool = DB::table('agent_tool_registry')->where('name', self::TOOL_NAME)->first();
            if ($existingTool === null) {
                $columns = array_flip(Schema::getColumnListing('agent_tool_registry'));
                $row = array_intersect_key($definition, $columns) + [
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                DB::table('agent_tool_registry')->insert($row);
            } elseif (($existingTool->service_class ?? null) !== $definition['service_class']
                || ($existingTool->method ?? null) !== $definition['method']) {
                throw new RuntimeException('The Jev tool name is already registered to a different service.');
            }

            foreach (['offline-assistant', 'ai-ops', 'research-analyst', 'genealogy-researcher', 'workflow-ops'] as $skillName) {
                $skill = app(SkillLoaderService::class)->loadSkillSnapshot($skillName);
                if ($skill === null) {
                    throw new RuntimeException("PLOS Jev-enabled skill '{$skillName}' is unavailable.");
                }
                app(SkillVersionService::class)->trackVersion($skillName, $skill['frontmatter'], $skill['body'], $skill['full_content']);
            }
        });

        app(SystemConfigService::class)->forget(TypeSafeJevDecisionService::POLICY_KEY);
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_tool_registry')) {
            $definition = $this->tool();
            $row = DB::table('agent_tool_registry')->where('name', self::TOOL_NAME)->first();
            if ($row !== null
                && ($row->service_class ?? null) === $definition['service_class']
                && ($row->method ?? null) === $definition['method']
                && ($row->description ?? null) === $definition['description']) {
                DB::table('agent_tool_registry')->where('name', self::TOOL_NAME)->update(['enabled' => 0, 'updated_at' => now()]);
            }
        }

        if (Schema::hasTable('system_configs')) {
            $row = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'typesafe_jev_policy')->first();
            if ($row !== null) {
                $current = json_decode((string) $row->config_value, true);
                if ($current === $this->policy()) {
                    $current['enabled'] = false;
                    foreach ($current['tasks'] as &$task) {
                        $task['enabled'] = false;
                    }
                    unset($task);
                    DB::table('system_configs')->where('id', $row->id)->update([
                        'config_value' => json_encode($current, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        app(SystemConfigService::class)->forget(TypeSafeJevDecisionService::POLICY_KEY);
    }
};
