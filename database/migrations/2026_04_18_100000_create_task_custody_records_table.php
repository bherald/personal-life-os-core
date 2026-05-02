<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C2 — Task Custody Record primitive (first concrete use of the B6 lease
 * contract in `docs/plos-task-lease-contract.md`).
 *
 * One row = one owner holding one task for a bounded time window, with a
 * durable result envelope written at release.
 *
 * Acquire is guarded by a partial unique constraint emulated via a
 * generated `active_key` column — NULL when released, populated when
 * live — so exactly one unreleased record can exist per (surface, ref).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_custody_records', function ($table) {
            $table->bigIncrements('id');

            $table->string('surface', 32);
            $table->string('surface_ref', 128);
            $table->string('owner_token', 255);

            $table->timestamp('acquired_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();

            $table->enum('outcome', ['success', 'failure', 'cancel'])->nullable();
            $table->json('result_envelope')->nullable();
            $table->enum('notification_state', ['pending', 'delivered', 'suppressed'])->nullable();

            $table->string('progress_note', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->index(['surface', 'surface_ref']);
            $table->index(['released_at', 'expires_at']);
            $table->index('notification_state');
        });

        // Partial unique — one unreleased custody record per (surface, ref).
        // MySQL 5.7+ supports this via a generated `active_key` column that
        // is NULL when released_at is set (so duplicates are allowed there)
        // and deterministic otherwise.
        DB::statement("
            ALTER TABLE task_custody_records
            ADD COLUMN active_key VARCHAR(180)
            GENERATED ALWAYS AS (
                CASE
                    WHEN released_at IS NULL THEN CONCAT(surface, ':', surface_ref)
                    ELSE NULL
                END
            ) STORED
        ");

        DB::statement('CREATE UNIQUE INDEX uniq_task_custody_active ON task_custody_records(active_key)');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_custody_records');
    }
};
