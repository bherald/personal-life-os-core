<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('dead_letter_queue');
    }

    public function down(): void
    {
        Schema::create('dead_letter_queue', function ($table) {
            $table->id();
            $table->string('job_type', 50)->index();
            $table->string('job_id', 255)->index();
            $table->json('execution_context')->nullable();
            $table->text('error_message');
            $table->text('error_trace')->nullable();
            $table->string('error_class', 255)->nullable();
            $table->json('original_payload')->nullable();
            $table->tinyInteger('retry_count')->unsigned()->default(0);
            $table->boolean('max_retries_reached')->default(true);
            $table->enum('status', ['pending_review', 'retried', 'resolved', 'dismissed'])->default('pending_review');
            $table->text('resolution_notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by', 100)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }
};
