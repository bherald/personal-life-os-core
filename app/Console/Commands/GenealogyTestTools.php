<?php

namespace App\Console\Commands;

use App\Services\AgentToolRegistryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Genealogy Tool Test Harness
 *
 * Tests every tool registered for the genealogy-researcher agent directly
 * against a known-good person, reporting PASS/FAIL/SKIP with root-cause detail.
 *
 * Purpose: 3-minute feedback loop instead of 15-minute full agent run.
 * Run after every fix, before deploying. Catches regressions.
 *
 * Usage:
 *   php artisan genealogy:test-tools
 *   php artisan genealogy:test-tools --tool=detect_source_conflicts
 *   php artisan genealogy:test-tools --phase=analyze
 *   php artisan genealogy:test-tools --person=2661 --tree=4
 *   php artisan genealogy:test-tools --fail-only
 */
class GenealogyTestTools extends Command
{
    protected $signature = 'genealogy:test-tools
                            {--tool= : Test a single named tool}
                            {--phase= : Test only tools in this SKILL.md phase (assess|research|analyze|report)}
                            {--person=2661 : Person ID to use as test subject}
                            {--tree=4 : Tree ID for context}
                            {--fail-only : Only show failures and skips}
                            {--stop-on-fail : Stop after first failure}';

    protected $description = 'Test all genealogy-researcher tools directly — fast diagnostic loop (no full agent run needed)';

    // Tools skipped in automated testing (write/side-effect tools or require live data not in test DB)
    private const SKIP_TOOLS = [
        'propose_change',
        'propose_relationship',
        'submit_for_review',
        'rag_index',
        'post_agent_message',
        'create_research_task',        // creates DB rows
        'update_hint_status',          // mutates hint status
        'nara_download',               // downloads files
        'nara_download_best',          // downloads files
        'nara_copy_to_tree',           // copies files
        'transcribe_handwriting',      // GPU-heavy
        'transcribe_media_handwriting',// GPU-heavy
        'log_research_search',         // requires valid open task_id
        'fan_analyze_cluster',         // requires fan_cooccurrences data (none yet)
        'fan_suggest_research',        // requires fan_cooccurrences data (none yet)
        'wikitree_get_person',         // requires a valid WikiTree profile ID
        'wikitree_get_ancestors',      // requires a valid WikiTree profile ID
        'nara_get_objects',            // requires a valid NARA record ID
    ];

    // Tools that need a real query string
    private const QUERY_TOOLS = [
        'recall_procedures', 'recall_episodes',
        'mcp_searxng_search', 'mcp_genealogy_search',
        'newspaper_search', 'newspaper_search_obituaries',
        'internet_archive_search', 'nara_search',
        'wikitree_search',
        'ellis_island_search',
        'freedmens_bureau_search',
        'dar_search', 'german_church_records_search',
        'europeana_search', 'rag_search',
        'ai_research_person', 'ai_research_brick_wall',
        'surname_phonetic_matches', 'search_places',
        'source_search', 'source_search_all',
    ];

