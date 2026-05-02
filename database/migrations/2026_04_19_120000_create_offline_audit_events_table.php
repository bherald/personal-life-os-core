<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 3b P02g — Offline/hybrid policy audit receipts.
     *
     * One row per decision emitted by OfflinePolicyService plus one row per
     * operator mode change emitted by RoutingProfileCommand. The schema is
     * intentionally flat and replay-friendly: every column that
     * distinguishes the decision path is explicit rather than hidden inside
     * the context JSON, so the table can be queried via simple WHERE clauses
     * for audit review.
     */
    public function up(): void
    {
        Schema::create('offline_audit_events', function (Blueprint $table) {
            $table->id();

            // policy_allow, policy_deny, policy_confirm, mode_change
            $table->string('event_type', 30)->index();

            // Profile active AT THE TIME of the decision. For mode_change
            // events this is the profile being activated (the "to" side).
            $table->string('profile', 64)->index();

            // Captured from routing.offline_mode at decision time.
            $table->boolean('offline_mode_active')->default(false);

            // Optional classification columns — populated depending on what
            // was evaluated. Null is a valid value for rows that do not apply.
            $table->string('operation', 100)->nullable()->index();
            $table->string('tool_class', 50)->nullable();
            $table->string('mcp_server', 100)->nullable();
            $table->string('mcp_trust_boundary', 30)->nullable();
            $table->string('path_class', 30)->nullable();
            $table->string('provider_class', 40)->nullable();
            $table->string('remote_domain_class', 30)->nullable();

            // Target = operation's target path, MCP server, provider id, etc.
            $table->string('target', 500)->nullable();

            // Actor = agent id, artisan caller, or 'system'. For mode_change,
            // this is 'routing:profile' (the only writer of active_profile).
            $table->string('actor', 100)->nullable()->index();

            $table->string('reason', 500)->nullable();

            // Full decision payload + caller context for replay / debug.
            $table->json('context')->nullable();

            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['profile', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_audit_events');
    }
};
