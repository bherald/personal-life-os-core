<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'ollama_jetson';

    private const ROLE_CAPS = [
        'fast' => [
            'num_ctx' => 4096,
            'num_predict' => 384,
        ],
        'standard' => [
            'num_ctx' => 4096,
            'num_predict' => 512,
        ],
        'coding' => [
            'num_ctx' => 6144,
            'num_predict' => 768,
        ],
    ];

    private const ROLLBACK_ROLE_CAPS = [
        'fast' => [
            'num_ctx' => 8192,
            'num_predict' => 768,
        ],
        'standard' => [
            'num_ctx' => 8192,
            'num_predict' => 1024,
        ],
        'coding' => [
            'num_ctx' => 8192,
            'num_predict' => 1024,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $this->applyCaps(self::ROLE_CAPS, '2026-07-02 RTC: reduced Jetson Ollama context/output caps after small-model bakeoff rejected all candidates. Jetson host tuning now keeps clocks pinned, Ollama single-lane/warm, and Ubuntu trimmed for SSH/Ollama appliance use.');
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $this->applyCaps(self::ROLLBACK_ROLE_CAPS, '2026-07-02 RTC rollback: restored prior Jetson Ollama runtime caps.');
    }

    /**
     * @param  array<string,array<string,int>>  $caps
     */
    private function applyCaps(array $caps, string $note): void
    {
        $row = DB::table('llm_instances')
            ->where('instance_id', self::INSTANCE_ID)
            ->first(['config', 'notes']);

        if (! $row) {
            return;
        }

        $config = $this->decodeMap($row->config ?? '{}');
        $roleRuntime = $this->decodeMap($config['role_runtime'] ?? []);

        foreach ($caps as $role => $options) {
            $runtime = $this->decodeMap($roleRuntime[$role] ?? []);
            $runtimeOptions = $this->decodeMap($runtime['options'] ?? []);
            $runtime['options'] = array_replace($runtimeOptions, $options);
            $roleRuntime[$role] = $runtime;
        }

        $config['role_runtime'] = $roleRuntime;
        $config['default_options'] = array_replace(
            $this->decodeMap($config['default_options'] ?? []),
            [
                'num_ctx' => $caps['standard']['num_ctx'],
                'num_predict' => $caps['standard']['num_predict'],
            ]
        );
        $config['jetson'] = array_replace(
            $this->decodeMap($config['jetson'] ?? []),
            [
                'runtime_tuned_at' => '2026-07-02',
                'runtime_tuning_note' => $note,
            ]
        );

        DB::table('llm_instances')
            ->where('instance_id', self::INSTANCE_ID)
            ->update([
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'notes' => $this->appendNote($row->notes ?? null, $note),
                'updated_at' => now(),
            ]);

        $this->clearCaches();
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function appendNote(?string $existing, string $note): string
    {
        $existing = trim((string) $existing);

        if ($existing === '') {
            return $note;
        }

        if (str_contains($existing, $note)) {
            return $existing;
        }

        return $existing."\n".$note;
    }

    private function clearCaches(): void
    {
        foreach ([
            'llm_instances_all',
            'llm_instances_healthy',
            'llm_model_profiles',
            'ollama_available_models',
            'ollama_vetted_models',
        ] as $key) {
            Cache::forget($key);
        }
    }
};