    // Tools in each SKILL.md phase
    private const PHASE_TOOLS = [
        'assess' => [
            'recall_procedures', 'recall_episodes', 'list_trees',
            'get_research_landscape', 'get_recent_searches', 'get_tree_statistics',
            'get_missing_data_report', 'get_research_hints', 'get_open_research_tasks',
            'mcp_genealogy_stats', 'list_persons', 'get_source_metrics',
        ],
        'research' => [
            'get_repositories_for_person', 'source_search_all', 'wikitree_search',
            'wikitree_get_person', 'wikitree_get_ancestors',
            'ellis_island_search', 'newspaper_search',
            'newspaper_search_obituaries', 'internet_archive_search', 'nara_search',
            'generate_record_hints', 'generate_tree_hints', 'mcp_searxng_search',
            'mcp_genealogy_search', 'rag_search', 'ai_research_person', 'ai_research_brick_wall',
            'htr_status',
        ],
        'analyze' => [
            'get_person', 'get_person_full', 'get_person_events', 'get_person_sources',
            'get_siblings', 'evidence_build_chain', 'assess_gps_compliance',
            'surname_phonetic_matches', 'resolve_place', 'search_places',
            'source_search', 'detect_duplicates', 'fan_analyze_cluster',
            'fan_suggest_research', 'fan_extract_cooccurrences', 'fan_get_cooccurrences',
            'map_ancestor_locations', 'map_migration_path', 'detect_source_conflicts',
            'get_source_conflicts', 'find_graph_duplicates', 'generate_gps_proof',
            'get_search_coverage', 'update_search_coverage',
        ],
        'report' => [
            'update_hint_status', 'create_research_task', 'log_research_search',
            'submit_for_review', 'propose_relationship', 'propose_change',
            'post_agent_message', 'rag_index',
        ],
    ];

    private array $results = [];

    public function handle(AgentToolRegistryService $registry): int
    {
        $personId = (int) $this->option('person');
        $treeId   = (int) $this->option('tree');
        $onlyTool = $this->option('tool');
        $onlyPhase = $this->option('phase');
        $failOnly  = $this->option('fail-only');
        $stopOnFail = $this->option('stop-on-fail');

        // Verify test person exists
        $person = DB::selectOne("SELECT id, given_name, surname FROM genealogy_persons WHERE id = ?", [$personId]);
        if (!$person) {
            $this->error("Test person {$personId} not found. Use --person=ID to specify a valid person.");
            return 1;
        }
        $personName = trim($person->given_name . ' ' . $person->surname);

        $this->info("Genealogy Tool Test Harness");
        $this->info("Subject: [{$personId}] {$personName} | Tree: {$treeId}");
        $this->line(str_repeat('─', 70));

        // Build test context (mirrors what AgentLoopService provides)
        $context = [
            'agent_id'    => 'genealogy-researcher',
            'tree_id'     => $treeId,
            'person_id'   => $personId,
            'task'        => "Test genealogy research for {$personName} (tree {$treeId})",
            'phase'       => 'test',
        ];

        // Determine which tools to test
        if ($onlyTool) {
            $toolsToTest = [$onlyTool];
        } elseif ($onlyPhase) {
            $toolsToTest = self::PHASE_TOOLS[$onlyPhase] ?? [];
            if (empty($toolsToTest)) {
                $this->error("Unknown phase '{$onlyPhase}'. Use: assess|research|analyze|report");
                return 1;
            }
        } else {
            // All phases
            $toolsToTest = array_unique(array_merge(...array_values(self::PHASE_TOOLS)));
        }

        $pass = $fail = $skip = $error = 0;

        foreach ($toolsToTest as $toolName) {
            if (in_array($toolName, self::SKIP_TOOLS)) {
                $skip++;
                if (!$failOnly) {
                    $this->line("  <fg=gray>SKIP</> {$toolName}");
                }
                continue;
            }

            $params = $this->buildTestParams($toolName, $personId, $treeId, $personName);
            $startMs = (int) round(microtime(true) * 1000);

            try {
                $result = $registry->executeTool($toolName, $params, $context);
                $durationMs = (int) round(microtime(true) * 1000) - $startMs;
                $success = $result['success'] ?? false;
                $resultText = $result['result_text'] ?? '';

                if ($success) {
                    $pass++;
                    $preview = mb_substr(strip_tags($resultText), 0, 80);
                    if (!$failOnly) {
                        $this->line(sprintf("  <fg=green>PASS</> %-45s %dms  %s",
                            $toolName, $durationMs, $preview));
                    }
                } else {
                    $fail++;
                    $this->line(sprintf("  <fg=red>FAIL</> %-45s %dms",
                        $toolName, $durationMs));
                    $this->line("       <fg=yellow>{$resultText}</>");
                    if ($stopOnFail) return 1;
                }

                $this->results[$toolName] = [
                    'status' => $success ? 'pass' : 'fail',
                    'ms' => $durationMs,
                    'detail' => $resultText,
                ];
            } catch (\Throwable $e) {
                $durationMs = (int) round(microtime(true) * 1000) - $startMs;
                $error++;
                $msg = $e->getMessage();
                $this->line(sprintf("  <fg=red>ERR </> %-45s %dms", $toolName, $durationMs));
                $this->line("       <fg=red>{$msg}</>");

                $this->results[$toolName] = [
                    'status' => 'error',
                    'ms' => $durationMs,
                    'detail' => $msg,
                ];
                if ($stopOnFail) return 1;
            }
        }

        // Summary
        $this->line(str_repeat('─', 70));
        $total = $pass + $fail + $skip + $error;
        $this->info("Results: {$pass} pass | {$fail} fail | {$error} error | {$skip} skip | {$total} total");

        if ($fail > 0 || $error > 0) {
            $this->warn("\nFailed/Error tools:");
            foreach ($this->results as $name => $r) {
                if (in_array($r['status'], ['fail', 'error'])) {
                    $this->line("  [{$r['status']}] {$name}: {$r['detail']}");
                }
            }
        }

        return ($fail > 0 || $error > 0) ? 1 : 0;
    }

