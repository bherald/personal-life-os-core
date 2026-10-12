<?php

use App\Services\Genealogy\GenealogyMediaIntakeRunService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function parameter(): array
    {
        return ['type' => 'boolean', 'default' => true, 'x_context_only' => true];
    }

    public function up(): void
    {
        $this->change(true);
    }

    public function down(): void
    {
        $this->change(false);
    }

    private function change(bool $install): void
    {
        DB::transaction(function () use ($install): void {
            $row = DB::table('agent_tool_registry')->where('name', 'media_intake_run')
                ->where('service_class', GenealogyMediaIntakeRunService::class)->where('method', 'run')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $parameters = json_decode($row->parameters, true, flags: JSON_THROW_ON_ERROR);
            if ($install) {
                if (array_key_exists('inventory_only', $parameters)) {
                    return;
                }
                $parameters['inventory_only'] = $this->parameter();
            } else {
                $parameter = $parameters['inventory_only'] ?? null;
                // Native MySQL JSON may reorder object keys; keep value types exact.
                if (! is_array($parameter) || count($parameter) !== 3
                    || ($parameter['type'] ?? null) !== 'boolean' || ($parameter['default'] ?? null) !== true
                    || ($parameter['x_context_only'] ?? null) !== true) {
                    return;
                }
                unset($parameters['inventory_only']);
            }
            if (DB::table('agent_tool_registry')->where('id', $row->id)
                ->whereRaw('CAST(parameters AS CHAR) = ?', [$row->parameters])
                ->update(['parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Intake tool parameters changed during migration.');
            }
        });
    }
};
