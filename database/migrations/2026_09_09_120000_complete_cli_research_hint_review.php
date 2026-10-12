<?php

use App\Services\Genealogy\GenealogyService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RULE = ['target' => 'workflow', 'tree_scoped' => true,
        'references' => ['hint_id' => ['table' => 'genealogy_research_hints', 'id_column' => 'id', 'tree_column' => 'tree_id']]];

    public function up(): void
    {
        if (! Schema::hasTable('genealogy_research_hints')) {
            throw new RuntimeException('Load the PLOS skeletal schema before enabling research-hint review.');
        }
        if (! Schema::hasColumn('genealogy_research_hints', 'review_notes')) {
            Schema::table('genealogy_research_hints', function (Blueprint $table): void {
                $table->longText('review_notes')->nullable();
            });
        }
        $this->change(true);
    }

    public function down(): void
    {
        // Retain the additive column, notes and corrected parameter contract.
        // Ordinary code rollback must never erase an operator's review text.
        $this->change(false);
    }

    private function change(bool $install): void
    {
        $changed = DB::transaction(function () use ($install): bool {
            $tool = DB::table('agent_tool_registry')->where('name', 'update_hint_status')
                ->where('service_class', GenealogyService::class)->where('method', 'updateResearchHintStatus')->lockForUpdate()->first();
            if ($install && $tool !== null) {
                $parameters = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
                $before = $parameters;
                if (! isset($parameters['type']) && ! array_is_list($parameters)) {
                    if (($parameters['status']['type'] ?? null) === 'string' && ! array_key_exists('enum', $parameters['status'])) {
                        $parameters['status']['enum'] = ['pending', 'accepted', 'rejected', 'deferred'];
                    }
                    if (! array_key_exists('notes', $parameters)) {
                        $parameters['notes'] = ['type' => 'string', 'required' => false,
                            'description' => 'Review notes, preserved exactly. Omit to retain existing notes; an empty string clears them.'];
                    }
                }
                if ($parameters !== $before && DB::table('agent_tool_registry')->where('id', $tool->id)
                    ->whereRaw('CAST(parameters AS CHAR) = ?', [$tool->parameters])
                    ->update(['parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
                    throw new RuntimeException('Research-hint parameters changed during migration.');
                }
            }
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $before = $policy;
            if ($install && $tool !== null && ! array_key_exists('update_hint_status', $policy['tools'])) {
                $policy['tools']['update_hint_status'] = self::RULE;
            } elseif (! $install && ($policy['tools']['update_hint_status'] ?? null) === self::RULE) {
                unset($policy['tools']['update_hint_status']);
            }
            if ($policy === $before) {
                return false;
            }
            if (DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Framework service configuration changed during migration.');
            }

            return true;
        });
        if ($changed) {
            app(SystemConfigService::class)->forget('cli.framework_services');
        }
    }
};
