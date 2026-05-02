<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_sessions')) {
            Schema::create('agent_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('session_id', 64)->unique();
                $table->string('user_id', 100)->nullable()->index();
                $table->string('workflow_id', 100)->nullable()->index();
                $table->string('session_type', 50)->default('chat');
                $table->string('agent_name', 100)->nullable();
                $table->string('skill_version', 20)->nullable();
                $table->json('messages')->nullable();
                $table->json('context')->nullable();
                $table->json('agent_state')->nullable();
                $table->json('metadata')->nullable();
                $table->integer('total_tokens')->unsigned()->default(0);
                $table->integer('message_count')->unsigned()->default(0);
                $table->enum('status', ['active', 'paused', 'expired', 'completed'])->default('active');
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();

                $table->index(['user_id', 'status']);
                $table->index(['session_type', 'status']);
                $table->index(['status', 'expires_at']);
            });

            return;
        }

        if (! Schema::hasColumn('agent_sessions', 'skill_version')) {
            Schema::table('agent_sessions', function (Blueprint $table) {
                $table->string('skill_version', 20)->nullable()->after('agent_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_sessions') && Schema::hasColumn('agent_sessions', 'skill_version')) {
            Schema::table('agent_sessions', function (Blueprint $table) {
                $table->dropColumn('skill_version');
            });
        }
    }
};
