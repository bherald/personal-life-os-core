<?php

use App\Services\ModelHostControllerPolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $id = 'qwen38_87';
            $profile = DB::table('llm_model_profiles')->where('profile_name', 'deep_offline_qwen38')->lockForUpdate()->first();
            $provider = DB::table('llm_instances')->where('instance_id', $id)->lockForUpdate()->first();
            $row = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            if ($profile === null || $provider === null || $row === null) {
                throw new RuntimeException('Qwen managed launch requires its existing table registration.');
            }
            $metadata = json_decode($profile->metadata, true, flags: JSON_THROW_ON_ERROR);
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $target = $policy['targets'][$id] ?? null;
            $model = 'qwen3.8-27b-q4_k_m';
            if (! is_array($metadata) || ! is_array($target) || $provider->instance_type !== 'local_llm'
                || ($config['models']['deep_offline'] ?? null) !== $model || ($target['model'] ?? null) !== $model
                || $profile->model_name !== $model || ($metadata['instance_id'] ?? null) !== $id) {
                throw new RuntimeException('Qwen model/provider/profile identity changed; review before migration.');
            }
            // Operational paired installation may precede this migration. Complete
            // registration and later operator tuning are never overwritten on replay.
            if (isset($metadata['controller_registration'])) {
                $existing = $metadata['controller_registration'];
                if (! is_array($existing) || ($existing['schema'] ?? null) !== 'plos.native_controller_registration.v1'
                    || ($existing['target'] ?? null) !== $id || ($existing['host_id'] ?? null) !== '.87'
                    || ($existing['provider_instance_id'] ?? null) !== $id
                    || ! is_array($existing['launch'] ?? null)
                    || ! is_string($existing['control_path'] ?? null) || ! is_string($existing['launch_path'] ?? null)
                    || preg_match('/\A[a-f0-9]{64}\z/', (string) ($existing['control_sha256'] ?? '')) !== 1
                    || preg_match('/\A[a-f0-9]{64}\z/', (string) ($existing['launch_sha256'] ?? '')) !== 1
                    || ($target['lifecycle_adapter']['adapter_type'] ?? null) !== 'scheduled_llama') {
                    throw new RuntimeException('Partial Qwen managed registration requires reconciliation.');
                }

                return;
            }
            if (($target['lifecycle_adapter']['adapter_type'] ?? null) !== 'pinned_llama') {
                throw new RuntimeException('Qwen predecessor adapter changed; review before migration.');
            }
            $registration = $this->registration();
            $policy['targets'][$id]['lifecycle_adapter'] = [
                'adapter_type' => 'scheduled_llama', 'control_roots' => (object) [],
                'required_process_arguments' => ['--alias' => $model, '--ctx-size' => '4096', '--parallel' => '1', '--n-gpu-layers' => '0'],
                'resource_mode' => 'full',
            ];
            $policy['targets'][$id]['verification_timeout_seconds'] = 600;
            $config['route_policy']['require_cached_health'] = false;
            $metadata['controller_registration'] = $registration;
            (new ModelHostControllerPolicyService)->normalize($policy);
            foreach ([
                ['system_configs', $row->id, 'config_value', $policy],
                ['llm_instances', $provider->id, 'config', $config],
                ['llm_model_profiles', $profile->id, 'metadata', $metadata],
            ] as [$table, $key, $column, $value]) {
                DB::table($table)->where('id', $key)->update([
                    $column => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            }
        });
        foreach (['llm_instances', 'llm_model_profiles', 'external_api_providers'] as $key) {
            Cache::forget($key);
        }
    }

    private function registration(): array
    {
        // Versioned table seed, not runtime discovery. These exact model/library
        // identities already exist on .87; no artifact install or qualification.
        return json_decode(<<<'JSON'
{
    "schema": "plos.native_controller_registration.v1",
    "target": "qwen38_87",
    "provider_instance_id": "qwen38_87",
    "host_id": ".87",
    "port": 11438,
    "role": "deep_offline",
    "enabled": false,
    "task_name": "PLOS-Qwen38Sidecar11438",
    "task_user": "SYSTEM",
    "boot_trigger_enabled": false,
    "control_source": "scripts/plos-local-llm-run.ps1",
    "control_path": "C:\\PLOS\\ModelHostController\\targets\\qwen38_87\\plos-local-llm-run.ps1",
    "control_sha256": "2ea56a265678c7c65e3a9c76670b853052b29c3d20b9e76fa526037cafd0b777",
    "launch_path": "C:\\PLOS\\ModelHostController\\targets\\qwen38_87\\launch.json",
    "launch_sha256": "e1118af601b8d6a356432af2dbcd4f9cd7c9b6804068ad1eeda20821f94c75d5",
    "task_arguments": "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File \"C:\\PLOS\\ModelHostController\\targets\\qwen38_87\\plos-local-llm-run.ps1\" -Service llama -LaunchPath \"C:\\PLOS\\ModelHostController\\targets\\qwen38_87\\launch.json\" -LaunchSha256 e1118af601b8d6a356432af2dbcd4f9cd7c9b6804068ad1eeda20821f94c75d5",
    "launch": {
        "schema": "plos.native_llama_launch.v1",
        "executable_path": "D:\\PLOS-Models\\k3-alt\\runtime\\llama-cpp-01818e4956858fe225a6875aae88b4cbee0b319e\\build-cpu\\bin\\Release\\llama-server.exe",
        "executable_sha256": "552ec9f5bd814ce4f671332d843c6689d10c397242dd130c19e42ba7b7efc491",
        "model_path": "D:\\PLOS-Models\\k3-alt\\models\\Qwen3.8-27B-Q4_K_M.gguf",
        "model_sha256": "31629f53165ab6a7dad8c9847dcfd1fdf55829dac1e6e748f4a68581b0033d34",
        "working_directory": "D:\\PLOS-Models\\k3-alt\\runtime\\llama-cpp-01818e4956858fe225a6875aae88b4cbee0b319e\\build-cpu\\bin\\Release",
        "stdout_path": "C:\\PLOS\\ModelHostController\\targets\\qwen38_87\\stdout.log",
        "stderr_path": "C:\\PLOS\\ModelHostController\\targets\\qwen38_87\\stderr.log",
        "runtime_files": {
            "D:\\PLOS-Models\\k3-alt\\runtime\\llama-cpp-01818e4956858fe225a6875aae88b4cbee0b319e\\build-cpu\\bin\\Release\\ggml.dll": "e1016be13d77d72fe4e21ace6aff454879366389891be7e86ad52c6a89ce3bd2",
            "D:\\PLOS-Models\\k3-alt\\runtime\\llama-cpp-01818e4956858fe225a6875aae88b4cbee0b319e\\build-cpu\\bin\\Release\\ggml-base.dll": "3eb74c731c26b13498ac12273d1d383017d32e330a72d7269bbce1a5e1b26c71",
            "D:\\PLOS-Models\\k3-alt\\runtime\\llama-cpp-01818e4956858fe225a6875aae88b4cbee0b319e\\build-cpu\\bin\\Release\\ggml-cpu.dll": "e2266b9fad79957a37ed305f26b6ce824f7372026c428f8572d68841a3044024",
            "D:\\PLOS-Models\\k3-alt\\runtime\\llama-cpp-01818e4956858fe225a6875aae88b4cbee0b319e\\build-cpu\\bin\\Release\\llama.dll": "9e97718e36ad887e8ca6e2eaa6a764cb6d8d57cde92449683fcd924821cc286e",
            "D:\\PLOS-Models\\k3-alt\\runtime\\llama-cpp-01818e4956858fe225a6875aae88b4cbee0b319e\\build-cpu\\bin\\Release\\llama-common.dll": "8ceaedcf30e8e1b26c54621200cc846b05529f62fe376bac01cb1d884c0d8ec7",
            "D:\\PLOS-Models\\k3-alt\\runtime\\llama-cpp-01818e4956858fe225a6875aae88b4cbee0b319e\\build-cpu\\bin\\Release\\llama-server.exe": "552ec9f5bd814ce4f671332d843c6689d10c397242dd130c19e42ba7b7efc491",
            "D:\\PLOS-Models\\k3-alt\\runtime\\llama-cpp-01818e4956858fe225a6875aae88b4cbee0b319e\\build-cpu\\bin\\Release\\llama-server-impl.dll": "9ea99896760c3da0047050ba10ae403082d19223b468e936bb1c0137a441210c",
            "D:\\PLOS-Models\\k3-alt\\runtime\\llama-cpp-01818e4956858fe225a6875aae88b4cbee0b319e\\build-cpu\\bin\\Release\\mtmd.dll": "c559f46b6c0a641b261428eb71413ad5d03a62737ca16be0c0959bfb89b2d907"
        },
        "arguments": [
            "--model",
            "D:\\PLOS-Models\\k3-alt\\models\\Qwen3.8-27B-Q4_K_M.gguf",
            "--host",
            "127.0.0.1",
            "--port",
            "11438",
            "--alias",
            "qwen3.8-27b-q4_k_m",
            "--ctx-size",
            "4096",
            "--parallel",
            "1",
            "--threads",
            "12",
            "--threads-batch",
            "12",
            "--n-gpu-layers",
            "0",
            "--no-mmproj",
            "--jinja"
        ]
    },
    "context_slot_tokens": 4096,
    "usable_context_tokens": 4096,
    "capacity_candidate": {
        "cold": {
            "free_physical_bytes": {
                "minimum": 34359738368
            },
            "commit_headroom_bytes": {
                "minimum": 25769803776
            }
        },
        "loaded": {
            "free_physical_bytes": {
                "minimum": 8589934592
            },
            "commit_headroom_bytes": {
                "minimum": 8589934592
            }
        }
    },
    "qualification_required": [
        "exact installed task and library identities",
        "managed capacity and exact text canary",
        "clean release",
        "no global route promotion before qualification"
    ]
}
JSON, true, flags: JSON_THROW_ON_ERROR);
    }

    public function down(): void
    {
        // Host task, manifest, and table registration are a paired operator action.
        // Do not restore the retired Kimi-dependent launch path from a DB rollback.
    }
};
