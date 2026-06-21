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
            'max_concurrent' => 10,
            'max_age_hours' => 24,
            'include_content_default' => false,
            'max_total_articles' => 50,
            'max_formatted_chars' => 50000,
            'persist_articles_default' => true,
            'content_preview_max_chars' => 300,
            'all_feeds_failed_log_level' => 'error',
            'partial_empty_log_level' => 'warning',
            'policy_notes' => [
                'max_concurrent' => 'Default max parallel RSS fetches when workflow node config does not provide max_concurrent.',
                'max_age_hours' => 'Default article freshness window when workflow node config does not provide max_age_hours.',
                'include_content_default' => 'Default full-content inclusion behavior when workflow node config does not provide include_content.',
                'max_total_articles' => 'Default cap for accumulated articles passed downstream.',
                'max_formatted_chars' => 'Default cap for formatted text passed downstream.',
                'persist_articles_default' => 'Default article persistence behavior when workflow node config does not provide persist_articles.',
                'content_preview_max_chars' => 'Default content preview length in formatted output.',
                'all_feeds_failed_log_level' => 'Log level when no articles are retrieved because all parallel feeds failed.',
                'partial_empty_log_level' => 'Log level when no articles are retrieved but at least one parallel feed succeeded.',
                'ownership' => 'ParallelRSSFeedReader runtime defaults are table-owned here; explicit workflow node config remains the per-run override.',
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
            ->where('config_key', 'parallel_reader_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);

        $existingNotes = $existing['policy_notes'] ?? null;
        $policy['policy_notes'] = is_array($existingNotes)
            ? array_replace($defaultPolicy['policy_notes'], $existingNotes)
            : $defaultPolicy['policy_notes'];

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'rss', 'config_key' => 'parallel_reader_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for ParallelRSSFeedReader defaults.',
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
            ->where('config_key', 'parallel_reader_runtime_policy')
            ->delete();
    }
};
