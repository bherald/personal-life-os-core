<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_benchmarks', function (Blueprint $table): void {
            $table->json('trajectory_assessment')->nullable()->after('metadata');
            $table->boolean('promotion_eligible')->default(false)->after('trajectory_assessment');
            $table->index(['agent_id', 'promotion_eligible'], 'agent_benchmarks_promotion_eligible_idx');
        });
    }

    public function down(): void
    {
        Schema::table('agent_benchmarks', function (Blueprint $table): void {
            $table->dropIndex('agent_benchmarks_promotion_eligible_idx');
            $table->dropColumn(['trajectory_assessment', 'promotion_eligible']);
        });
    }
};
