<?php

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService as Resources;
use App\Services\ModelHostControllerSelectionJournal as Journal;
use App\Services\ModelHostControllerWaitService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }
        if (! (new ModelHostControllerPolicyService)->operatorPacketsApplicable([
            'config/local-engine-candidates/ollama-qwen-trial-results.87.json',
            'config/local-engine-candidates/ollama-qwen-trials.87.json',
        ])) {
            return;
        }
        $definition = json_decode(file_get_contents(base_path('config/local-engine-candidates/ollama-qwen-trials.87.json')),
            true, flags: JSON_THROW_ON_ERROR);
        $results = json_decode(file_get_contents(base_path('config/local-engine-candidates/ollama-qwen-trial-results.87.json')),
            true, flags: JSON_THROW_ON_ERROR);
        $expectedTargets = array_column($definition['candidates'] ?? [], 'target');
        $actualTargets = array_keys($results['candidates'] ?? []);
        sort($expectedTargets);
        sort($actualTargets);
        if (($definition['schema'] ?? null) !== 'plos.ollama_qwen_trials.v1'
            || ($results['schema'] ?? null) !== 'plos.ollama_qwen_trial_results.v1'
            || count($definition['candidates'] ?? []) !== 2
            || $actualTargets !== $expectedTargets) {
            // Evidence is keyed by exact target identity, independent of JSON key order.
            throw new RuntimeException('Exact Qwen trial evidence is unavailable.');
        }
        DB::transaction(function () use ($definition, $results): void {
            $slot = DB::table('distributed_tasks')->where('task_id', Journal::SLOT_ID)->lockForUpdate()->first();
            $state = $slot ? json_decode((string) $slot->result, true, flags: JSON_THROW_ON_ERROR) : [];
            if (! $slot || $slot->task_type !== Journal::TASK_TYPE || $slot->status !== 'held'
                || ($state['schema'] ?? null) !== Journal::SCHEMA || ($state['phase'] ?? null) !== 'idle'
                || DB::table('distributed_tasks')->where('task_type', ModelHostControllerWaitService::TASK_TYPE)
                    ->where('status', 'held')->exists()) {
                throw new RuntimeException('Controller journal and FIFO must be idle before Qwen trial finalization.');
            }
            $provider = DB::table('llm_instances')->where('instance_id', $definition['provider_instance_id'])->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if (! $provider || ! $policyRow || ! $resourceRow) {
                throw new RuntimeException('Existing Qwen trial tables are required.');
            }
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (($config['controller_qwen_manual_trials_20260929']['measured_qualification'] ?? null) === true) {
                return; // Never replay over a later operator choice.
            }
            if (($config['controller_qwen_manual_trials_20260929']['manual_only'] ?? null) !== true
                || ($config['controller_qwen_manual_trials_20260929']['measured_qualification'] ?? null) !== false
                || ($config['route_policy']['require_explicit_instance'] ?? null) !== true
                || ($config['route_policy']['allow_generic_fallback'] ?? null) !== false
                || ($policy['execution_mode'] ?? null) !== 'serial'
                || ($policy['concurrent_inference_enabled'] ?? null) !== false
                || ($resources['host_reserves']['.87']['free_physical_bytes']['minimum'] ?? 0) < 8589934592) {
                throw new RuntimeException('Qwen manual trial scope or controller reserve drifted.');
            }
            foreach ($definition['candidates'] as $candidate) {
                $id = $candidate['target'];
                $result = $results['candidates'][$id];
                $role = $candidate['role'].'_trial';
                $target = $policy['targets'][$id] ?? null;
                $capacity = $resources['profiles'][$candidate['resource_profile']] ?? null;
                $model = DB::table('ollama_models')->where('instance_id', $provider->id)
                    ->where('model_name', $candidate['model'])->lockForUpdate()->first();
                $profile = DB::table('llm_model_profiles')->where('profile_name', $candidate['profile_name'])
                    ->lockForUpdate()->first();
                $journal = DB::table('distributed_tasks')->where('id', $result['journal_id'] ?? 0)->lockForUpdate()->first();
                $journalPayload = $journal ? json_decode((string) $journal->payload, true, flags: JSON_THROW_ON_ERROR) : [];
                $journalResult = $journal ? json_decode((string) $journal->result, true, flags: JSON_THROW_ON_ERROR) : [];
                if (($result['passed'] ?? null) !== true || ($result['custody_idle_after'] ?? null) !== true
                    || ($result['journal_result'] ?? null) !== 'completed'
                    || ! is_int($result['journal_id'] ?? null) || $result['journal_id'] < 1
                    || ! is_string($result['request_id'] ?? null)
                    || ! preg_match('/\Amhc-http-[a-f0-9-]{36}\z/', $result['request_id'])
                    || ! is_numeric($result['elapsed_seconds'] ?? null) || $result['elapsed_seconds'] <= 0
                    || ! is_array($target) || ! is_array($capacity) || ! $model || ! $profile
                    || ($config['models'][$role] ?? null) !== $candidate['model']
                    || ($target['model'] ?? null) !== $candidate['model'] || ($target['roles'] ?? null) !== [$role]
                    || ($target['state'] ?? null) !== 'staged'
                    || ($target['activation_allowed'] ?? null) !== true
                    || ($target['requires_operator_override'] ?? null) !== false
                    || ($capacity['qualified'] ?? null) !== true
                    || ($capacity['model_identity'] ?? null) !== 'digest:'.$candidate['digest']
                    || ! $journal || $journal->task_type !== Journal::TASK_TYPE || $journal->status !== 'held'
                    || ($journalPayload['schema'] ?? null) !== Journal::SCHEMA
                    || ($journalPayload['request_id'] ?? null) !== $result['request_id']
                    || ($journalPayload['role'] ?? null) !== $role
                    || ($journalPayload['controller']['target'] ?? null) !== $id
                    || ($journalResult['schema'] ?? null) !== Journal::SCHEMA
                    || ($journalResult['phase'] ?? null) !== 'completed'
                    || ($journalResult['dispatched'] ?? null) !== true
                    || ($journalResult['receipt']['result'] ?? null) !== 'green'
                    || (int) $model->is_available !== 1 || $model->status !== 'testing'
                    || (int) $profile->enabled !== 1 || $profile->status !== 'testing') {
                    throw new RuntimeException('Qwen manual trial evidence or exact table preimage differs.');
                }
                if ($candidate['role'] === 'coding' && ($result['complete_answer'] ?? null) !== true) {
                    throw new RuntimeException('Coding trial answer did not complete.');
                }
                if ($candidate['role'] === 'embedding'
                    && (($result['vector_dimensions'] ?? null) !== 1024
                        || ($result['finite_values'] ?? null) !== 1024
                        || ($result['nonzero_values'] ?? null) !== true)) {
                    throw new RuntimeException('Embedding trial did not prove its 1024-dimensional contract.');
                }
                $metadata = json_decode((string) $profile->metadata, true, flags: JSON_THROW_ON_ERROR);
                $metadata['controller_candidate']['measured_qualification'] = true;
                $metadata['controller_candidate']['trial_state'] = 'brief_pass';
                $metadata['controller_candidate']['trial_result'] = $result;
                $updatedModel = DB::table('ollama_models')->where('id', $model->id)->where('status', 'testing')
                    ->update(['status' => 'vetted', 'updated_at' => now()]);
                $updatedProfile = DB::table('llm_model_profiles')->where('id', $profile->id)->where('status', 'testing')
                    ->update(['status' => 'active', 'metadata' => $this->json($metadata), 'updated_at' => now()]);
                if ($updatedModel !== 1 || $updatedProfile !== 1) {
                    throw new RuntimeException('Qwen manual trial rows changed during finalization.');
                }
            }
            $config['controller_qwen_manual_trials_20260929']['measured_qualification'] = true;
            $config['controller_qwen_trials_20260929']['measured_qualification'] = true;
            if ($this->providerPreimageQuery((int) $provider->id, (string) $provider->config)
                ->update(['config' => $this->json($config), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Qwen trial provider changed during finalization.');
            }
        });
        foreach (['llm_instances', 'llm_instances_all', 'llm_instances_healthy', 'ollama_models', 'llm_model_profiles'] as $key) {
            Cache::forget($key);
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function providerPreimageQuery(int $id, string $config): Builder
    {
        $query = DB::table('llm_instances')->where('id', $id);

        // MySQL JSON equality compares a JSON document with a SQL string; match its exact stored bytes instead.
        return DB::connection()->getDriverName() === 'mysql'
            ? $query->whereRaw('SHA2(CAST(config AS CHAR), 256) = ?', [hash('sha256', $config)])
            : $query->where('config', $config);
    }

    public function down(): void
    {
        // Preserve any later operator choice.
    }
};
