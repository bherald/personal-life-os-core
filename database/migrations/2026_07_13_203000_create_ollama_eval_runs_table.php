<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ollama_eval_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->string('requested_provider', 64);
            $table->string('requested_model', 191);
            $table->string('requested_instance_id', 100)->nullable();
            $table->string('actual_provider', 100)->nullable();
            $table->string('actual_model', 191)->nullable();
            $table->string('suite_id', 100);
            $table->char('suite_fingerprint', 64);
            $table->unsignedSmallInteger('expected_case_count');
            $table->unsignedSmallInteger('evaluated_case_count');
            $table->decimal('pass_rate', 5, 1);
            $table->decimal('average_score_ratio', 6, 4);
            $table->string('decision', 64);
            $table->boolean('promotion_eligible')->default(false);
            $table->boolean('target_consistent')->default(false);
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->json('case_summary');
            $table->json('assessment');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['requested_model', 'created_at'], 'ollama_eval_runs_model_created_idx');
            $table->index(['suite_fingerprint', 'promotion_eligible'], 'ollama_eval_runs_suite_promotion_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ollama_eval_runs');
    }
};
