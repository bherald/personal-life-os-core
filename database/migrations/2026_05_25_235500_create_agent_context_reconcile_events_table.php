<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_context_reconcile_events', function (Blueprint $table): void {
            $table->id();
            $table->char('event_key', 64)->unique();
            $table->string('source_system', 50);
            $table->string('source_state', 40);
            $table->string('reason', 120);
            $table->unsignedBigInteger('rag_document_id')->nullable();
            $table->char('source_id_hash', 64)->nullable();
            $table->char('title_hash', 64)->nullable();
            $table->string('agent_id', 100)->nullable();
            $table->char('session_hash', 64)->nullable();
            $table->char('task_hash', 64)->nullable();
            $table->unsignedInteger('event_count')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['source_system', 'source_state'], 'idx_acre_source_state');
            $table->index(['reason', 'last_seen_at'], 'idx_acre_reason_seen');
            $table->index(['rag_document_id', 'resolved_at'], 'idx_acre_rag_resolved');
            $table->index(['last_seen_at', 'resolved_at'], 'idx_acre_seen_resolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_context_reconcile_events');
    }
};
