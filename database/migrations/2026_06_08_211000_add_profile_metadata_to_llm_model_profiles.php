<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            return;
        }

        Schema::table('llm_model_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_model_profiles', 'status')) {
                $table->string('status', 30)->default('active')->after('enabled')->index();
            }

            if (! Schema::hasColumn('llm_model_profiles', 'quality_rating')) {
                $table->unsignedTinyInteger('quality_rating')->nullable()->after('status')->index();
            }

            if (! Schema::hasColumn('llm_model_profiles', 'capabilities')) {
                $table->json('capabilities')->nullable()->after('quality_rating');
            }

            if (! Schema::hasColumn('llm_model_profiles', 'requires_override')) {
                $table->boolean('requires_override')->default(false)->after('capabilities')->index();
            }

            if (! Schema::hasColumn('llm_model_profiles', 'vetted_at')) {
                $table->timestamp('vetted_at')->nullable()->after('requires_override');
            }

            if (! Schema::hasColumn('llm_model_profiles', 'metadata')) {
                $table->json('metadata')->nullable()->after('vetted_at');
            }
        });

        $this->backfillProfileMetadata();
        Cache::forget('llm_model_profiles');
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            return;
        }

        Schema::table('llm_model_profiles', function (Blueprint $table): void {
            foreach (['metadata', 'vetted_at', 'requires_override', 'capabilities', 'quality_rating', 'status'] as $column) {
                if (Schema::hasColumn('llm_model_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Cache::forget('llm_model_profiles');
    }

    private function backfillProfileMetadata(): void
    {
        $profiles = DB::table('llm_model_profiles')->get();
        foreach ($profiles as $profile) {
            $model = DB::table('ollama_models')
                ->where('model_name', $profile->model_name)
                ->orderByDesc('is_available')
                ->orderByRaw('COALESCE(quality_rating, 0) DESC')
                ->orderBy('id')
                ->first();

            $useCases = $this->decodeList($profile->use_cases ?? null);
            $modelCapabilities = $this->decodeList($model->capabilities ?? null);
            $capabilities = array_values(array_unique(array_merge($modelCapabilities, array_filter(
                $useCases,
                fn (string $value): bool => $this->looksLikeCapability($value)
            ))));

            foreach (['text_input', 'text_output'] as $defaultCapability) {
                if (! in_array($defaultCapability, $capabilities, true)
                    && ($profile->profile_name ?? '') !== 'audio_transcription'
                ) {
                    $capabilities[] = $defaultCapability;
                }
            }

            $enabled = (int) ($profile->enabled ?? 0) === 1;
            $status = $enabled ? 'active' : ($model?->status ?? 'candidate');
            if (! $enabled
                && in_array('local_candidate', $capabilities, true)
                && ! in_array($status, ['unavailable', 'deprecated', 'blocked'], true)
            ) {
                $status = 'candidate';
            }

            DB::table('llm_model_profiles')
                ->where('id', $profile->id)
                ->update([
                    'status' => $status,
                    'quality_rating' => $model?->quality_rating,
                    'capabilities' => empty($capabilities) ? null : json_encode($capabilities, JSON_UNESCAPED_SLASHES),
                    'requires_override' => $enabled ? 0 : 1,
                    'vetted_at' => $model?->vetted_at,
                    'metadata' => json_encode([
                        'source' => 'migration_backfill',
                        'model_status' => $model?->status,
                        'model_available' => $model?->is_available !== null ? (bool) $model->is_available : null,
                    ], JSON_UNESCAPED_SLASHES),
                ]);
        }
    }

    /**
     * @return array<int,string>
     */
    private function decodeList(mixed $json): array
    {
        if (is_array($json)) {
            return array_values(array_unique(array_map('strval', $json)));
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded)
            ? array_values(array_unique(array_map('strval', $decoded)))
            : [];
    }

    private function looksLikeCapability(string $value): bool
    {
        return preg_match('/_(input|output)$/', $value) === 1
            || in_array($value, [
                'image_to_text',
                'audio_to_text',
                'video_to_text',
                'text_to_image',
                'generated_file_output',
                'ocr_text_extraction',
                'operator_uncensored',
                'private_uncensored_text',
                'tool_calling',
                'agentic_coding',
                'visual_reasoning',
                'visual_coding',
                'long_context',
                'local_candidate',
                'local_tool',
                'experimental',
            ], true);
    }
};
