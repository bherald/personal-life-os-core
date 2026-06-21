<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RESOURCE_PROFILES = [
        'faces' => ['hard_cap' => 4, 'gpu_bound' => false, 'cpu_bound' => true, 'io_bound' => false, 'llm_bound' => false],
        'ai' => ['hard_cap' => 3, 'gpu_bound' => true, 'cpu_bound' => false, 'io_bound' => false, 'llm_bound' => true],
        'rag' => ['hard_cap' => 4, 'gpu_bound' => false, 'cpu_bound' => false, 'io_bound' => true, 'llm_bound' => false],
        'phash' => ['hard_cap' => 3, 'gpu_bound' => false, 'cpu_bound' => true, 'io_bound' => false, 'llm_bound' => false],
        'video' => ['hard_cap' => 2, 'gpu_bound' => false, 'cpu_bound' => true, 'io_bound' => false, 'llm_bound' => false],
        'exif' => ['hard_cap' => 3, 'gpu_bound' => false, 'cpu_bound' => false, 'io_bound' => true, 'llm_bound' => false],
        'writeback' => ['hard_cap' => 2, 'gpu_bound' => false, 'cpu_bound' => false, 'io_bound' => true, 'llm_bound' => false],
        'thumbnails' => ['hard_cap' => 3, 'gpu_bound' => false, 'cpu_bound' => true, 'io_bound' => false, 'llm_bound' => false],
        'genealogy_llm' => ['hard_cap' => 6, 'gpu_bound' => false, 'cpu_bound' => false, 'io_bound' => false, 'llm_bound' => true],
        'default' => ['hard_cap' => 2, 'gpu_bound' => false, 'cpu_bound' => true, 'io_bound' => false, 'llm_bound' => false],
    ];

    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            $this->upsertSystemConfig(
                'scheduler',
                'resource_profiles',
                self::RESOURCE_PROFILES,
                'Table-owned scheduler resource profile caps and resource flags. Code constants are fallback defaults only.'
            );
        }

        if (Schema::hasTable('llm_instances')) {
            $this->updateInstanceConfig('ollama_jetson', function (array $config): array {
                $config['models'] = is_array($config['models'] ?? null) ? $config['models'] : [];
                unset($config['models']['standard'], $config['models']['quality']);

                $config['role_policy'] = is_array($config['role_policy'] ?? null) ? $config['role_policy'] : [];
                unset($config['role_policy']['standard'], $config['role_policy']['quality']);

                $config['route_policy'] = $this->recursiveMerge(
                    is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [],
                    [
                        'require_model_role' => true,
                        'allow_generic_fallback' => false,
                        'allowed_model_roles' => ['fast', 'embedding', 'health', 'coding'],
                    ]
                );

                return $config;
            }, [
                'is_healthy' => 1,
                'health_score' => 70,
                'circuit_state' => 'closed',
                'circuit_opened_at' => null,
                'circuit_retry_at' => null,
                'consecutive_failures' => 0,
            ], '2026-06-15 TABLE-DRIVEN-CAPACITY: Jetson remains active for light/explicit roles only; quality and standard are withheld until eval shows stable latency.');

            $this->updateInstanceConfig('ollama_primary', function (array $config): array {
                $config['models'] = is_array($config['models'] ?? null) ? $config['models'] : [];
                unset($config['models']['standard'], $config['models']['quality']);

                $config['route_policy'] = $this->recursiveMerge(
                    is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [],
                    [
                        'require_model_role' => true,
                        'allow_generic_fallback' => false,
                        'allowed_model_roles' => ['fast', 'embedding', 'vision', 'coding', 'uncensored'],
                    ]
                );

                return $config;
            }, [
                'is_healthy' => 1,
                'health_score' => 75,
                'circuit_state' => 'closed',
                'circuit_opened_at' => null,
                'circuit_retry_at' => null,
                'consecutive_failures' => 0,
            ], '2026-06-15 TABLE-DRIVEN-CAPACITY: Primary is restored for light/local roles; quality and standard are withheld because recent telemetry timed out.');

            $this->updateInstanceConfig('ollama_secondary', fn (array $config): array => $config, [
                'max_concurrent' => 2,
            ], '2026-06-15 TABLE-DRIVEN-CAPACITY: Raised table-owned concurrency for the stable .87 workhorse after recent quality/standard success telemetry.');

            $this->updateInstanceConfig('codex_exec', function (array $config): array {
                $config['route_policy'] = $this->recursiveMerge(
                    is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [],
                    [
                        'require_model_role' => true,
                        'allow_generic_fallback' => true,
                        'allowed_model_roles' => ['fast', 'standard', 'quality', 'coding'],
                    ]
                );

                return $config;
            }, [
                'is_active' => 1,
                'is_healthy' => 1,
                'health_score' => 100,
                'circuit_state' => 'closed',
                'circuit_opened_at' => null,
                'circuit_retry_at' => null,
                'consecutive_failures' => 0,
                'max_concurrent' => 2,
                'rate_limit_rpm' => 12,
            ], '2026-06-15 TABLE-DRIVEN-CAPACITY: Codex Exec remains the approved private external escalation lane and is table-advertised for increased standard/quality/coding fallback load.');
        }

        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', 'genealogy_agent_research_queue')
                ->update([
                    'max_parallel' => 6,
                    'updated_at' => now(),
                ]);

            $this->mergeScheduledJobNotes('genealogy_agent_research_queue', [
                'runtime' => [
                    'runtime_mode' => 'queue_research',
                    'resource_profile' => 'genealogy_llm',
                    'backlog_metric' => 'genealogy_queue',
                    'parallel_min_backlog' => 2,
                    'hard_cap' => 6,
                    'capacity_model_roles' => ['standard', 'quality'],
                    'capacity_policy' => 'Count only healthy private providers that advertise one of capacity_model_roles in llm_instances.config.models.',
                ],
                'training' => [
                    'version' => 2,
                    'growth_policy' => 'max_parallel, runtime.hard_cap, and capacity_model_roles are table-owned; code should resolve live capacity and safety gates without hidden hardcoded caps.',
                    'codex_policy' => 'Codex Exec may count for this private workload only because llm_instances marks it private_allowed and advertises standard/quality/coding roles.',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('llm_instances')) {
            $this->updateInstanceConfig('ollama_jetson', function (array $config): array {
                $config['models'] = is_array($config['models'] ?? null) ? $config['models'] : [];
                $config['models']['standard'] = 'qwen3:4b';
                $config['models']['quality'] = 'qwen3:4b';

                $config['role_policy'] = is_array($config['role_policy'] ?? null) ? $config['role_policy'] : [];
                $config['role_policy']['standard'] = 'jetson_safe_auto';
                $config['role_policy']['quality'] = 'jetson_safe_auto_4b_conservative';
                if (is_array($config['route_policy'] ?? null)) {
                    $config['route_policy']['allowed_model_roles'] = ['fast', 'embedding', 'health', 'coding', 'standard', 'quality'];
                }

                return $config;
            }, [
                'max_concurrent' => 1,
            ]);

            $this->updateInstanceConfig('ollama_primary', function (array $config): array {
                $config['models'] = is_array($config['models'] ?? null) ? $config['models'] : [];
                $config['models']['standard'] = 'qwen3:4b';
                $config['models']['quality'] = 'qwen3:4b';
                if (is_array($config['route_policy'] ?? null)) {
                    $config['route_policy']['allowed_model_roles'] = ['fast', 'embedding', 'vision', 'coding', 'uncensored', 'standard', 'quality'];
                }

                return $config;
            }, [
                'max_concurrent' => 1,
            ]);

            $this->updateInstanceConfig('ollama_secondary', fn (array $config): array => $config, [
                'max_concurrent' => 1,
            ]);

            $this->updateInstanceConfig('codex_exec', fn (array $config): array => $config, [
                'max_concurrent' => 1,
                'rate_limit_rpm' => 6,
            ]);
        }

        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', 'genealogy_agent_research_queue')
                ->update([
                    'max_parallel' => 4,
                    'updated_at' => now(),
                ]);

            $this->mergeScheduledJobNotes('genealogy_agent_research_queue', [
                'runtime' => [
                    'hard_cap' => 4,
                    'capacity_model_roles' => [],
                ],
                'training' => [
                    'version' => 1,
                ],
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function upsertSystemConfig(string $section, string $key, array $value, string $description): void
    {
        DB::table('system_configs')->updateOrInsert(
            ['section' => $section, 'config_key' => $key],
            [
                'config_value' => json_encode($value, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => $description,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * @param  callable(array<string,mixed>): array<string,mixed>  $mutator
     * @param  array<string,mixed>  $updates
     */
    private function updateInstanceConfig(string $instanceId, callable $mutator, array $updates = [], ?string $note = null): void
    {
        $row = DB::table('llm_instances')
            ->where('instance_id', $instanceId)
            ->first(['config', 'notes']);

        if (! $row) {
            return;
        }

        $config = json_decode((string) ($row->config ?? '{}'), true);
        if (! is_array($config)) {
            $config = [];
        }

        $payload = array_merge($updates, [
            'config' => json_encode($mutator($config), JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        if ($note !== null) {
            $payload['notes'] = $this->appendNoteOnce((string) ($row->notes ?? ''), $note);
        }

        DB::table('llm_instances')
            ->where('instance_id', $instanceId)
            ->update($payload);
    }

    /**
     * @param  array<string,mixed>  $patch
     */
    private function mergeScheduledJobNotes(string $name, array $patch): void
    {
        $row = DB::table('scheduled_jobs')
            ->where('name', $name)
            ->first(['notes']);

        $current = [];
        if ($row && is_string($row->notes ?? null) && trim((string) $row->notes) !== '') {
            $decoded = json_decode((string) $row->notes, true);
            if (is_array($decoded)) {
                $current = $decoded;
            } else {
                $current = ['summary' => (string) $row->notes];
            }
        }

        DB::table('scheduled_jobs')
            ->where('name', $name)
            ->update([
                'notes' => json_encode($this->recursiveMerge($current, $patch), JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function appendNoteOnce(string $notes, string $note): string
    {
        if (str_contains($notes, $note)) {
            return $notes;
        }

        $notes = trim($notes);

        return $notes === '' ? $note : $notes."\n".$note;
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    private function recursiveMerge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $base[$key] = $value;
                continue;
            }

            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->recursiveMerge($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
};
