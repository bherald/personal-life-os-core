<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumns('llm_instances', ['total_requests', 'total_failures', 'success_rate'])) {
            return;
        }

        DB::statement(
            'UPDATE llm_instances
             SET success_rate = CASE
                 WHEN total_requests > 0 AND total_failures BETWEEN 0 AND total_requests
                     THEN ((total_requests - total_failures) * 100.0) / total_requests
                 ELSE NULL
             END'
        );
    }

    public function down(): void
    {
        // Forward-only data repair: incorrect derived rates are unsafe to restore.
        // The source counters remain unchanged and can always regenerate the rate.
    }
};
