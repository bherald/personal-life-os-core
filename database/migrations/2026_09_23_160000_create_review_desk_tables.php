<?php

use App\Services\Genealogy\FaceMatchReviewDecisionService;
use App\Services\Research\ResearchReviewService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_desk_sources', function (Blueprint $table): void {
            $table->string('name', 80)->primary();
            $table->string('source_table')->nullable();
            $table->string('connection_name')->nullable();
            $table->string('id_column')->default('id');
            $table->string('status_column')->default('status');
            $table->text('pending_states');
            $table->text('selection')->nullable();
            $table->string('discovery_mode')->default('queue');
            $table->string('source_url');
            $table->text('effect');
            $table->text('blocker');
        });
        Schema::create('review_desk_references', function (Blueprint $table): void {
            $table->uuid('reference')->primary();
            $table->string('source', 180);
            $table->string('source_id', 100);
            $table->unsignedInteger('generation')->default(1);
            $table->uuid('previous_reference')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('source_revision', 64);
            $table->string('review_type', 80)->index();
            $table->string('source_state', 80);
            $table->string('status', 30)->index();
            $table->text('title');
            $table->longText('snapshot');
            $table->timestamps();
            $table->unique(['source', 'source_id', 'generation'], 'review_desk_identity');
        });
        Schema::create('review_desk_aliases', function (Blueprint $table): void {
            $table->string('alias', 240)->primary();
            $table->uuid('reference')->index();
            $table->boolean('ambiguous')->default(false);
        });
        Schema::create('review_desk_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('reference')->index();
            $table->string('idempotency_key', 128);
            $table->string('request_digest', 64);
            $table->string('action', 30);
            $table->string('state', 30);
            $table->longText('payload');
            $table->timestamps();
            $table->unique(['reference', 'idempotency_key'], 'review_desk_idempotency');
        });
        Schema::create('review_desk_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('reference')->index();
            $table->uuid('receipt_id')->unique();
            $table->text('notes');
            $table->string('queue')->default('operator');
            $table->text('return_condition');
            $table->string('status')->default('open');
            $table->timestamps();
        });
        Schema::create('review_desk_policy', function (Blueprint $table): void {
            $table->string('review_type', 80);
            $table->string('action', 30);
            $table->boolean('enabled')->default(false);
            $table->boolean('bulk_enabled')->default(false);
            $table->boolean('sensitive')->default(true);
            $table->string('service_class');
            $table->string('method');
            $table->text('conditions')->nullable();
            $table->primary(['review_type', 'action']);
        });

        foreach (['approve', 'reject'] as $action) {
            DB::table('review_desk_policy')->insert([
                'review_type' => 'face', 'action' => $action, 'enabled' => true,
                'bulk_enabled' => false, 'sensitive' => true,
                'service_class' => FaceMatchReviewDecisionService::class,
                'method' => $action,
            ]);
            DB::table('review_desk_policy')->insert([
                'review_type' => 'research', 'action' => $action, 'enabled' => true,
                'bulk_enabled' => $action === 'approve', 'sensitive' => false,
                'service_class' => ResearchReviewService::class,
                'method' => $action === 'approve' ? 'approveFact' : 'rejectFact',
                'conditions' => json_encode(array_merge(
                    ['domain_category' => ['research', 'technology', 'science']],
                    $action === 'approve' ? ['indexed_to_rag' => [true, 1]] : []
                )),
            ]);
        }

        $sources = [
            ['agent', 'agent_review_queue', 'status', ['pending'], '/reviews/source', 'Inspect the decomposed proposal before any domain decision.', 'Packets and unknown agent types are inspection-only.'],
            ['genealogy', 'genealogy_proposed_relationships', 'status', ['pending'], '/genealogy', 'May create or link a genealogy relationship.', 'Use the canonical genealogy proposal review and evidence.'],
            ['genealogy_change', 'genealogy_proposed_changes', 'status', ['pending'], '/genealogy', 'Applies an exact genealogy field change.', 'Use the native per-field proposal form.'],
            ['face', 'genealogy_face_match_queue', 'status', ['pending'], '/media/faces', 'Confirms a suggested face identity link.', 'Current visible face evidence and explicit decision scope are required.'],
            ['scan_date_legacy_metadata', 'file_registry', 'status', ['active'], '/file-catalog', 'Inspect legacy scan metadata against the original file.', 'Inspection only; use the native file metadata audit.'],
            ['workflow_gate', 'workflow_approval_gates', 'status', ['pending'], '/workflows', 'Approval may resume workflow execution.', 'Use the native workflow form and execution-bound approval.'],
            ['email_draft', 'email_reply_drafts', 'status', ['pending', 'draft'], '/email-queue', 'Approval sends the exact email externally.', 'Exact recipient and content approval must occur in the email queue.'],
            ['quarantine', 'file_quarantine', 'status', ['quarantined'], '/file-catalog', 'Release or delete the quarantined file.', 'Use the native file review and exact deletion override.'],
            ['intake', 'genealogy_intake_runs', 'status', ['staged', 'reference_copies_ready', 'needs_review'], '/genealogy', 'Packet review and generated proposal application are separate decisions.', 'Open the intake workspace to review individual proposals.'],
            ['research_hint', 'genealogy_research_hints', 'status', ['pending', 'new'], '/genealogy', 'Record a research hint disposition.', 'Use the native tree research-hint review.'],
            ['removal', 'removal_requests', 'status', ['pending', 'pending_review'], '/data-removal', 'May remove private data.', 'Use the native removal review and its exact scope.'],
            ['research_topic', null, 'status', [], '/research-topics', 'Topic planning is reviewed in its native workspace.', 'No concrete standalone topic approval adapter is admitted.'],
            ['research_mission', null, 'status', [], '/research-missions', 'Mission review is handled in its native workspace.', 'No concrete standalone mission approval adapter is admitted.'],
            ['cli_approval', null, 'state', [], '/operator-offline', 'Authorizes an exact tool action in the originating CLI session.', 'Session-local SQLite approvals are visible only in their originating session; use /approvals there.'],
            ['workflow_approval_request', null, 'status', [], '/workflows', 'Legacy workflow approval requests have no verified canonical application handler.', 'Inspect legacy workflow requests natively; no active queue adapter is admitted.'],
        ];
        foreach ($sources as [$name, $sourceTable, $statusColumn, $pending, $url, $effect, $blocker]) {
            DB::table('review_desk_sources')->insert([
                'name' => $name, 'source_table' => $sourceTable, 'status_column' => $statusColumn,
                'pending_states' => json_encode($pending), 'source_url' => $url,
                'effect' => $effect, 'blocker' => $blocker,
            ]);
        }
        DB::table('review_desk_sources')->insert([
            'name' => 'research', 'source_table' => 'research_facts', 'connection_name' => 'pgsql_rag',
            'status_column' => 'review_status', 'pending_states' => '["pending"]', 'source_url' => '/research-missions',
            'effect' => 'Approval may index a research fact.',
            'blocker' => 'Use canonical research fact review; indexing is a separate downstream effect.',
        ]);
        DB::table('review_desk_sources')->where('name', 'scan_date_legacy_metadata')->update([
            'discovery_mode' => 'native',
            'selection' => json_encode(['is_scan' => [1], 'exif_written' => [1], 'date_needs_review' => [1],
                'date_taken_source' => ['ai_visual_high', 'ai_visual_medium', 'ai_visual_low']]),
        ]);
    }

    public function down(): void
    {
        foreach (['policy', 'feedback', 'receipts', 'aliases', 'references', 'sources'] as $name) {
            Schema::dropIfExists('review_desk_'.$name);
        }
    }
};
