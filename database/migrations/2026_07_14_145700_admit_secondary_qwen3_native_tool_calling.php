<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'ollama_secondary';

    private const MODEL = 'qwen3:8b';

    private const NOTE = '2026-07-14 RTC: exact ollama_secondary qwen3:8b native /api/chat canary returned a structured tool_calls function with validated city arguments; admitted for native PLOS tool routing independently of coding-task promotion.';

    public function up(): void
    {
        $this->updateModel(true);
    }

    public function down(): void
    {
        $this->updateModel(false);
    }

    private function updateModel(bool $admit): void
    {
        if (! Schema::hasTable('llm_instances') || ! Schema::hasTable('ollama_models')) {
            return;
        }

        $row = DB::table('ollama_models as models')
            ->join('llm_instances as instances', 'instances.id', '=', 'models.instance_id')
            ->where('instances.instance_id', self::INSTANCE_ID)
            ->where('models.model_name', self::MODEL)
            ->first(['models.id', 'models.capabilities', 'models.use_cases', 'models.vetting_notes']);
        if (! $row) {
            return;
        }

        $capabilities = $this->decodeList($row->capabilities);
        $useCases = $this->decodeList($row->use_cases);
        if ($admit) {
            $capabilities = $this->add($capabilities, ['tool_calling', 'tool_use']);
            $useCases = $this->add($useCases, ['native_tool_calling']);
            $notes = $this->appendNote((string) ($row->vetting_notes ?? ''), self::NOTE);
        } else {
            $capabilities = array_values(array_diff($capabilities, ['tool_calling', 'tool_use']));
            $useCases = array_values(array_diff($useCases, ['native_tool_calling']));
            $notes = trim(str_replace(self::NOTE, '', (string) ($row->vetting_notes ?? '')));
        }

        DB::table('ollama_models')->where('id', $row->id)->update([
            'capabilities' => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
            'use_cases' => json_encode($useCases, JSON_UNESCAPED_SLASHES),
            'vetting_notes' => $notes,
            'updated_at' => now(),
        ]);

        foreach (['llm_instances_all', 'llm_instances_healthy', 'ollama_available_models', 'ollama_vetted_models'] as $key) {
            Cache::forget($key);
        }
    }

    /** @return array<int,string> */
    private function decodeList(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        return is_array($decoded)
            ? array_values(array_unique(array_filter($decoded, 'is_string')))
            : [];
    }

    /** @param array<int,string> $values @param array<int,string> $additions */
    private function add(array $values, array $additions): array
    {
        return array_values(array_unique([...$values, ...$additions]));
    }

    private function appendNote(string $notes, string $note): string
    {
        if (str_contains($notes, $note)) {
            return $notes;
        }

        return trim($notes) === '' ? $note : rtrim($notes)."\n".$note;
    }
};
