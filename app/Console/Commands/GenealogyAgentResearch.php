<?php

namespace App\Console\Commands;

use App\Services\AgentLoopService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenealogyAgentResearch extends Command
{
    protected $signature = 'genealogy:agent-research
                            {--tree= : Tree ID to research (required unless --all-trees)}
                            {--person= : Focus on a specific person ID}
                            {--task= : Custom task description for the agent}
                            {--limit=10 : Max persons for hint generation}
                            {--notify : Send Pushover notification on completion}
                            {--dry-run : Show what the agent would do without executing}
                            {--all-trees : Run for all trees with persons}';

    protected $description = 'Run the genealogy researcher AI agent to autonomously find and evaluate records';

    public function handle(AgentLoopService $agentLoop): int
    {
        $treeId = $this->option('tree');
        $personId = $this->option('person');
        $customTask = $this->option('task');
        $limit = (int) $this->option('limit');
        $notify = $this->option('notify');
        $dryRun = $this->option('dry-run');
        $allTrees = $this->option('all-trees');

        // Determine which trees to process
        $treeIds = [];
        if ($allTrees) {
            $trees = DB::select("SELECT DISTINCT tree_id FROM genealogy_persons WHERE tree_id IS NOT NULL");
            $treeIds = array_column($trees, 'tree_id');
            $this->info("Found " . count($treeIds) . " tree(s) to process");
        } elseif ($treeId) {
            $treeIds = [(int) $treeId];
        } else {
            $this->error('Must specify --tree=ID or --all-trees');
            return 1;
        }

        foreach ($treeIds as $currentTreeId) {
            $this->processTree($agentLoop, (int) $currentTreeId, $personId, $customTask, $limit, $notify, $dryRun);
        }

        return 0;
    }

    private function processTree(AgentLoopService $agentLoop, int $treeId, ?string $personId, ?string $customTask, int $limit, bool $notify, bool $dryRun): void
    {
        // Build the task description
        if ($customTask) {
            $task = $customTask;
        } elseif ($personId) {
            try {
                $person = DB::selectOne("SELECT given_name, surname FROM genealogy_persons WHERE id = ?", [$personId]);
                $personName = $person ? trim($person->given_name . ' ' . $person->surname) : "Person #{$personId}";
            } catch (\Throwable $e) {
                $personName = "Person #{$personId}";
            }
            $task = "Research {$personName} (person_id: {$personId}) in tree {$treeId}. "
                . "Use get_person to review their current data, then generate_record_hints to find new records. "
                . "Evaluate any existing pending hints with get_research_hints. "
                . "Report your findings with confidence scores.";
        } else {
            $task = "Research tree {$treeId}. You MUST research MULTIPLE persons (at least 3-5), not just one.\n\n"
                . "ASSESS phase: Call get_tree_statistics, get_missing_data_report, get_research_hints to survey the tree.\n"
                . "Select 3-5 persons with the most missing data (prioritize direct ancestors).\n\n"
                . "RESEARCH phase: For EACH selected person, use at least 3 DIFFERENT tools:\n"
                . "- generate_record_hints (database matching)\n"
                . "- newspaper_search OR newspaper_search_obituaries (LOC historical newspapers)\n"
                . "- internet_archive_search (Internet Archive genealogy collections)\n"
                . "- mcp_searxng_search with specific queries like '\"FirstName LastName\" site:findagrave.com'\n"
                . "- rag_search (local knowledge base)\n"
                . "- ai_research_person (AI-assisted deep research)\n"
                . "Move to the next person after 2-3 tool calls per person. Do NOT exhaust all iterations on one person.\n\n"
                . "ANALYZE phase: For persons where research found data, use get_person, evidence_build_chain,\n"
                . "assess_gps_compliance, surname_phonetic_matches, resolve_place, detect_duplicates.\n\n"
                . "REPORT phase: Use create_research_task for follow-ups, submit_for_review for findings,\n"
                . "log_research_search to document what was searched.\n\n"
                . "Use at least 10 different tools total. Summarize ALL findings per person.";
        }

        if ($dryRun) {
            $this->warn("[DRY RUN] Tree {$treeId}");
            $this->line("Task: {$task}");
            $this->line("Notify: " . ($notify ? 'yes' : 'no'));
            return;
        }

        $this->info("Starting genealogy researcher agent for tree {$treeId}...");

        $result = $agentLoop->execute('genealogy-researcher', $task, [
            'tree_id' => $treeId,
            'notify' => $notify,
            // max_iterations comes from SKILL.md (40) — don't override here
        ]);

        if ($result['success']) {
            $this->info("Agent completed in " . round($result['duration_ms'] / 1000, 1) . "s");
            $this->info("Tokens used: " . ($result['tokens_used'] ?? 0));
            $this->info("Tool calls: " . count($result['tool_calls'] ?? []));
            $this->info("Iterations: " . ($result['iterations'] ?? 1));

            if (!empty($result['tool_calls'])) {
                $this->line("\nTool calls made:");
                foreach ($result['tool_calls'] as $tc) {
                    $status = $tc['success'] ? 'OK' : 'FAIL';
                    $this->line("  [{$status}] {$tc['tool']}");
                }
            }

            $this->line("\n--- Agent Response ---");
            $this->line($result['response']);
        } else {
            $this->error("Agent failed: " . ($result['error'] ?? 'Unknown error'));
            return;
        }
    }
}
