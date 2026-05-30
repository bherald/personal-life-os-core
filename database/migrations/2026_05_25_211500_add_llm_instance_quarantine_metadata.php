<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $this->addColumnIfMissing('quarantine_status', function (Blueprint $table): void {
            $table->string('quarantine_status', 30)->default('none')->after('circuit_retry_at');
        });
        $this->addColumnIfMissing('quarantined_at', function (Blueprint $table): void {
            $table->timestamp('quarantined_at')->nullable()->after('quarantine_status');
        });
        $this->addColumnIfMissing('quarantine_reason', function (Blueprint $table): void {
            $table->text('quarantine_reason')->nullable()->after('quarantined_at');
        });
        $this->addColumnIfMissing('quarantine_source', function (Blueprint $table): void {
            $table->string('quarantine_source', 100)->nullable()->after('quarantine_reason');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        foreach ([
            'quarantine_source',
            'quarantine_reason',
            'quarantined_at',
            'quarantine_status',
        ] as $column) {
            if (Schema::hasColumn('llm_instances', $column)) {
                Schema::table('llm_instances', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function addColumnIfMissing(string $column, callable $callback): void
    {
        if (Schema::hasColumn('llm_instances', $column)) {
            return;
        }

        Schema::table('llm_instances', $callback);
    }
};
