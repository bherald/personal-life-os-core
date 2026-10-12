<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('file_date_review_decisions')) {
            return;
        }

        $missing = array_values(array_filter(
            ['decision_scope', 'authorization_receipt_id', 'authorized_source_sha256', 'authorized_asset_uuid', 'metadata_writeback_error'],
            fn (string $column): bool => ! Schema::hasColumn('file_date_review_decisions', $column)
        ));
        if ($missing === []) {
            return;
        }

        Schema::table('file_date_review_decisions', function (Blueprint $table) use ($missing): void {
            if (in_array('decision_scope', $missing, true)) {
                $table->string('decision_scope', 48)->nullable();
            }
            if (in_array('authorization_receipt_id', $missing, true)) {
                $table->uuid('authorization_receipt_id')->nullable()->index();
            }
            if (in_array('authorized_source_sha256', $missing, true)) {
                $table->string('authorized_source_sha256', 64)->nullable();
            }
            if (in_array('authorized_asset_uuid', $missing, true)) {
                $table->uuid('authorized_asset_uuid')->nullable();
            }
            if (in_array('metadata_writeback_error', $missing, true)) {
                $table->text('metadata_writeback_error')->nullable();
            }
        });
    }

    public function down(): void
    {
        // These columns carry per-decision authorization and write history.
        // Preserve them on rollback rather than deleting operator audit records.
    }
};
