<?php

use App\Services\TypeSafeJevDecisionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function questionSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['type', 'instructions'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['noul', 'choice', 'score']],
                'instructions' => ['anyOf' => [['type' => 'string', 'minLength' => 1], ['type' => 'array']]],
                'criteria' => ['anyOf' => [['type' => 'array'], ['type' => 'object']]],
            ],
            'allOf' => [
                ['if' => ['properties' => ['type' => ['const' => 'choice']]], 'then' => ['required' => ['criteria']]],
                ['if' => ['properties' => ['type' => ['const' => 'score']]], 'then' => ['required' => ['criteria']]],
            ],
        ];
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            $tool = DB::table('agent_tool_registry')->where('name', 'typesafe_jev_decide')->lockForUpdate()->first();
            if ($tool === null || $tool->service_class !== TypeSafeJevDecisionService::class || $tool->method !== 'evaluate') {
                throw new RuntimeException('Unexpected TypeSafe tool binding.');
            }
            $schema = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
            if (($schema['properties']['questions']['additionalProperties'] ?? null) !== true) {
                return; // Preserve an operator-amended question contract.
            }
            $schema['properties']['questions']['additionalProperties'] = $this->questionSchema();
            DB::table('agent_tool_registry')->where('id', $tool->id)->update([
                'parameters' => json_encode($schema, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $tool = DB::table('agent_tool_registry')->where('name', 'typesafe_jev_decide')->lockForUpdate()->first();
            if ($tool === null || $tool->service_class !== TypeSafeJevDecisionService::class || $tool->method !== 'evaluate') {
                return;
            }
            $schema = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
            if (($schema['properties']['questions']['additionalProperties'] ?? null) !== $this->questionSchema()) {
                return; // Preserve later or operator-amended question contracts.
            }
            $schema['properties']['questions']['additionalProperties'] = true;
            DB::table('agent_tool_registry')->where('id', $tool->id)->update([
                'parameters' => json_encode($schema, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }
};
