<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('system_configs')->where('section', 'joplin')->where('config_key', 'review_mirror')->first();
        if (! $row) {
            return;
        }
        $policy = json_decode($row->config_value, true, 512, JSON_THROW_ON_ERROR);
        $policy += ['batch_size' => 4, 'http_concurrency' => 4, 'request_timeout_seconds' => 10];
        DB::table('system_configs')->where('section', 'joplin')->where('config_key', 'review_mirror')->update(['config_value' => json_encode($policy), 'updated_at' => now()]);
    }

    public function down(): void
    { /* Retain operator-owned settings and sync receipts. */
    }
};
