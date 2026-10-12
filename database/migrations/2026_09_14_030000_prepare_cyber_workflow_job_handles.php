<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cyber_workflow_jobs', function (Blueprint $table) {
            $table->uuid('handle')->primary();
            $table->string('owner', 191)->index();
            $table->uuid('session_id');
            $table->string('scope_sha256', 64);
            $table->text('scope_json');
            $table->text('artifact_binding');
            $table->string('workflow_sha256', 64);
            $table->text('expected_actions');
            $table->text('expected_nodes');
            $table->string('dispatch_state', 32);
            $table->timestamp('created_at');
        });
        DB::table('system_configs')->insertOrIgnore(['section' => 'cyber', 'config_key' => 'workflow_jobs',
            'config_value' => json_encode(['enabled' => false, 'state_path' => null]), 'data_type' => 'json',
            'description' => 'Private CYBER durable task candidate; qualify workflow and server-owned state before enabling.',
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Preserve durable task evidence and operator settings; disable/retire through an explicit migration.
        throw new RuntimeException('CYBER durable task evidence cannot be discarded by rollback. Prepare an explicit retirement migration.');
    }
};
