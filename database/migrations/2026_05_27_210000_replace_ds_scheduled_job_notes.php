<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs') || ! Schema::hasColumn('scheduled_jobs', 'notes')) {
            return;
        }

        $legacyLabel = 'D'.'S dry-run review lane only';
        $newLabel = 'Bounded dry-run review lane only';

        DB::table('scheduled_jobs')
            ->where('notes', 'like', '%'.$legacyLabel.'%')
            ->update([
                'notes' => DB::raw('REPLACE(notes, '.$this->quote($legacyLabel).', '.$this->quote($newLabel).')'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs') || ! Schema::hasColumn('scheduled_jobs', 'notes')) {
            return;
        }

        $legacyLabel = 'D'.'S dry-run review lane only';
        $newLabel = 'Bounded dry-run review lane only';

        DB::table('scheduled_jobs')
            ->where('notes', 'like', '%'.$newLabel.'%')
            ->update([
                'notes' => DB::raw('REPLACE(notes, '.$this->quote($newLabel).', '.$this->quote($legacyLabel).')'),
            ]);
    }

    private function quote(string $value): string
    {
        return DB::getPdo()->quote($value);
    }
};
