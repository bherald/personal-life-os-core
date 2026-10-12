<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BACKUP_KEY = 'nightly_pipeline_migration_backup';

    public function up(): void
    {
        if (! Schema::hasTable('youtube_joplin_deliveries')) {
            Schema::create('youtube_joplin_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->string('playlist_id', 100);
                $table->string('video_id', 11);
                $table->string('added_at', 40);
                $table->string('note_id', 32)->nullable();
                $table->string('source_hash', 64)->nullable();
                $table->string('last_error', 100)->nullable();
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
                $table->unique(['playlist_id', 'video_id']);
            });
        }
        $workflow = DB::table('workflows')->where('name', 'youtube_watch_later')->first();
        if (! $workflow || DB::table('system_configs')->where('section', 'youtube')
            ->where('config_key', self::BACKUP_KEY)->exists()) {
            return;
        }
        $nodes = DB::table('workflow_nodes')->where('workflow_id', $workflow->id)->orderBy('node_order')->get();
        $final = $nodes->first(fn ($node) => $node->node_type === 'App\\Nodes\\PushoverNotify'
            && DB::table('workflow_node_configs')->where('workflow_node_id', $node->id)
                ->where('config_key', 'source_group')->where('config_value', 'workflow_routine_updates')->exists());
        if (! $final) {
            throw new RuntimeException('Nightly YouTube completion notification is missing; inspect workflow before migration');
        }
        $job = DB::table('scheduled_jobs')->where('job_type', 'workflow')->where('command', $workflow->name)->first();
        $weekly = DB::table('scheduled_jobs')->where('name', 'joplin_youtube_organize')
            ->whereIn('command', ['joplin:youtube-knowledge --apply --json', 'joplin:youtube-knowledge --apply --deduplicate --json'])->first();
        DB::transaction(function () use ($workflow, $nodes, $final, $job, $weekly): void {
            DB::table('system_configs')->insert([
                'section' => 'youtube', 'config_key' => self::BACKUP_KEY, 'data_type' => 'json',
                'description' => 'Exact pre-migration nightly workflow and scheduler configuration for rollback.',
                'config_value' => json_encode([
                    'workflow' => (array) $workflow, 'nodes' => $nodes->map(fn ($row) => (array) $row)->all(),
                    'configs' => DB::table('workflow_node_configs')->whereIn('workflow_node_id', $nodes->pluck('id'))->get()->map(fn ($row) => (array) $row)->all(),
                    'job' => $job ? (array) $job : null, 'weekly' => $weekly ? (array) $weekly : null,
                ], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
            // The scheduler's actual operator-selected time wins over stale workflow metadata.
            $cron = $job->cron_expression ?? $workflow->schedule;
            DB::table('workflows')->where('id', $workflow->id)->update(['schedule' => $cron, 'error_handling' => 'stop', 'updated_at' => now()]);
            // Avoid uniqueness collisions while moving the old notice to the end.
            foreach ($nodes as $node) {
                DB::table('workflow_nodes')->where('id', $node->id)->update(['node_order' => $node->node_order + 1000]);
            }
            $ordered = $nodes->reject(fn ($node) => $node->id === $final->id)->push($final)->values();
            foreach ($ordered as $index => $node) {
                DB::table('workflow_nodes')->where('id', $node->id)->update(['node_order' => $index + 1]);
                if ($node->node_type === 'App\\Nodes\\YouTube\\YouTubeWatchLaterOrganize') {
                    $this->config($node->id, 'dry_run', 'false');
                }
                if ($node->node_type === 'App\\Nodes\\YouTube\\YouTubeKeyPointsPostProcessor') {
                    $this->config($node->id, 'all_youtube_sources', 'true');
                }
            }
            DB::table('workflow_nodes')->where('id', $final->id)->update(['node_type' => 'App\\Nodes\\YouTube\\YouTubeJoplinFinalize']);
            $this->config($final->id, 'title', 'YouTube nightly pipeline');
            $this->config($final->id, 'message', 'Status: {pipeline_status}. Verified notes: {delivery_verified}. Retry: {delivery_retry}. Backlog: {delivery_remaining}. Index conflicts: {organization_conflicts}.');
            DB::table('workflow_node_configs')->where('workflow_node_id', $final->id)->where('config_key', 'only_if')->delete();
            // One processing schedule; preserve separate diagnostic/monitoring jobs.
            if ($weekly) {
                DB::table('scheduled_jobs')->where('id', $weekly->id)->update(['enabled' => false, 'updated_at' => now()]);
                $organizer = $nodes->firstWhere('node_type', 'App\\Nodes\\YouTube\\YouTubeWatchLaterOrganize');
                if ($organizer) {
                    $this->config($organizer->id, 'deduplicate', str_contains($weekly->command, '--deduplicate') ? 'true' : 'false');
                }
            }
            if ($job) {
                DB::table('scheduled_jobs')->where('id', $job->id)->update(['without_overlapping' => true, 'max_parallel' => 1, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        $json = DB::table('system_configs')->where('section', 'youtube')->where('config_key', self::BACKUP_KEY)->value('config_value');
        if ($json) {
            $backup = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            DB::transaction(function () use ($backup): void {
                $workflow = $backup['workflow'];
                DB::table('workflows')->where('id', $workflow['id'])->update([
                    'schedule' => $workflow['schedule'], 'error_handling' => $workflow['error_handling'], 'updated_at' => now(),
                ]);
                foreach ($backup['nodes'] as $node) {
                    DB::table('workflow_nodes')->where('id', $node['id'])->update(['node_order' => $node['node_order'] + 1000]);
                }
                foreach ($backup['nodes'] as $node) {
                    DB::table('workflow_nodes')->where('id', $node['id'])->update(['node_order' => $node['node_order'], 'node_type' => $node['node_type']]);
                }
                $ids = array_column($backup['nodes'], 'id');
                DB::table('workflow_node_configs')->whereIn('workflow_node_id', $ids)->delete();
                DB::table('workflow_node_configs')->insert($backup['configs']);
                if ($backup['weekly']) {
                    DB::table('scheduled_jobs')->where('id', $backup['weekly']['id'])->update(['enabled' => $backup['weekly']['enabled'], 'updated_at' => now()]);
                }
                if ($backup['job']) {
                    DB::table('scheduled_jobs')->where('id', $backup['job']['id'])->update([
                        'without_overlapping' => $backup['job']['without_overlapping'], 'max_parallel' => $backup['job']['max_parallel'], 'updated_at' => now(),
                    ]);
                }
                DB::table('system_configs')->where('section', 'youtube')->where('config_key', self::BACKUP_KEY)->delete();
            });
        }
        // Delivery evidence survives rollback. Never erase verified receipts.
    }

    private function config(int $nodeId, string $key, string $value): void
    {
        DB::table('workflow_node_configs')->updateOrInsert(['workflow_node_id' => $nodeId, 'config_key' => $key], ['config_value' => $value]);
    }
};
