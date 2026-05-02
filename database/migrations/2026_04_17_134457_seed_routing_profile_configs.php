<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed routing-profile config rows in system_configs (3b2 minimal slice).
 *
 * Introduces lightweight operator profiles that, in a follow-up slice, will
 * shape runtime routing (tool/MCP allowlists, capability filters) while
 * routing.offline_mode is enabled. This migration ONLY seeds the data rows;
 * no runtime enforcement is wired in this slice.
 *
 * Rows inserted (idempotent — skipped if the section+config_key pair exists):
 *   - routing.active_profile (string) — default | offline_review | offline_dev_assist
 *   - routing.profile.offline_review (json) — allowlist definition
 *   - routing.profile.offline_dev_assist (json) — allowlist definition
 *
 * See config/offline_policy.php and docs/AGENT-SAFETY-CARDS.md for the public
 * policy shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            [
                'section' => 'routing',
                'config_key' => 'active_profile',
                'config_value' => 'default',
                'data_type' => 'string',
                'description' => 'Active routing profile. default=no restrictions beyond offline_mode. Values: default | offline_review | offline_dev_assist.',
            ],
            [
                'section' => 'routing',
                'config_key' => 'profile.offline_review',
                'config_value' => json_encode([
                    'allowed_instance_types' => ['ollama'],
                    'allowed_capabilities' => ['text'],
                    'description' => 'Inspect, search, diff, explain, safe local validation. Local Ollama only.',
                ]),
                'data_type' => 'json',
                'description' => 'Profile definition for offline_review: read-only / inspect-only operator profile.',
            ],
            [
                'section' => 'routing',
                'config_key' => 'profile.offline_dev_assist',
                'config_value' => json_encode([
                    'allowed_instance_types' => ['ollama'],
                    'allowed_capabilities' => ['text', 'tools'],
                    'description' => 'Local file edit/search/diff/lint/test/build plus local MCP/dev tooling. Local Ollama only.',
                ]),
                'data_type' => 'json',
                'description' => 'Profile definition for offline_dev_assist: local dev-tooling operator profile.',
            ],
        ];

        foreach ($rows as $row) {
            $existing = DB::selectOne(
                'SELECT id FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
                [$row['section'], $row['config_key']]
            );

            if ($existing !== null) {
                continue;
            }

            DB::insert(
                'INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $row['section'],
                    $row['config_key'],
                    $row['config_value'],
                    $row['data_type'],
                    $row['description'],
                ]
            );
        }
    }

    public function down(): void
    {
        $pairs = [
            ['routing', 'active_profile'],
            ['routing', 'profile.offline_review'],
            ['routing', 'profile.offline_dev_assist'],
        ];

        foreach ($pairs as [$section, $key]) {
            DB::delete(
                'DELETE FROM system_configs WHERE section = ? AND config_key = ?',
                [$section, $key]
            );
        }
    }
};