    private function buildTestParams(string $toolName, int $personId, int $treeId, string $personName): array
    {
        $params = ['person_id' => $personId, 'tree_id' => $treeId];

        // Query-based tools
        if (in_array($toolName, self::QUERY_TOOLS)) {
            $params['query']   = $personName;
            $params['name']    = $personName;
            $parts = explode(' ', $personName);
            $params['surname'] = end($parts);
        }

        // Tool-specific overrides
        switch ($toolName) {
            case 'recall_procedures':
            case 'recall_episodes':
                $params['query'] = "genealogy research for {$personName}";
                break;

            case 'evidence_build_chain':
                $params['eventType'] = 'birth';
                break;

            case 'generate_gps_proof':
                $params['question'] = "What are the established facts about {$personName}?";
                break;

            case 'update_search_coverage':
                $params['positive']         = false;
                $params['repository_name']  = 'Test Run';
                $params['repository_type']  = 'other';
                break;

            case 'resolve_place':
                $params['place_string'] = 'Pennsylvania, USA';
                break;

            case 'assess_gps_compliance':
                // Use the first available open research task
                $task = DB::selectOne("SELECT id FROM gps_research_tasks LIMIT 1");
                $params['task_id'] = $task ? (int) $task->id : 1;
                break;

            case 'get_repositories_for_person':
            case 'get_search_coverage':
            case 'get_source_conflicts':
            case 'fan_analyze_cluster':
            case 'fan_suggest_research':
            case 'fan_get_cooccurrences':
            case 'fan_extract_cooccurrences':
            case 'detect_source_conflicts':
            case 'detect_duplicates':
            case 'find_graph_duplicates':
            case 'map_ancestor_locations':
            case 'map_migration_path':
            case 'assess_gps_compliance':
            case 'get_person':
            case 'get_person_full':
            case 'get_person_events':
            case 'get_person_sources':
            case 'get_siblings':
            case 'generate_record_hints':
            case 'get_open_research_tasks':
                // person_id already in params — no additional params needed
                break;

            case 'get_research_landscape':
            case 'get_recent_searches':
            case 'get_tree_statistics':
            case 'get_missing_data_report':
            case 'get_research_hints':
            case 'list_persons':
            case 'generate_tree_hints':
                $params = ['tree_id' => $treeId];
                break;

            case 'list_trees':
            case 'mcp_genealogy_stats':
            case 'get_source_metrics':
            case 'htr_status':
                $params = []; // no params needed
                break;
        }

        return $params;
    }
}
