<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email Unsubscribe Links Table
     *
     * Tracks detected unsubscribe links from email headers (List-Unsubscribe)
     * and body content. Supports RFC 8058 One-Click Unsubscribe, mailto:,
     * and HTTP/HTTPS link methods.
     */
    public function up(): void
    {
        Schema::create('email_unsubscribe_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('email_id')->nullable();
            $table->string('sender_domain', 255)->index();
            $table->string('sender_email', 255)->index();
            $table->text('unsubscribe_url')->nullable();
            $table->string('unsubscribe_email', 255)->nullable();
            $table->enum('method', ['one-click', 'mailto', 'link', 'form'])->default('link');
            $table->enum('status', ['detected', 'pending', 'completed', 'failed'])->default('detected');
            $table->timestamp('unsubscribed_at')->nullable();
            $table->text('one_click_post_body')->nullable()->comment('RFC 8058 List-Unsubscribe-Post body');
            $table->text('error_message')->nullable();
            $table->tinyInteger('retry_count')->unsigned()->default(0);
            $table->json('metadata')->nullable()->comment('Additional context: headers, subject patterns, etc.');
            $table->timestamps();

            $table->index('status');
            $table->index(['sender_domain', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_unsubscribe_links');
    }
};
