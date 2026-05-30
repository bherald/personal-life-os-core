<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PUBLIC_ONLY_FREE_PROVIDERS = [
        'mistral_free',
        'cerebras_free',
        'groq_free',
        'deepinfra_free',
        'openrouter_free',
        'gemini_free',
        'sambanova_free',
    ];

    public function up(): void
    {
        Schema::table('llm_instances', function (Blueprint $table) {
            if (! Schema::hasColumn('llm_instances', 'allows_private_data')) {
                $table->boolean('allows_private_data')
                    ->default(false)
                    ->after('is_censored');
            }

            if (! Schema::hasColumn('llm_instances', 'data_privacy_scope')) {
                $table->string('data_privacy_scope', 30)
                    ->default('public_only')
                    ->after('allows_private_data');
            }

            if (! Schema::hasColumn('llm_instances', 'privacy_reviewed_at')) {
                $table->timestamp('privacy_reviewed_at')
                    ->nullable()
                    ->after('data_privacy_scope');
            }

            if (! Schema::hasColumn('llm_instances', 'privacy_notes')) {
                $table->text('privacy_notes')
                    ->nullable()
                    ->after('privacy_reviewed_at');
            }
        });

        $now = now();

        DB::table('llm_instances')
            ->where('instance_type', 'ollama')
            ->update([
                'allows_private_data' => true,
                'data_privacy_scope' => 'local_private',
                'privacy_reviewed_at' => $now,
                'privacy_notes' => 'Local Ollama provider on approved PLOS infrastructure; private data allowed.',
                'updated_at' => $now,
            ]);

        $this->markCodexPrivateAllowed($now);
        $this->markFreeExternalProvidersPublicOnly($now);

        DB::table('llm_instances')
            ->where('instance_id', 'claude_cli')
            ->delete();

        Cache::forget('llm_instances_all');
        Cache::forget('llm_instances_healthy');
        Cache::forget('external_api_providers');
        Cache::forget('llm_model_profiles');
    }

    public function down(): void
    {
        Schema::table('llm_instances', function (Blueprint $table) {
            foreach (['privacy_notes', 'privacy_reviewed_at', 'data_privacy_scope', 'allows_private_data'] as $column) {
                if (Schema::hasColumn('llm_instances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Cache::forget('llm_instances_all');
        Cache::forget('llm_instances_healthy');
        Cache::forget('external_api_providers');
        Cache::forget('llm_model_profiles');
    }

    private function markCodexPrivateAllowed(\Illuminate\Support\Carbon $now): void
    {
        $row = DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->first();

        if (! $row) {
            return;
        }

        $capabilities = $this->withoutPrivacyCapability($this->decodeJson($row->capabilities ?? '[]'));
        $config = $this->decodeJson($row->config ?? '{}');
        unset($config['sensitive_safe']);

        $supportedModels = array_values(array_filter(
            $this->decodeJson($row->supported_models ?? '[]'),
            fn ($model): bool => $model !== 'gpt-5.3-codex-spark'
        ));

        DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->update([
                'allows_private_data' => true,
                'data_privacy_scope' => 'private_allowed',
                'privacy_reviewed_at' => $now,
                'privacy_notes' => 'OpenAI Codex Exec is the approved external LLM partner for private PLOS/Genea/dev pipeline work.',
                'capabilities' => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'supported_models' => json_encode($supportedModels, JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ]);
    }

    private function markFreeExternalProvidersPublicOnly(\Illuminate\Support\Carbon $now): void
    {
        $rows = DB::table('llm_instances')
            ->whereIn('instance_id', self::PUBLIC_ONLY_FREE_PROVIDERS)
            ->get();

        foreach ($rows as $row) {
            $capabilities = $this->withoutPrivacyCapability($this->decodeJson($row->capabilities ?? '[]'));
            $config = $this->decodeJson($row->config ?? '{}');
            unset($config['sensitive_safe']);
            $config['privacy_scope'] = 'public_only';
            $config['notes'] = trim(((string) ($config['notes'] ?? '')).' Public/free provider: do not send private or sensitive PLOS data.');

            DB::table('llm_instances')
                ->where('instance_id', $row->instance_id)
                ->update([
                    'is_active' => false,
                    'routability' => 'bench_only',
                    'allows_private_data' => false,
                    'data_privacy_scope' => 'public_only',
                    'privacy_reviewed_at' => $now,
                    'privacy_notes' => 'External/free provider retained for public or benchmark-only use. The routing gate must not send private/sensitive PLOS data here.',
                    'capabilities' => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
                    'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ]);
        }
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function withoutPrivacyCapability(array $capabilities): array
    {
        if (array_is_list($capabilities)) {
            return array_values(array_filter(
                $capabilities,
                fn ($capability): bool => $capability !== 'sensitive_safe'
            ));
        }

        unset($capabilities['sensitive_safe']);

        return $capabilities;
    }
};
