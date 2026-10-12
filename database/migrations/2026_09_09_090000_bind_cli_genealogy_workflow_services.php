<?php

use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Exact existing implementations; all permission/approval policy stays in
    // the registry. This migration adds only target and record-scope wiring.
    private const TOOLS = [
        'list_trees' => ['GenealogyService', 'listTrees', null, null],
        'get_missing_data_report' => ['GenealogyService', 'getMissingDataReport', 'tree_id', null],
        'get_research_landscape' => ['GenealogyService', 'getResearchLandscape', 'tree_id', null],
        'get_priority_persons' => ['GenealogyService', 'getPriorityPersons', 'tree_id', null],
        'get_recent_searches' => ['ResearchTaskService', 'getRecentSearches', 'tree_id', 'person_id'],
        'get_search_coverage' => ['SearchCoverageService', 'getCoverageForPerson', null, 'person_id'],
        'update_search_coverage' => ['SearchCoverageService', 'updateCoverage', null, 'person_id'],
        'get_tree_coverage_gaps' => ['SearchCoverageService', 'getGapReport', 'tree_id', null],
        'fan_get_cooccurrences' => ['FANCooccurrenceService', 'getCooccurrences', null, 'person_id'],
        'get_source_conflicts' => ['SourceConflictService', 'getConflictsForPerson', null, 'person_id'],
        'get_repositories_for_person' => ['RepositoryRoutingService', 'getRepositoriesForPerson', null, 'person_id'],
        'source_search' => ['SourceCitationService', 'searchSources', 'treeId', null],
        'surname_phonetic_matches' => ['NameVariantService', 'findPhoneticMatches', 'treeId', null],
        'map_ancestor_locations' => ['HistoricalMapsService', 'getAncestorLocations', 'treeId', null],
        'rebuild_ancestor_paths' => ['GenealogyService', 'rebuildAncestorPaths', 'tree_id', 'root_person_id'],
        'refresh_person_coverage' => ['GenealogyService', 'refreshPersonCoverage', 'tree_id', null],
    ];

    private function rule(?string $tree, ?string $person): array
    {
        $rule = ['target' => 'workflow', 'tree_scoped' => $tree !== null || $person !== null,
            'references' => $person === null ? [] : [$person => [
                'table' => 'genealogy_persons', 'id_column' => 'id', 'tree_column' => 'tree_id',
            ]]];
        if ($tree !== null) {
            $rule['tree_argument'] = $tree;
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
            foreach (self::TOOLS as $name => [$service, $method, $tree, $person]) {
                $rule = $this->rule($tree, $person);
                if ($install) {
                    // Preserve existing registry permissions, availability and operator bindings.
                    $registered = DB::table('agent_tool_registry')->where('name', $name)
                        ->where('service_class', 'App\\Services\\Genealogy\\'.$service)
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
