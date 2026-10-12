<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'colibri_87';

    private const MODEL = 'glm-5.2-colibri';

    private const PROFILE = 'deep_offline_colibri';

    private const APPROVAL_DECISION = 'operator_approved_2026_07_24';

    private const APPROVED_NOTES = 'Operator-approved GLM-5.2 Colibri deep-offline provider. Highest-capability local text lane with patient latency; text-only monitored SSE, one active request plus one queued request, no MCP tools, and no silent provider fallback.';

    private const CANDIDATE_NOTES = 'Operator-gated GLM-5.2 Colibri deep-offline candidate. Text-only monitored SSE, one active request plus one queued request, no MCP tools, and no silent provider fallback.';

    public function up(): void
    {
        $now = now();
        $this->promoteInstance($now);
        $this->promoteProfile($now);
        $this->promoteRegistry($now);
        $this->clearCaches();
    }

    public function down(): void
    {
        $this->restoreInstance();
        $this->restoreProfile();
        $this->restoreRegistry();
        $this->clearCaches();
    }

    private function promoteInstance(mixed $now): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }
        $instance = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if ($instance === null) {
            return;
        }
        $config = $this->decodeObject($instance->config ?? null);
        $routePolicy = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
        $routePolicy['mode'] = 'operator_approved_deep_offline';
        $routePolicy['allowed_model_roles'] = ['deep_offline'];
        $routePolicy['allow_generic_fallback'] = false;
        $routePolicy['require_model_role'] = true;
        $routePolicy['require_explicit_instance'] = true;
        $routePolicy['tools_enabled'] = false;
        $config['route_policy'] = $routePolicy;
        $config['operator_approval'] = [
            'decision' => self::APPROVAL_DECISION,
            'approved_at' => $now->toIso8601String(),
            'scope' => ['plos_ai_hub', 'offline_dev_assist'],
            'availability' => 'healthy_only',
        ];
        $config['ranking'] = $this->ranking();
        $config['notes'] = self::APPROVED_NOTES;

        DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->update(
            $this->filterColumns('llm_instances', [
                'compat_status' => 'authoritative',
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => self::APPROVED_NOTES,
                'updated_at' => $now,
            ])
        );
    }

    private function promoteProfile(mixed $now): void
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            return;
        }
        $profile = DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->first();
        if ($profile === null) {
            return;
        }
        $metadata = $this->decodeObject($profile->metadata ?? null);
        $metadata['operator_approval'] = [
            'decision' => self::APPROVAL_DECISION,
            'approved_at' => $now->toIso8601String(),
            'availability' => 'healthy_only',
        ];
        $metadata['ranking'] = $this->ranking();
        $metadata['ui'] = array_merge(
            is_array($metadata['ui'] ?? null) ? $metadata['ui'] : [],
            ['sort_order' => 25]
        );

        DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->update(
            $this->filterColumns('llm_model_profiles', [
                'description' => 'Operator-approved patient GLM-5.2 through the local Colibri runtime on .87',
                'use_cases' => json_encode([
                    'deep_offline_chat',
                    'patient_local_reasoning',
                    'difficult_synthesis',
                    'offline_plos_assistant',
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'enabled' => 1,
                'status' => 'active',
                'quality_rating' => 90,
                'capabilities' => json_encode([
                    'text_input',
                    'text_output',
                    'streaming',
                    'reasoning',
                    'local_private',
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'requires_override' => 0,
                'vetted_at' => $now,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => self::APPROVED_NOTES,
                'updated_at' => $now,
            ])
        );
    }

    private function promoteRegistry(mixed $now): void
    {
        if (! Schema::hasTable('ollama_models') || ! Schema::hasTable('llm_instances')) {
            return;
        }
        $instanceId = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->value('id');
        if ($instanceId === null) {
            return;
        }

        DB::table('ollama_models')
            ->where('instance_id', $instanceId)
            ->where('model_name', self::MODEL)
            ->update($this->filterColumns('ollama_models', [
                'status' => 'vetted',
                'is_available' => 1,
                'capabilities' => json_encode([
                    'text_input',
                    'text_output',
                    'streaming',
                    'reasoning',
                    'local_private',
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'use_cases' => json_encode([
                    'deep_offline_chat',
                    'patient_local_reasoning',
                    'difficult_synthesis',
                    'offline_plos_assistant',
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'description' => self::APPROVED_NOTES,
                'quality_rating' => 90,
                'vetting_notes' => self::APPROVED_NOTES,
                'vetted_at' => $now,
                'vetted_by' => self::APPROVAL_DECISION,
                'updated_at' => $now,
            ]));
    }

    private function restoreInstance(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }
        $instance = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if ($instance === null) {
            return;
        }
        $config = $this->decodeObject($instance->config ?? null);
        if (($config['operator_approval']['decision'] ?? null) !== self::APPROVAL_DECISION) {
            return;
        }
        unset($config['operator_approval'], $config['ranking']);
        $routePolicy = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
        $routePolicy['mode'] = 'narrow';
        $config['route_policy'] = $routePolicy;
        $config['notes'] = self::CANDIDATE_NOTES;

        DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->update(
            $this->filterColumns('llm_instances', [
                'compat_status' => 'provisional',
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => self::CANDIDATE_NOTES,
                'updated_at' => now(),
            ])
        );
    }

    private function restoreProfile(): void
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            return;
        }
        $profile = DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->first();
        if ($profile === null) {
            return;
        }
        $metadata = $this->decodeObject($profile->metadata ?? null);
        if (($metadata['operator_approval']['decision'] ?? null) !== self::APPROVAL_DECISION) {
            return;
        }
        unset($metadata['operator_approval'], $metadata['ranking']);
        if (($metadata['ui']['sort_order'] ?? null) === 25) {
            unset($metadata['ui']['sort_order']);
        }
        if (($metadata['ui'] ?? null) === []) {
            unset($metadata['ui']);
        }

        DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->update(
            $this->filterColumns('llm_model_profiles', [
                'description' => 'Patient local GLM-5.2 through the Colibri disk-streaming runtime on .87',
                'use_cases' => json_encode([
                    'deep_offline_chat',
                    'patient_local_reasoning',
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'enabled' => 1,
                'status' => 'testing',
                'quality_rating' => 90,
                'capabilities' => json_encode([
                    'text_input',
                    'text_output',
                    'streaming',
                    'local_candidate',
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'requires_override' => 1,
                'vetted_at' => null,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => self::CANDIDATE_NOTES,
                'updated_at' => now(),
            ])
        );
    }

    private function restoreRegistry(): void
    {
        if (! Schema::hasTable('ollama_models') || ! Schema::hasTable('llm_instances')) {
            return;
        }
        $instanceId = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->value('id');
        if ($instanceId === null) {
            return;
        }
        $model = DB::table('ollama_models')
            ->where('instance_id', $instanceId)
            ->where('model_name', self::MODEL)
            ->first();
        if ($model === null || (string) ($model->vetted_by ?? '') !== self::APPROVAL_DECISION) {
            return;
        }

        DB::table('ollama_models')
            ->where('instance_id', $instanceId)
            ->where('model_name', self::MODEL)
            ->update($this->filterColumns('ollama_models', [
                'status' => 'testing',
                'capabilities' => json_encode([
                    'text_input',
                    'text_output',
                    'streaming',
                    'local_candidate',
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'use_cases' => json_encode([
                    'deep_offline_chat',
                    'patient_local_reasoning',
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'description' => self::CANDIDATE_NOTES,
                'quality_rating' => 90,
                'vetting_notes' => self::CANDIDATE_NOTES,
                'vetted_at' => null,
                'vetted_by' => 'codex-candidate-smoke',
                'updated_at' => now(),
            ]));
    }

    /** @return array<string,mixed> */
    private function ranking(): array
    {
        return [
            'quality_rating' => 90,
            'capability_tier' => 'highest_local_text',
            'latency_tier' => 'patient',
            'relative_to_ollama' => 'higher_capability_lower_speed',
            'normal_chat_default' => false,
            'selection_policy' => 'operator_selected_when_healthy',
        ];
    }

    /** @return array<string,mixed> */
    private function decodeObject(mixed $value): array
    {
        $decoded = json_decode((string) ($value ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function filterColumns(string $table, array $payload): array
    {
        return array_filter(
            $payload,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function clearCaches(): void
    {
        foreach ([
            'llm_instances_all',
            'llm_instances_healthy',
            'external_api_providers',
            'llm_model_profiles',
            'ollama_available_models',
            'ollama_vetted_models',
        ] as $key) {
            Cache::forget($key);
        }
    }
};
