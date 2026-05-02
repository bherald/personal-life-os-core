<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix dhash_hex column size - 128-bit dHash = 64 hex characters, not 32
 */
return new class extends Migration
{
    public function up(): void
    {
        // The dHash is 128-bit, represented as 64 hex characters
        // The column was incorrectly created as char(32), need char(64)
        try {
            DB::statement("
                ALTER TABLE file_registry_perceptual_hashes
                MODIFY COLUMN dhash_hex CHAR(64) NOT NULL
            ");
        } catch (\Exception $e) {
            // Column may already be correct size
        }
    }

    public function down(): void
    {
        // Don't downgrade - existing data would be truncated
    }
};
