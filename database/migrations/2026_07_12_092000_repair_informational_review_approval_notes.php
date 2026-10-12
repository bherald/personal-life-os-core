<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OPERATOR_REVIEW_TYPES = [
        'genealogy_finding',
        'tool_proposal',
        'skill_optimization',
    ];

    private const REPLACEMENT_NOTE = 'Auto-approved: informational review type has no side-effect approval handler.';

    public function up(): void
    {
        if (! Schema::hasTable('agent_review_queue')) {
            return;
        }

        DB::table('agent_review_queue')
            ->where('reviewer_notes', 'like', 'Auto-approved: confidence%')
            ->whereNotIn('review_type', self::OPERATOR_REVIEW_TYPES)
            ->update([
                'reviewer_notes' => self::REPLACEMENT_NOTE,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Do not restore audit notes that can assert a false confidence comparison.
    }
};
