<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'track_health_default' => true,
            'default_limit' => 10,
            'default_timeout_seconds' => 10,
            'include_content_default' => false,
            'max_age_hours' => 24,
            'max_total_articles' => 50,
            'max_formatted_chars' => 50000,
            'persist_articles_default' => true,
            'health_check_timeout_seconds' => 10,
            'max_redirects' => 3,
            'retry_attempts' => 3,
            'description_max_chars' => 500,
            'content_preview_max_chars' => 300,
            'policy_notes' => [
                'overrides' => 'Workflow RSSFeedReader node config still overrides these defaults for a specific run.',
                'fetch_policy' => 'Fetch timeout, retry attempts, and redirect cap used by RSSFeedReader before graceful degradation.',
                'health_tracking' => 'Health tracking default and check timeout used when the node records feed health.',
                'output_caps' => 'Article accumulation, formatted output, description, and content-preview caps used before downstream processing.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'rss')
            ->where('config_key', 'reader_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'rss', 'config_key' => 'reader_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for RSSFeedReader fetch, retry, health-tracking, output, and persistence defaults.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'rss')
            ->where('config_key', 'reader_runtime_policy')
            ->delete();
    }
};
