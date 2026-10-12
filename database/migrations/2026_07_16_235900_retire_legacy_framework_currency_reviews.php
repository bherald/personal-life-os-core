<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'framework_currency_check';

    private const LEGACY_COMMAND = 'framework:currency-check --notify';

    private const RETIRED_DESCRIPTION = '[RETIRED] Superseded by whiteroom monitoring and the biweekly LLM portfolio review.';

    private const REVIEW_NOTE = 'System-retired legacy framework currency notice; superseded by whiteroom monitoring and the evaluated LLM portfolio review. No approval or external action was applied.';

    /** @return array<string, mixed> */
    private function retirementNotes(): array
    {
        return [
            'summary' => 'Retired duplicate release-notice producer; retained disabled for run history and command compatibility.',
            'retired_reason' => 'Unscored release notices duplicated the current whiteroom and LLM portfolio review paths and entered an unregistered generic review type.',
            'replacement_surfaces' => [
                'whiteroom_monitor_due_report',
                'llm_portfolio_review',
                'Review Hub',
                '5am PLOS report',
            ],
            'safety' => [
                'legacy_command_writes_disabled' => true,
                'operator_authored_reviews_changed' => false,
                'typed_reviews_changed' => false,
                'genealogy_or_face_reviews_changed' => false,
            ],
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', self::JOB_NAME)
                ->where('command', self::LEGACY_COMMAND)
                ->update([
                    'description' => self::RETIRED_DESCRIPTION,
                    'enabled' => 0,
                    'next_run_at' => null,
                    'notification_mode' => 'silent',
                    'notes' => json_encode($this->retirementNotes(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }

        if (! Schema::hasTable('agent_review_queue')) {
            return;
        }

        $this->legacyPendingNotices()->update([
            'status' => 'ignored',
            'reviewer_notes' => self::REVIEW_NOTE,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            $encodedNotes = json_encode($this->retirementNotes(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            DB::table('scheduled_jobs')
                ->where('name', self::JOB_NAME)
                ->where('command', self::LEGACY_COMMAND)
                ->where('description', self::RETIRED_DESCRIPTION)
                ->where('notes', $encodedNotes)
                ->update([
                    'description' => 'Weekly scan for AI/tech advances - HuggingFace trending, GitHub releases, Ollama models',
                    'enabled' => 1,
                    'next_run_at' => null,
                    'notification_mode' => null,
                    'notes' => null,
                    'updated_at' => now(),
                ]);
        }

        if (! Schema::hasTable('agent_review_queue')) {
            return;
        }

        DB::table('agent_review_queue')
            ->where('agent_id', 'system')
            ->where('review_type', 'finding')
            ->whereNull('finding_type')
            ->where('status', 'ignored')
            ->where('reviewer_notes', self::REVIEW_NOTE)
            ->update([
                'status' => 'pending',
                'reviewer_notes' => null,
                'reviewed_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function legacyPendingNotices(): Builder
    {
        return DB::table('agent_review_queue')
            ->where('agent_id', 'system')
            ->where('review_type', 'finding')
            ->whereNull('finding_type')
            ->where('status', 'pending')
            ->where('priority', 0)
            ->where(function ($query): void {
                $query->where(function ($source): void {
                    $source->where('summary', 'like', '[GitHub] %')
                        ->whereRaw("JSON_EXTRACT(details, '$.repo') IS NOT NULL");
                })->orWhere(function ($source): void {
                    $source->where('summary', 'like', '[HuggingFace] %')
                        ->whereRaw("JSON_EXTRACT(details, '$.model_id') IS NOT NULL");
                })->orWhere(function ($source): void {
                    $source->where('summary', 'like', '[Ollama] %')
                        ->whereRaw("JSON_EXTRACT(details, '$.model') IS NOT NULL");
                });
            });
    }
};
