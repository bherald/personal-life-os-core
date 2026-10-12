<?php

use App\Services\ModelHostControllerSelectionJournal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('distributed_tasks')->insertOrIgnore(
            ModelHostControllerSelectionJournal::baseRow(ModelHostControllerSelectionJournal::SLOT_ID)
        );
    }

    public function down(): void
    {
        // Never erase custody, uncertain effects or replay protection during source rollback.
    }
};
