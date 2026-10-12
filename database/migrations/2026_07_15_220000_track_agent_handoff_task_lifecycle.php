<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_handoffs')) {
            return;
        }

        if (! Schema::hasColumn('agent_handoffs', 'distributed_task_id')) {
            Schema::table('agent_handoffs', function (Blueprint $table): void {
                $table->string('distributed_task_id', 64)->nullable()->after('target_agent_id')->index();
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE agent_handoffs
                MODIFY status ENUM('initiated','submitted','working','completed','failed','timeout','cancelled')
                NOT NULL DEFAULT 'initiated'
            SQL);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_handoffs')) {
            return;
        }

        DB::table('agent_handoffs')
            ->whereIn('status', ['submitted', 'working'])
            ->update(['status' => 'initiated']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE agent_handoffs
                MODIFY status ENUM('initiated','completed','failed','timeout','cancelled')
                NOT NULL DEFAULT 'initiated'
            SQL);
        }

        if (Schema::hasColumn('agent_handoffs', 'distributed_task_id')) {
            Schema::table('agent_handoffs', function (Blueprint $table): void {
                $table->dropIndex(['distributed_task_id']);
                $table->dropColumn('distributed_task_id');
            });
        }
    }
};
