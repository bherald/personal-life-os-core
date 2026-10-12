<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BACKUP_KEY = 'bounded_nightly_error_policy_backup';

    public function up(): void
    {
        $workflow = DB::table('workflows')->where('name', 'youtube_watch_later')->first();
        if (! $workflow || DB::table('system_configs')->where('section', 'youtube')->where('config_key', self::BACKUP_KEY)->exists()) {
            return;
        }
        DB::transaction(function () use ($workflow): void {
            $nodes = DB::table('workflow_nodes')->where('workflow_id', $workflow->id)->get();
            $retry = DB::table('retry_configs')->where('workflow_id', $workflow->id)->first();
            DB::table('system_configs')->insert([
                'section' => 'youtube', 'config_key' => self::BACKUP_KEY, 'data_type' => 'json',
                'description' => 'Scoped YouTube batch, timeout and error-notification rollback receipt.',
                'config_value' => json_encode([
                    'workflow_id' => $workflow->id,
                    'nodes' => $nodes->map(fn ($row) => (array) $row)->all(),
                    'configs' => DB::table('workflow_node_configs')->whereIn('workflow_node_id', $nodes->pluck('id'))->get()->map(fn ($row) => (array) $row)->all(),
                    'retry' => $retry ? (array) $retry : null,
                ], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($retry) {
                DB::table('retry_configs')->where('id', $retry->id)->update(['notify_on_failure' => 'none']);
            } else {
                DB::table('retry_configs')->insert(['workflow_id' => $workflow->id, 'max_attempts' => 1, 'notify_on_failure' => 'none', 'backoff_strategy' => 'exponential']);
            }
            foreach ($nodes as $node) {
                $config = [];
                $timeout = null;
                switch ($node->node_type) {
                    case 'App\\Nodes\\YouTube\\YouTubePlaylist':
                        $config = ['limit' => '3', 'first_run_limit' => '3'];
                        $timeout = 180;
                        break;
                    case 'App\\Nodes\\YouTube\\YouTubeTranscript':
                        // Each service call already tries the available transcript methods.
                        $config = ['max_retries' => '0', 'max_seconds' => '240'];
                        $timeout = 900;
                        break;
                    case 'App\\Nodes\\YouTube\\YouTubeKeyPointsPostProcessor':
                        $config = ['limit' => '1', 'max_seconds' => '240'];
                        $timeout = 900;
                        break;
                    case 'App\\Nodes\\YouTube\\YouTubeWatchLaterOrganize':
                        $timeout = 300;
                        break;
                    case 'App\\Nodes\\PushoverNotify':
                        $config = ['source_group' => 'youtube_operational_errors'];
                        break;
                }
                foreach ($config as $key => $value) {
                    DB::table('workflow_node_configs')->updateOrInsert(['workflow_node_id' => $node->id, 'config_key' => $key], ['config_value' => $value]);
                }
                if ($timeout !== null) {
                    DB::table('workflow_nodes')->where('id', $node->id)->update(['timeout_seconds' => $timeout]);
                }
            }
        });
    }

    public function down(): void
    {
        $json = DB::table('system_configs')->where('section', 'youtube')->where('config_key', self::BACKUP_KEY)->value('config_value');
        if (! $json) {
            return;
        }
        $backup = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($backup): void {
            foreach ($backup['nodes'] as $node) {
                DB::table('workflow_nodes')->where('id', $node['id'])->update(['timeout_seconds' => $node['timeout_seconds']]);
                foreach (['limit', 'first_run_limit', 'max_retries', 'max_seconds', 'source_group'] as $key) {
                    $original = array_values(array_filter($backup['configs'], fn ($row) => $row['workflow_node_id'] === $node['id'] && $row['config_key'] === $key));
                    if ($original) {
                        DB::table('workflow_node_configs')->updateOrInsert(['workflow_node_id' => $node['id'], 'config_key' => $key], ['config_value' => $original[0]['config_value']]);
                    } else {
                        DB::table('workflow_node_configs')->where('workflow_node_id', $node['id'])->where('config_key', $key)->delete();
                    }
                }
            }
            if ($backup['retry']) {
                DB::table('retry_configs')->where('id', $backup['retry']['id'])->update(['notify_on_failure' => $backup['retry']['notify_on_failure']]);
            } else {
                DB::table('retry_configs')->where('workflow_id', $backup['workflow_id'])->where('notify_on_failure', 'none')->delete();
            }
            DB::table('system_configs')->where('section', 'youtube')->where('config_key', self::BACKUP_KEY)->delete();
        });
    }
};
