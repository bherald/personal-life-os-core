<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('llm_model_request_logs')) {
            return;
        }

        Schema::create('llm_model_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('llm_instance_row_id')->nullable()->index();
            $table->string('instance_id', 50)->index();
            $table->string('instance_type', 40)->nullable()->index();
            $table->string('model_name', 120)->nullable()->index();
            $table->string('caller', 120)->nullable()->index();
            $table->string('model_role', 50)->nullable()->index();
            $table->string('request_kind', 40)->default('chat')->index();
            $table->boolean('success')->index();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('tokens_per_second', 8, 2)->nullable();
            $table->decimal('quality_score', 5, 4)->nullable();
            $table->boolean('escalated')->nullable()->index();
            $table->string('error_summary', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['instance_id', 'model_name', 'created_at'], 'llm_model_req_instance_model_created_idx');
            $table->index(['caller', 'created_at'], 'llm_model_req_caller_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_model_request_logs');
    }
};
