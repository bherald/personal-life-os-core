<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agent Sessions Table
     *
     * Maintains conversation state across API calls for AI agents.
     * Supports session isolation per user/workflow with automatic expiration.
     *
     * Pattern Reference: OpenAI Agents SDK session management
     */
    public function up(): void
    {
        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->id();

            // Session identification
            $table->string('session_id', 64)->unique();  // UUID or custom identifier
            $table->string('user_id', 100)->nullable()->index();  // User/owner identifier
            $table->string('workflow_id', 100)->nullable()->index();  // Associated workflow if any

            // Session type/scope
            $table->string('session_type', 50)->default('chat');  // chat, workflow, agent, etc.
            $table->string('agent_name', 100)->nullable();  // Name of agent if applicable

            // Conversation state
            $table->json('messages')->nullable();  // Array of message objects [{role, content, timestamp}]
            $table->json('context')->nullable();  // Contextual data (RAG results, tool states, etc.)
            $table->json('agent_state')->nullable();  // Agent-specific state (current step, variables, etc.)
            $table->json('metadata')->nullable();  // Additional metadata (preferences, settings, etc.)

            // Token tracking
            $table->integer('total_tokens')->unsigned()->default(0);
            $table->integer('message_count')->unsigned()->default(0);

            // Status and lifecycle
            $table->enum('status', ['active', 'paused', 'expired', 'completed'])->default('active');
            $table->timestamp('expires_at')->nullable()->index();  // Session expiration time
            $table->timestamp('last_activity_at')->nullable();  // Last message/interaction time

            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            // Indexes for common queries
            $table->index(['user_id', 'status']);
            $table->index(['session_type', 'status']);
            $table->index(['status', 'expires_at']);  // For cleanup queries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_sessions');
    }
};
