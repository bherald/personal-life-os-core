<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cli_framework_worker_holds')) {
            Schema::create('cli_framework_worker_holds', function (Blueprint $table) {
                $table->string('worker_id', 64)->primary();
                $table->string('address', 45)->unique();
                $table->string('binding_sha256', 64);
                $table->string('state', 16);
                $table->string('result_sha256', 64)->nullable();
                $table->timestamps();
            });
        }
        DB::transaction(function (): void {
            $inserted = DB::table('workflows')->insertOrIgnore(['name' => 'cyber_qualified_native_tools', 'description' => 'Inactive exact preapproved native Windows fixture via existing DEV durable queue. No model, client paths, auto-approval, expired hold reclaim or replay.', 'active' => false, 'error_handling' => 'stop']);
            if ($inserted === 1) {
                $id = DB::table('workflows')->where('name', 'cyber_qualified_native_tools')->value('id');
                DB::table('workflow_nodes')->insert(['workflow_id' => $id, 'node_type' => 'Cyber\\NativeToolSuite', 'node_order' => 1, 'timeout_seconds' => 180]);
            }
        });
    }

    public function down(): void
    {
        DB::table('workflows')->where('name', 'cyber_qualified_native_tools')->update(['active' => false]);
        // Keep unknown remote holds and durable history. Retirement never implies process termination.
    }
};
