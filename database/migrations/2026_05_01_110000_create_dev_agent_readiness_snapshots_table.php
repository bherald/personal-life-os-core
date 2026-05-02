<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dev_agent_readiness_snapshots')) {
            return;
        }

        Schema::create('dev_agent_readiness_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('captured_at')->useCurrent()->index();
            $table->unsignedSmallInteger('window_hours')->default(24);
            $table->string('overall_status', 20)->index();
            $table->unsignedSmallInteger('agent_count')->default(0);
            $table->unsignedSmallInteger('warning_count')->default(0);
            $table->unsignedSmallInteger('critical_count')->default(0);
            $table->string('trace_status', 20)->nullable()->index();
            $table->boolean('trace_enabled')->default(false);
            $table->boolean('trace_directory_writable')->default(false);
            $table->unsignedInteger('trace_events_24h')->nullable();
            $table->unsignedInteger('trace_malformed_lines_24h')->nullable();
            $table->string('trace_scan_status', 40)->nullable();
            $table->string('recursion_status', 20)->nullable()->index();
            $table->unsignedInteger('recursion_calls_7d')->nullable();
            $table->json('checks_summary')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['captured_at', 'overall_status'], 'idx_dars_captured_status');
            $table->index(['trace_status', 'trace_scan_status'], 'idx_dars_trace_status_scan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_agent_readiness_snapshots');
    }
};
