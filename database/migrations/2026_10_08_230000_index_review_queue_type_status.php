<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'idx_review_queue_type_status';

    public function up(): void
    {
        if (Schema::hasTable('agent_review_queue') && ! Schema::hasIndex('agent_review_queue', self::INDEX)) {
            Schema::table('agent_review_queue', fn (Blueprint $table) => $table->index(['review_type', 'status'], self::INDEX));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_review_queue') && Schema::hasIndex('agent_review_queue', self::INDEX)) {
            Schema::table('agent_review_queue', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }
};
