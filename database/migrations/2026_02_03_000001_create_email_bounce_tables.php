<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email Bounce Handling Tables
     *
     * Tracks email bounces from multiple providers (Postmark, SendGrid, Mailgun, SES),
     * maintains a suppression list for hard bounces and complaints,
     * and manages retry queue for soft bounces with exponential backoff.
     */
    public function up(): void
    {
        // Email bounces tracking
        Schema::create('email_bounces', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->index();
            $table->enum('bounce_type', ['hard', 'soft', 'complaint']);
            $table->string('bounce_subtype', 50)->nullable();
            $table->string('bounce_code', 20)->nullable();
            $table->enum('provider', ['postmark', 'sendgrid', 'mailgun', 'ses']);
            $table->string('provider_bounce_id', 255)->nullable();
            $table->string('message_id', 255)->nullable();
            $table->text('reason')->nullable();
            $table->json('raw_payload')->nullable();
            $table->unsignedBigInteger('draft_id')->nullable();
            $table->tinyInteger('retry_count')->unsigned()->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->boolean('max_retries_reached')->default(false);
            $table->timestamp('bounced_at');
            $table->timestamps();

            $table->index('bounce_type');
            $table->index('bounced_at');
            $table->index('draft_id');

            $table->foreign('draft_id')
                  ->references('id')
                  ->on('email_reply_drafts')
                  ->onDelete('set null');
        });

        // Permanent suppression list
        Schema::create('email_suppression_list', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->unique();
            $table->enum('reason', ['hard', 'complaint', 'manual']);
            $table->unsignedBigInteger('source_bounce_id')->nullable();
            $table->string('provider', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('suppressed_at')->useCurrent();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();

            $table->index('reason');
            $table->index('lifted_at');

            $table->foreign('source_bounce_id')
                  ->references('id')
                  ->on('email_bounces')
                  ->onDelete('set null');
        });

        // Retry queue for soft bounces
        Schema::create('email_retry_queue', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bounce_id');
            $table->unsignedBigInteger('draft_id')->nullable();
            $table->string('email', 255);
            $table->string('subject', 500)->nullable();
            $table->text('body')->nullable();
            $table->string('from_address', 255)->nullable();
            $table->tinyInteger('retry_number')->unsigned();
            $table->timestamp('scheduled_at');
            $table->enum('status', ['pending', 'processing', 'sent', 'failed', 'cancelled'])->default('pending');
            $table->text('result_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_at');
            $table->index('bounce_id');

            $table->foreign('bounce_id')
                  ->references('id')
                  ->on('email_bounces')
                  ->onDelete('cascade');

            $table->foreign('draft_id')
                  ->references('id')
                  ->on('email_reply_drafts')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_retry_queue');
        Schema::dropIfExists('email_suppression_list');
        Schema::dropIfExists('email_bounces');
    }
};
