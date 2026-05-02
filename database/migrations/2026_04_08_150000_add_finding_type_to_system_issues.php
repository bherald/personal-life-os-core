<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('system_issues', 'finding_type')) {
            return;
        }

        DB::statement("
            ALTER TABLE system_issues
            ADD COLUMN finding_type VARCHAR(100) NULL AFTER suggested_fix,
            ADD INDEX idx_system_issues_finding_type (finding_type)
        ");
    }

    public function down(): void
    {
        if (!Schema::hasColumn('system_issues', 'finding_type')) {
            return;
        }

        DB::statement("
            ALTER TABLE system_issues
            DROP INDEX idx_system_issues_finding_type,
            DROP COLUMN finding_type
        ");
    }
};
