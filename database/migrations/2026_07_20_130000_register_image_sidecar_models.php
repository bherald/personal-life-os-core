<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MARKER = '2026_07_20_register_image_sidecar_models';

    /** @var list<string> */
    private const OWNED_COLUMNS = [
        'model_name',
        'description',
        'use_cases',
        'enabled',
        'status',
        'quality_rating',
        'capabilities',
        'requires_override',
        'vetted_at',
        'metadata',
        'notes',
        'updated_at',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            return;
        }

        $now = now();
        foreach ($this->profiles() as $profileName => $profile) {
            $query = DB::table('llm_model_profiles')->where('profile_name', $profileName);
            $existing = $query->first(self::OWNED_COLUMNS);
            $existingMetadata = $this->decodeJsonObject($existing->metadata ?? null);
            if (isset($existingMetadata['_migration_extensions'][self::MARKER])) {
                continue;
            }

            $metadata = $profile['metadata'];
            $metadata['_migration_extensions'][self::MARKER] = [
                'previous_profile' => $existing === null ? null : get_object_vars($existing),
            ];
            $values = [
                'model_name' => 'sidecar:image/'.$profile['model'],
                'description' => $profile['description'],
                'use_cases' => json_encode(['image_output', 'text_to_image', 'local_candidate'], JSON_UNESCAPED_SLASHES),
                'enabled' => 0,
                'status' => $profile['published'] ? 'candidate' : 'blocked',
                'quality_rating' => null,
                'capabilities' => json_encode(['text_input', 'image_output', 'text_to_image', 'local_candidate'], JSON_UNESCAPED_SLASHES),
                'requires_override' => 1,
                'vetted_at' => $now,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => $profile['notes'],
                'updated_at' => $now,
            ];
            if ($existing !== null) {
                $query->update($values);
            } else {
                DB::table('llm_model_profiles')->insert($values + [
                    'profile_name' => $profileName,
                    'created_at' => $now,
                ]);
            }
        }

        Cache::forget('llm_model_profiles');
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            return;
        }

        foreach ($this->profiles() as $profileName => $profile) {
            $query = DB::table('llm_model_profiles')->where('profile_name', $profileName);
            $current = $query->first(self::OWNED_COLUMNS);
            if ($current === null || ! $this->stillOwnsProfile($current, $profile)) {
                continue;
            }

            $metadata = $this->decodeJsonObject($current->metadata ?? null);
            $previous = $metadata['_migration_extensions'][self::MARKER]['previous_profile'] ?? null;
            if ($previous === null) {
                $query->delete();
            } elseif (is_array($previous)) {
                $query->update(array_intersect_key($previous, array_flip(self::OWNED_COLUMNS)));
            }
        }
        Cache::forget('llm_model_profiles');
    }

    /** @return array<string,array<string,mixed>> */
    private function profiles(): array
    {
        return [
            'media_output_image' => $this->profile(
                'sdxl-lightning',
                'Image Generation / Fast',
                'Fast local 1024px image generation using SDXL Lightning 4-step',
                'ByteDance/SDXL-Lightning',
                'c9a24f48e1c025556787b0c58dd67a091ece2e44',
                'CreativeML Open RAIL++-M',
                ['width' => 1024, 'height' => 1024, 'steps' => 4, 'guidance_scale' => 0.0],
                'https://huggingface.co/ByteDance/SDXL-Lightning',
                true,
                13.422,
                '4c13ace5f12043c18454111a268e15883edbc4574c541b455cd13889396c22aa'
            ),
            'media_output_image_quality' => $this->profile(
                'sdxl-base-1.0',
                'Image Generation / Quality',
                'Quality-oriented local 1024px image generation using SDXL Base 1.0',
                'stabilityai/stable-diffusion-xl-base-1.0',
                '462165984030d82259a11f4367a4eed129e94a7b',
                'CreativeML Open RAIL++-M',
                ['width' => 1024, 'height' => 1024, 'steps' => 30, 'guidance_scale' => 5.0],
                'https://arxiv.org/abs/2307.01952',
                true,
                29.125,
                'b8dba90382244cfa70095bebc1de900d8f2f5d3dd79a77957a6956f4b03d625b'
            ),
            'media_output_image_alternate' => $this->profile(
                'pixart-sigma-1024',
                'Image Generation / Alternate',
                'Alternate local 1024px image generation using PixArt Sigma XL 2',
                'PixArt-alpha/PixArt-Sigma-XL-2-1024-MS',
                'e102b3591cc82e97071b8b4cb90d834d0c487207',
                'CreativeML Open RAIL++-M',
                ['width' => 1024, 'height' => 1024, 'steps' => 20, 'guidance_scale' => 4.5],
                'https://arxiv.org/abs/2403.04692',
                true,
                40.859,
                '0e3a0c75b1931da86395edad0d8fb2c8e7c82d958536011e74e5457ecba174dc'
            ),
            'media_output_image_modern' => $this->profile(
                'flux2-klein-4b',
                'Image Generation / Modern Quality',
                'Modern local text-to-image candidate using FLUX.2 Klein 4B with CPU offload',
                'black-forest-labs/FLUX.2-klein-4B',
                'e7b7dc27f91deacad38e78976d1f2b499d76a294',
                'Apache-2.0',
                ['width' => 1024, 'height' => 1024, 'steps' => 4, 'guidance_scale' => 1.0],
                'https://huggingface.co/black-forest-labs/FLUX.2-klein-4B',
                true,
                42.782,
                '427e701fee56a472ee0971b6004f298ce00410db312bfc89eb8ea02480a11e99'
            ),
            'media_output_image_experimental' => $this->profile(
                'sana-sprint-1.6b',
                'Image Generation / Experimental',
                'Experimental local 1024px image generation using SANA-Sprint 1.6B',
                'Efficient-Large-Model/Sana_Sprint_1.6B_1024px_diffusers',
                'b3c9ce6f29ad4161a00fa58a62e476b9c75ca934',
                'NSCL-v2-custom plus Gemma terms',
                ['width' => 1024, 'height' => 1024, 'steps' => 2, 'guidance_scale' => 0.0],
                'https://arxiv.org/abs/2503.09641',
                true,
                16.125,
                'fb316839703d4c14a83799fefc3361b48348825437174a87842402ffc8148111',
                [
                    'operator_use_scope' => 'private-personal runtime approved 2026-07-20; research-purpose and Gemma use restrictions remain applicable',
                    'redistribution' => 'not authorized by this operator decision',
                    'terms' => [
                        'https://huggingface.co/Efficient-Large-Model/Sana_Sprint_1.6B_1024px_diffusers',
                        'https://ai.google.dev/gemma/terms',
                        'https://ai.google.dev/gemma/prohibited_use_policy',
                    ],
                ]
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function profile(
        string $model,
        string $category,
        string $description,
        string $source,
        string $revision,
        string $license,
        array $defaults,
        string $benchmark,
        bool $published,
        float $elapsedSeconds,
        string $sha256,
        array $additionalMetadata = []
    ): array {
        return [
            'model' => $model,
            'description' => $description,
            'published' => $published,
            'notes' => $model === 'sana-sprint-1.6b'
                ? '2026-07-20 operator override: private-personal runtime candidate; upstream research-purpose and Gemma restrictions remain, and redistribution is not approved.'
                : '2026-07-20 RTC: locally benchmarked on .87 and exposed as an operator-override candidate.',
            'metadata' => array_merge([
                'schema' => 'plos.image_generator_profile.v1',
                'category' => $category,
                'sidecar_model' => $model,
                'source' => $source,
                'revision' => $revision,
                'license' => $license,
                'defaults' => $defaults,
                'public_benchmark_reference' => $benchmark,
                'local_benchmark' => [
                    'host' => '.87',
                    'status' => 'pass',
                    'generated_at' => '2026-07-20T17:27:52Z',
                    'prompt_sha256' => '0aaf4eb3fd752902110194b0101117bcda18826fcf096fee98e389650cc4eccd',
                    'elapsed_seconds' => $elapsedSeconds,
                    'output_sha256' => $sha256,
                    'width' => 1024,
                    'height' => 1024,
                    'visual_review' => 'coherent_output',
                ],
                'published' => $published,
            ], $additionalMetadata),
        ];
    }

    /** @param array<string,mixed> $profile */
    private function stillOwnsProfile(object $current, array $profile): bool
    {
        $expected = [
            'model_name' => 'sidecar:image/'.$profile['model'],
            'description' => $profile['description'],
            'use_cases' => json_encode(['image_output', 'text_to_image', 'local_candidate'], JSON_UNESCAPED_SLASHES),
            'enabled' => 0,
            'status' => $profile['published'] ? 'candidate' : 'blocked',
            'quality_rating' => null,
            'capabilities' => json_encode(['text_input', 'image_output', 'text_to_image', 'local_candidate'], JSON_UNESCAPED_SLASHES),
            'requires_override' => 1,
            'notes' => $profile['notes'],
        ];
        foreach ($expected as $column => $value) {
            if (($current->{$column} ?? null) != $value) {
                return false;
            }
        }

        $metadata = $this->decodeJsonObject($current->metadata ?? null);
        if (! isset($metadata['_migration_extensions'][self::MARKER])) {
            return false;
        }
        unset($metadata['_migration_extensions'][self::MARKER]);
        if (($metadata['_migration_extensions'] ?? null) === []) {
            unset($metadata['_migration_extensions']);
        }

        return $this->normalizeJsonNumbers($metadata) === $this->normalizeJsonNumbers($profile['metadata']);
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeJsonNumbers(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeJsonNumbers($item), $value);
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return $value;
    }
};
