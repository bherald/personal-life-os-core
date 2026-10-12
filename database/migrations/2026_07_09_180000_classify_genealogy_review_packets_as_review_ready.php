<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->policyColumnsExist()) {
            return;
        }

        DB::table('review_type_registry')
            ->where('name', 'genealogy_review_packet')
            ->whereNull('actionability_state')
            ->update([
                'actionability_state' => 'review_ready',
                'actionability_min_confidence' => null,
                'actionability_pushover_allowed' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! $this->policyColumnsExist()) {
            return;
        }

        DB::table('review_type_registry')
            ->where('name', 'genealogy_review_packet')
            ->where('actionability_state', 'review_ready')
            ->where('actionability_pushover_allowed', false)
            ->update([
                'actionability_state' => null,
                'actionability_min_confidence' => null,
                'actionability_pushover_allowed' => null,
                'updated_at' => now(),
            ]);
    }

    private function policyColumnsExist(): bool
    {
        return Schema::hasTable('review_type_registry')
            && Schema::hasColumn('review_type_registry', 'actionability_state')
            && Schema::hasColumn('review_type_registry', 'actionability_min_confidence')
            && Schema::hasColumn('review_type_registry', 'actionability_pushover_allowed');
    }
};
