<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_response_feedback')) {
            return;
        }

        Schema::create('chat_response_feedback', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->unsignedBigInteger('chat_message_id')->unique();
            $table->string('value', 16);
            $table->string('reason', 64)->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('chat_message_id')->references('id')->on('chat_messages')->cascadeOnDelete();
            $table->index('updated_at');
            $table->index(['value', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_response_feedback');
    }
};
