<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email Thread/Conversation Tracking Tables
     *
     * Tracks email conversations by linking messages via In-Reply-To and References headers.
     * Provides conversation context for AI-generated replies.
     */
    public function up(): void
    {
        // Email threads - groups related messages into conversations
        Schema::create('email_threads', function (Blueprint $table) {
            $table->id();
            $table->string('subject_normalized', 500)->index();
            $table->json('participant_emails');
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        // Individual email messages within threads
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->string('message_id', 255)->unique();
            $table->string('in_reply_to', 255)->nullable()->index();
            $table->text('references_header')->nullable();
            $table->string('from_address', 255);
            $table->json('to_addresses');
            $table->string('subject', 500);
            $table->text('body_preview')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamps();

            $table->index('thread_id');

            $table->foreign('thread_id')
                  ->references('id')
                  ->on('email_threads')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
        Schema::dropIfExists('email_threads');
    }
};
