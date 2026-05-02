<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email Follow-Up Tracking Table
     *
     * Tracks emails awaiting response with configurable reminder intervals.
     * Integrates with EmailThreadService for automatic reply detection.
     */
    public function up(): void
    {
        Schema::create('email_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('email_id')->index();
            $table->unsignedBigInteger('thread_id')->nullable()->index();
            $table->string('awaiting_reply_from', 255);
            $table->timestamp('expected_reply_by')->nullable();
            $table->unsignedInteger('reminder_interval_hours')->default(48);
            $table->unsignedInteger('reminder_count')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            $table->enum('status', ['waiting', 'replied', 'expired', 'cancelled'])->default('waiting');
            $table->timestamps();

            $table->foreign('email_id')
                  ->references('id')
                  ->on('email_messages')
                  ->onDelete('cascade');

            $table->foreign('thread_id')
                  ->references('id')
                  ->on('email_threads')
                  ->onDelete('set null');

            $table->index(['status', 'expected_reply_by']);
            $table->index(['status', 'last_reminder_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_follow_ups');
    }
};
