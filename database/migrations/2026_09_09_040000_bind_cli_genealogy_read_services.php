<?php

use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const METHODS = [
        'get_person_full' => 'getPersonFull',
        'get_person_events' => 'getPersonEvents',
        'get_person_sources' => 'getPersonSources',
        'get_siblings' => 'getPersonSiblings',
        'search_persons' => 'searchPersons',
        'get_research_hints' => 'getResearchHints',
    ];

    private function rule(string $name): array
    {
        $rule = ['target' => 'workflow', 'tree_scoped' => true,
            'references' => ['person_id' => ['table' => 'genealogy_persons', 'id_column' => 'id', 'tree_column' => 'tree_id']]];
        if (in_array($name, ['search_persons', 'get_research_hints'], true)) {
            $rule['tree_argument'] = 'tree_id';
        }
        if ($name === 'search_persons') {
            $rule['references'] = [];
        }

        return $rule;
    }

    public function up(): void
    {
        $this->updateBindings(true);
    }

    public function down(): void
    {
        $this->updateBindings(false);
    }

    private function updateBindings(bool $install): void
    {
        $changed = DB::transaction(function () use ($install): bool {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $before = $policy;
            foreach (self::METHODS as $name => $method) {
                $rule = $this->rule($name);
                if ($install) {
                    // Preserve existing registry permissions, availability and operator bindings.
                    $registered = DB::table('agent_tool_registry')->where('name', $name)
                        ->where('service_class', 'App\\Services\\Genealogy\\GenealogyService')
                        ->where('method', $method)->exists();
                    if ($registered && ! array_key_exists($name, $policy['tools'])) {
                        $policy['tools'][$name] = $rule;
                    }
                } elseif (($policy['tools'][$name] ?? null) === $rule) {
                    unset($policy['tools'][$name]);
                }
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
