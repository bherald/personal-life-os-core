<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair small schema drifts found by the broad local test pass.
     *
     * This migration is intentionally idempotent:
     * - prod/dev instances that already have speculative benchmark columns are
     *   unchanged;
     * - redundant indexes are dropped only when present.
     */
    public function up(): void
    {
        $this->ensureSpeculativeBenchmarkColumns();

        $this->dropIndexIfExists('offline_audit_events', 'offline_audit_events_event_type_index');
        $this->dropIndexIfExists('offline_audit_events', 'offline_audit_events_profile_index');
        $this->dropIndexIfExists('agent_episode_summaries', 'idx_aes_agent');
    }

    public function down(): void
    {
        // No-op: the added columns are active speculative-execution schema, and
        // the dropped indexes are redundant with existing composite indexes.
    }

    private function ensureSpeculativeBenchmarkColumns(): void
    {
        if (! Schema::hasTable('agent_benchmarks')) {
            return;
        }

        if (! Schema::hasColumn('agent_benchmarks', 'is_speculative')) {
            Schema::table('agent_benchmarks', function (Blueprint $table): void {
                $table->boolean('is_speculative')->default(false)->after('error_message');
            });
        }

        if (! Schema::hasColumn('agent_benchmarks', 'spec_run_id')) {
            Schema::table('agent_benchmarks', function (Blueprint $table): void {
                $table->string('spec_run_id', 64)->nullable()->after('is_speculative');
            });
        }

        if (
            Schema::hasColumn('agent_benchmarks', 'is_speculative')
            && Schema::hasColumn('agent_benchmarks', 'spec_run_id')
            && ! $this->indexExists('agent_benchmarks', 'idx_speculative')
        ) {
            Schema::table('agent_benchmarks', function (Blueprint $table): void {
                $table->index(['is_speculative', 'spec_run_id'], 'idx_speculative');
            });
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $index)) {
            return;
        }

        DB::statement(sprintf('DROP INDEX `%s` ON `%s`', $index, $table));
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== null;
    }
};
