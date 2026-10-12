<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        DB::table('review_type_registry')
            ->where('name', 'ai_model_update')
            ->update([
                'count_sql' => "SELECT COUNT(*) as total, SUM(CASE WHEN priority >= 1 THEN 1 ELSE 0 END) as `high_priority` FROM agent_review_queue WHERE status = 'pending' AND review_type = 'ai_model_update' AND (expires_at IS NULL OR expires_at > NOW())",
                'updated_at' => now(),
            ]);

        Cache::forget('review_type_registry');
    }

    public function down(): void
    {
        Cache::forget('review_type_registry');
    }
};
