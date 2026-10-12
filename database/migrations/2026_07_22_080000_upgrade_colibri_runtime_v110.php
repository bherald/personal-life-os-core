<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'colibri_87';

    private const PROFILE = 'deep_offline_colibri';

    public function up(): void
    {
        $this->updateRuntime('v1.1.0', 'colibri-v1.1.0', true);
    }

    public function down(): void
    {
        $this->updateRuntime('v1.0.0', 'colibri-v1.0.0', false);
    }

    private function updateRuntime(string $version, string $family, bool $upgrading): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $instance = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if ($instance === null) {
            return;
        }

        $config = json_decode((string) ($instance->config ?? ''), true);
        if (! is_array($config)) {
            $config = [];
        }

        if ($upgrading) {
            $extraHeaders = is_array($config['extra_headers'] ?? null) ? $config['extra_headers'] : [];
            $extraHeaders['Host'] = '127.0.0.1';
            $config['extra_headers'] = $extraHeaders;
            $config['runtime_release'] = [
                'version' => $version,
                'commit' => '0c524395eef0b132b32a5bef07b539b035a4bccf',
                'archive_sha256' => 'bde88c95527bb72e8abbaa0934da1291ddcd0cdbddb3256fe14fb22a878ba9ef',
                'launcher_sha256' => '31155894c0d5c6f3fc32750964fbab713b71f0d23da53ff68b2347ca7d0e53f8',
                'engine_sha256' => '88d1634730959c1e168ce0e98654c19088e950963d3b4bc393a978a86d04dc59',
            ];
            $config['runtime_tuning'] = [
                'pipe' => true,
                'profile' => true,
                'disk_split_metrics' => true,
                'direct_io' => 'runtime_auto',
                'pilot' => 'runtime_auto',
                'pilot_real' => false,
                'cuda' => false,
                'policy' => 'lossless_measured_baseline',
            ];
        } else {
            if (($config['extra_headers']['Host'] ?? null) === '127.0.0.1') {
                unset($config['extra_headers']['Host']);
                if ($config['extra_headers'] === []) {
                    unset($config['extra_headers']);
                }
            }
            unset($config['runtime_release'], $config['runtime_tuning']);
        }

        $payload = [
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('llm_instances', 'compat_runtime_family')) {
            $payload['compat_runtime_family'] = $family;
        }
        DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->update($payload);

        if (Schema::hasTable('llm_model_profiles')) {
            $profile = DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->first();
            if ($profile !== null && Schema::hasColumn('llm_model_profiles', 'metadata')) {
                $metadata = json_decode((string) ($profile->metadata ?? ''), true);
                if (! is_array($metadata)) {
                    $metadata = [];
                }
                if ($upgrading) {
                    $metadata['runtime_version'] = $version;
                } else {
                    unset($metadata['runtime_version']);
                }
                DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->update([
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (['llm_instances_all', 'llm_instances_healthy', 'llm_model_profiles'] as $key) {
            Cache::forget($key);
        }
    }
};
