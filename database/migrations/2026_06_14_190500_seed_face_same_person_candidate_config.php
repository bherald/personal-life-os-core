<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<int, array{key: string, value: string, type: string, description: string}> */
    private array $configs = [
        [
            'key' => 'same_person_candidate_limit',
            'value' => '50',
            'type' => 'integer',
            'description' => 'Default maximum review candidates returned by ops:face-same-person-candidates when --limit is omitted.',
        ],
        [
            'key' => 'same_person_seed_limit',
            'value' => '50',
            'type' => 'integer',
            'description' => 'Default maximum provenance-trusted seed faces loaded for same-person candidate search when --seed-limit is omitted.',
        ],
        [
            'key' => 'same_person_scan_per_seed',
            'value' => '200',
            'type' => 'integer',
            'description' => 'Default maximum visual-neighbor rows scanned per trusted seed embedding when --scan-per-seed is omitted.',
        ],
        [
            'key' => 'same_person_min_similarity',
            'value' => '0.82',
            'type' => 'float',
            'description' => 'Minimum pgvector similarity for a face to be considered as a review-only same-person candidate.',
        ],
        [
            'key' => 'same_person_strong_similarity',
            'value' => '0.92',
            'type' => 'float',
            'description' => 'Similarity threshold for strong review-only same-person candidate bucket assignment.',
        ],
        [
            'key' => 'same_person_exemplar_similarity',
            'value' => '0.90',
            'type' => 'float',
            'description' => 'Seed-exemplar similarity threshold used to reinforce review-only same-person candidate scoring.',
        ],
    ];

    public function up(): void
    {
        foreach ($this->configs as $config) {
            $this->upsertConfig($config);
        }
    }

    public function down(): void
    {
        foreach ($this->configs as $config) {
            DB::delete(
                'DELETE FROM system_configs WHERE section = ? AND config_key = ? AND config_value = ?',
                ['faces', $config['key'], $config['value']]
            );
        }
    }

    /** @param array{key: string, value: string, type: string, description: string} $config */
    private function upsertConfig(array $config): void
    {
        $existing = DB::selectOne(
            'SELECT id FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
            ['faces', $config['key']]
        );

        if ($existing === null) {
            DB::insert(
                'INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                ['faces', $config['key'], $config['value'], $config['type'], $config['description']]
            );

            return;
        }

        DB::update(
            "UPDATE system_configs
             SET config_value = CASE WHEN config_value IS NULL OR config_value = '' THEN ? ELSE config_value END,
                 data_type = ?,
                 description = ?,
                 updated_at = NOW()
             WHERE section = ? AND config_key = ?",
            [$config['value'], $config['type'], $config['description'], 'faces', $config['key']]
        );
    }
};
