<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        $this->addColumnIfMissing('actionability_state', function (Blueprint $table): void {
            $table->string('actionability_state', 32)->nullable()->after('batch_enabled');
        });
        $this->addColumnIfMissing('actionability_min_confidence', function (Blueprint $table): void {
            $table->decimal('actionability_min_confidence', 4, 3)->nullable()->after('actionability_state');
        });
        $this->addColumnIfMissing('actionability_pushover_allowed', function (Blueprint $table): void {
            $table->boolean('actionability_pushover_allowed')->nullable()->after('actionability_min_confidence');
        });

        $this->seedPolicies();
    }

    public function down(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        foreach ([
            'actionability_pushover_allowed',
            'actionability_min_confidence',
            'actionability_state',
        ] as $column) {
            if (Schema::hasColumn('review_type_registry', $column)) {
                Schema::table('review_type_registry', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function addColumnIfMissing(string $column, callable $callback): void
    {
        if (Schema::hasColumn('review_type_registry', $column)) {
            return;
        }

        Schema::table('review_type_registry', $callback);
    }

    private function seedPolicies(): void
    {
        if (! Schema::hasColumn('review_type_registry', 'actionability_state')) {
            return;
        }

        foreach ([
            'genealogy_finding' => ['operator_actionable', 0.800, true],
            'genealogy_merge' => ['operator_actionable', 0.800, true],
            'tool_proposal' => ['operator_actionable', null, true],
            'skill_optimization' => ['operator_actionable', null, true],
            'agent' => ['review_ready', null, false],
        ] as $name => [$state, $minConfidence, $pushoverAllowed]) {
            DB::table('review_type_registry')
                ->where('name', $name)
                ->update([
                    'actionability_state' => $state,
                    'actionability_min_confidence' => $minConfidence,
                    'actionability_pushover_allowed' => $pushoverAllowed,
                    'updated_at' => now(),
                ]);
        }
    }
};
