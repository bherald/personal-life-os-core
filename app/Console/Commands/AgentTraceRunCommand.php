<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AgentTraceRunCommand extends Command
{
    protected $signature = 'agent:trace-run
        {--session= : Specific session_id to trace}
        {--agent=genealogy-researcher : Agent name to trace}
        {--last=1 : Nth most recent session (1=latest)}
        {--phase= : Dump full LLM output for a specific phase}
        {--raw : Include full tool result text, not truncated}
        {--json : Output machine-readable JSON}';

    protected $description = 'End-to-end trace and analysis of an agent run';

    public function handle(): int
    {
        $agentName = $this->option('agent');
        $sessionId = $this->option('session');
        $last = (int) $this->option('last');
        $phaseFilter = $this->option('phase');
        $raw = $this->option('raw');
        $jsonOutput = $this->option('json');

        // Step 1: Find the session
        $session = $this->findSession($agentName, $sessionId, $last);
        if (!$session) {
            $this->error("No session found for agent '{$agentName}'");
            return 1;
        }

        // Step 2: Load all episodes
        $episodes = DB::select("
            SELECT id, event_type, summary, details, tokens_used, duration_ms, created_at
            FROM agent_episodes
            WHERE session_id = ?
            ORDER BY created_at ASC, id ASC
        ", [$session->session_id]);

        // Step 3: Find matching scheduled_job_run
        $jobRun = $this->findJobRun($agentName, $session->created_at);

        // Step 4: Load episode summary
        $episodeSummary = DB::selectOne("
            SELECT task, summary, outcome, importance, tools_used, tool_count,
                   tokens_used, duration_ms, episode_count, notes, created_at
            FROM agent_episode_summaries
            WHERE session_id = ?
            ORDER BY created_at DESC LIMIT 1
        ", [$session->session_id]);

        // Build structured trace data
        $trace = $this->buildTrace($session, $episodes, $jobRun, $episodeSummary);

        if ($jsonOutput) {
            $this->line(json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        // If --phase specified, dump that phase's full LLM output
        if ($phaseFilter) {
            $this->dumpPhaseOutput($trace, $phaseFilter);
            return 0;
        }

        // Render formatted output
        $this->renderTrace($trace, $raw);

        return 0;
    }

    private function findSession(string $agentName, ?string $sessionId, int $last): ?object
    {
        if ($sessionId) {
            return DB::selectOne("
                SELECT * FROM agent_sessions WHERE session_id = ?
            ", [$sessionId]);
        }

        return DB::selectOne("
            SELECT * FROM agent_sessions
            WHERE agent_name = ?
            ORDER BY created_at DESC
            LIMIT 1 OFFSET ?
        ", [$agentName, $last - 1]);
    }

    private function findJobRun(string $agentName, string $sessionCreatedAt): ?object
    {
        return DB::selectOne("
            SELECT sjr.id, sjr.started_at, sjr.completed_at, sjr.status,
                   LEFT(sjr.output, 2000) as output, sjr.duration_seconds, sjr.triggered_by,
                   sj.name, sj.timeout_minutes
            FROM scheduled_job_runs sjr
            JOIN scheduled_jobs sj ON sj.id = sjr.scheduled_job_id
            WHERE sj.name LIKE ?
              AND sj.job_type = 'agent_task'
              AND sjr.started_at <= DATE_ADD(?, INTERVAL 5 MINUTE)
              AND sjr.started_at >= DATE_SUB(?, INTERVAL 5 MINUTE)
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, sjr.started_at, ?)) ASC
            LIMIT 1
        ", ["%{$agentName}%", $sessionCreatedAt, $sessionCreatedAt, $sessionCreatedAt]);
    }

    private function buildTrace(object $session, array $episodes, ?object $jobRun, ?object $summary): array
    {
        $metadata = json_decode($session->metadata ?? '{}', true) ?: [];
        $phases = [];
        $currentPhase = null;
        $toolCalls = [];
        $events = [];
        $validationIssues = [];
        $bottlenecks = [];

        foreach ($episodes as $ep) {
            $details = json_decode($ep->details ?? '{}', true) ?: [];
            $event = [
                'id' => $ep->id,
                'type' => $ep->event_type,
                'summary_preview' => mb_substr($ep->summary ?? '', 0, 200),
                'full_summary' => $ep->summary,
                'details' => $details,
                'tokens' => $ep->tokens_used,
                'duration_ms' => $ep->duration_ms,
                'created_at' => $ep->created_at,
            ];
            $events[] = $event;

            switch ($ep->event_type) {
                case 'phase_started':
                    $currentPhase = $details['phase'] ?? 'unknown';
                    $phases[$currentPhase] = [
                        'name' => $currentPhase,
                        'started_at' => $ep->created_at,
                        'ended_at' => null,
                        'tools_available' => $details['tools'] ?? [],
                        'tool_calls' => [],
                        'llm_output' => null,
                        'llm_json' => null,
                        'model' => null,
                        'provider' => null,
                        'elapsed_sec' => 0,
                    ];
                    break;

                case 'tool_call':
                    $toolName = $details['tool'] ?? 'unknown';
                    $phase = $details['phase'] ?? $currentPhase ?? 'unknown';
                    $call = [
                        'tool' => $toolName,
                        'phase' => $phase,
                        'params' => $details['params'] ?? [],
                        'success' => $details['success'] ?? null,
                        'duration_ms' => $details['duration_ms'] ?? null,
                        'tool_result' => $details['tool_result'] ?? null,
                        'created_at' => $ep->created_at,
                        'summary' => mb_substr($ep->summary ?? '', 0, 300),
                    ];
                    $toolCalls[] = $call;
                    if (isset($phases[$phase])) {
                        $phases[$phase]['tool_calls'][] = $call;
                    }
                    break;

                case 'phase_completed':
                    $phase = $details['phase'] ?? $currentPhase ?? 'unknown';
                    if (isset($phases[$phase])) {
                        $phases[$phase]['ended_at'] = $ep->created_at;
                        $phases[$phase]['model'] = $details['model'] ?? null;
                        $phases[$phase]['provider'] = $details['provider'] ?? null;
                        // Extract LLM output from summary (after "Phase 'X' analyzed: " prefix)
                        $llmOutput = $ep->summary ?? '';
                        if (preg_match("/^Phase '[^']+' analyzed: (.*)$/s", $llmOutput, $m)) {
                            $llmOutput = $m[1];
                        }
                        $phases[$phase]['llm_output'] = $llmOutput;
                        $phases[$phase]['llm_json'] = $this->extractPhaseJson($llmOutput);

                        // Calculate elapsed
                        if ($phases[$phase]['started_at']) {
                            $start = strtotime($phases[$phase]['started_at']);
                            $end = strtotime($ep->created_at);
                            $phases[$phase]['elapsed_sec'] = max(0, $end - $start);
                        }
                    }
                    break;

                case 'task_completed':
                    // Final stats
                    break;

                case 'loop_detected':
                case 'hallucination_blocked':
                case 'budget_exceeded':
                case 'killed':
                case 'error':
                    $bottlenecks[] = [
                        'type' => $ep->event_type,
                        'summary' => mb_substr($ep->summary ?? '', 0, 300),
                        'details' => $details,
                        'at' => $ep->created_at,
                    ];
                    break;
            }
        }

        // Compute approximate per-tool timing from consecutive timestamps
        foreach ($phases as &$phase) {
            $calls = $phase['tool_calls'];
            for ($i = 0; $i < count($calls); $i++) {
                if ($i + 1 < count($calls)) {
                    $gap = strtotime($calls[$i + 1]['created_at']) - strtotime($calls[$i]['created_at']);
                    $phase['tool_calls'][$i]['approx_duration_sec'] = max(0, $gap);
                } elseif ($phase['ended_at']) {
                    $gap = strtotime($phase['ended_at']) - strtotime($calls[$i]['created_at']);
                    $phase['tool_calls'][$i]['approx_duration_sec'] = max(0, $gap);
                }
            }
        }
        unset($phase);

        // Analyze report phase validation
        $reportPhase = $phases['report'] ?? null;
        if ($reportPhase && $reportPhase['llm_json']) {
            $json = $reportPhase['llm_json'];
            $proposedChanges = count($json['proposed_changes'] ?? []);
            $proposedRels = count($json['proposed_relationships'] ?? []);
            $proposedMarriages = count($json['proposed_marriages'] ?? []);
            $personsResearched = $json['persons_researched'] ?? $json['persons_found'] ?? [];

            $validationIssues['proposals'] = [
                'proposed_changes' => $proposedChanges,
                'proposed_relationships' => $proposedRels,
                'proposed_marriages' => $proposedMarriages,
                'has_proposals' => ($proposedChanges + $proposedRels + $proposedMarriages) > 0,
            ];

            // Re-run hasFindings logic
            $hasFindings = false;
            $findingsDetail = [];
            foreach ($personsResearched as $p) {
                if (!is_array($p)) continue;
                $findings = $p['findings'] ?? '';
                if (!is_string($findings)) {
                    $findings = is_array($findings) ? implode('; ', array_filter($findings)) : (string) $findings;
                }
                $trimmed = trim($findings);
                $isNegative = preg_match(
                    '/^(none|nothing|zero|empty$)|^no records|^no results|found no records|found nothing|negative result|exhaustive search.*no|could not locate|unable to find/i',
                    $trimmed
                ) && !preg_match(
                    '/\b(but found|however found|did find|also found|found a|found the|found one|found evidence|record found|records found)\b/i',
                    $trimmed
                );
                $hasPositiveEvidence = (bool) preg_match(
                    '/(found|record|census|certificate|document|source|born|died|married|buried|\d{4})/i',
                    $findings
                );
                $triggersHasFindings = strlen($findings) > 30 && !$isNegative && $hasPositiveEvidence;
                $findingsDetail[] = [
                    'id' => $p['id'] ?? '?',
                    'name' => $p['name'] ?? 'Unknown',
                    'findings_length' => strlen($findings),
                    'findings_preview' => mb_substr($findings, 0, 200),
                    'is_negative' => $isNegative,
                    'has_positive_evidence' => $hasPositiveEvidence,
                    'triggers_has_findings' => $triggersHasFindings,
                ];
                if ($triggersHasFindings) {
                    $hasFindings = true;
                }
            }
            $validationIssues['findings_analysis'] = $findingsDetail;
            $validationIssues['has_findings'] = $hasFindings;
            $validationIssues['would_fail_validation'] = $hasFindings && !$validationIssues['proposals']['has_proposals'];

            // Check for fallback synthesis markers
            $taskCompleted = collect($events)->firstWhere('type', 'task_completed');
            if ($taskCompleted) {
                $tcSummary = $taskCompleted['full_summary'] ?? '';
                $validationIssues['fallback_synthesis'] = str_contains($tcSummary, 'agent-synthesized')
                    || str_contains($tcSummary, 'Synthesized proposals');
            }
        }

        // Slow phase detection
        foreach ($phases as $phaseName => $phase) {
            if ($phase['elapsed_sec'] > 900) {
                $bottlenecks[] = [
                    'type' => 'slow_phase',
                    'summary' => "Phase '{$phaseName}' took " . round($phase['elapsed_sec'] / 60, 1) . " minutes",
                    'at' => $phase['started_at'],
                ];
            }
        }

        // Tool frequency analysis
        $toolFreq = [];
        foreach ($toolCalls as $tc) {
            $key = $tc['phase'] . ':' . $tc['tool'];
            $toolFreq[$key] = ($toolFreq[$key] ?? 0) + 1;
        }
        arsort($toolFreq);

        $sessionDuration = 0;
        if ($session->created_at && $session->last_activity_at) {
            $sessionDuration = strtotime($session->last_activity_at) - strtotime($session->created_at);
        }

        return [
            'session' => [
                'id' => $session->id,
                'session_id' => $session->session_id,
                'agent' => $session->agent_name,
                'status' => $session->status,
                'skill_version' => $session->skill_version,
                'total_tokens' => $session->total_tokens,
                'message_count' => $session->message_count,
                'tree_id' => $metadata['tree_id'] ?? null,
                'started_at' => $session->created_at,
                'ended_at' => $session->last_activity_at,
                'duration_sec' => $sessionDuration,
            ],
            'job_run' => $jobRun ? [
                'id' => $jobRun->id,
                'job_name' => $jobRun->name,
                'status' => $jobRun->status,
                'started_at' => $jobRun->started_at,
                'completed_at' => $jobRun->completed_at,
                'duration_sec' => $jobRun->duration_seconds,
                'timeout_min' => $jobRun->timeout_minutes,
                'output_preview' => mb_substr($jobRun->output ?? '', 0, 500),
            ] : null,
            'phases' => $phases,
            'tool_calls_total' => count($toolCalls),
            'tool_frequency' => $toolFreq,
            'validation' => $validationIssues,
            'bottlenecks' => $bottlenecks,
            'episode_summary' => $summary ? [
                'task' => $summary->task,
                'outcome' => $summary->outcome,
                'importance' => $summary->importance,
                'tool_count' => $summary->tool_count,
                'tokens_used' => $summary->tokens_used,
                'duration_ms' => $summary->duration_ms,
                'hybrid_metrics' => json_decode($summary->notes ?? 'null', true),
            ] : null,
        ];
    }

    private function extractPhaseJson(string $content): ?array
    {
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $content, $m)) {
            return json_decode($m[1], true);
        }
        if (preg_match('/^\s*(\{[\s\S]*\})\s*$/m', $content, $m)) {
            return json_decode($m[1], true);
        }
        return null;
    }

    private function dumpPhaseOutput(array $trace, string $phaseName): void
    {
        $phase = $trace['phases'][$phaseName] ?? null;
        if (!$phase) {
            $this->error("Phase '{$phaseName}' not found. Available: " . implode(', ', array_keys($trace['phases'])));
            return;
        }

        $this->info("=== Phase: {$phaseName} ===");
        $this->line("Started: {$phase['started_at']} | Ended: {$phase['ended_at']} | Elapsed: {$phase['elapsed_sec']}s");
        $provider = $phase['provider'] ?? null;
        $modelStr = ($phase['model'] ?? 'N/A') . ($provider ? " ({$provider})" : '');
        $this->line("Tool calls: " . count($phase['tool_calls']) . " | Model: {$modelStr}");
        $this->newLine();

        $this->info("--- Tool Calls ---");
        foreach ($phase['tool_calls'] as $tc) {
            $dur = isset($tc['approx_duration_sec']) ? " (~{$tc['approx_duration_sec']}s)" : '';
            $durMs = isset($tc['duration_ms']) ? " [{$tc['duration_ms']}ms]" : '';
            $status = isset($tc['success']) ? ($tc['success'] ? 'OK' : 'FAIL') : '?';
            $this->line("  [{$tc['created_at']}] [{$status}] {$tc['tool']}{$dur}{$durMs}");
            if (!empty($tc['tool_result'])) {
                $this->line("    Result: " . mb_substr($tc['tool_result'], 0, 200));
            }
        }
        $this->newLine();

        $this->info("--- LLM Output ---");
        $this->line($phase['llm_output'] ?? '(no output captured)');

        if ($phase['llm_json']) {
            $this->newLine();
            $this->info("--- Parsed JSON Keys ---");
            foreach ($phase['llm_json'] as $key => $val) {
                $preview = is_array($val) ? 'array(' . count($val) . ')' : mb_substr((string) $val, 0, 100);
                $this->line("  {$key}: {$preview}");
            }
        }
    }

    private function renderTrace(array $trace, bool $raw): void
    {
        $s = $trace['session'];
        $dur = $s['duration_sec'] > 0 ? gmdate('H:i:s', $s['duration_sec']) : 'N/A';

        $this->info("=== Agent Trace: {$s['agent']} ===");
        $this->line("Session: {$s['session_id']} (v{$s['skill_version']})");
        $this->line("Status: {$s['status']} | Tokens: {$s['total_tokens']} | Duration: {$dur} | Tree: " . ($s['tree_id'] ?? 'N/A'));
        $this->line("Started: {$s['started_at']} | Ended: {$s['ended_at']}");

        if ($trace['job_run']) {
            $jr = $trace['job_run'];
            $this->line("Job: #{$jr['id']} ({$jr['job_name']}) — {$jr['status']} | Timeout: {$jr['timeout_min']}min");
        }

        $this->newLine();
        $this->info("=== Phase Timeline ===");

        foreach ($trace['phases'] as $name => $phase) {
            $elapsed = $phase['elapsed_sec'] > 60
                ? round($phase['elapsed_sec'] / 60, 1) . 'min'
                : $phase['elapsed_sec'] . 's';
            $toolCount = count($phase['tool_calls']);
            $model = $phase['model'] ?? '?';

            $provider = $phase['provider'] ?? null;
            $modelProvider = $model . ($provider ? " ({$provider})" : '');
            $this->line("  [{$name}] {$phase['started_at']} → {$phase['ended_at']} ({$elapsed}) — {$toolCount} tools — model: {$modelProvider}");

            // Top tools in this phase
            $toolNames = array_count_values(array_column($phase['tool_calls'], 'tool'));
            arsort($toolNames);
            $topTools = array_slice($toolNames, 0, 5, true);
            if (!empty($topTools)) {
                $toolStr = implode(', ', array_map(fn($t, $c) => "{$t}({$c})", array_keys($topTools), $topTools));
                $this->line("    Top tools: {$toolStr}");
            }

            // Show JSON summary for phase output
            if ($phase['llm_json']) {
                $json = $phase['llm_json'];
                $keys = [];
                if (isset($json['persons_researched'])) $keys[] = 'persons:' . count($json['persons_researched']);
                if (isset($json['persons_found'])) $keys[] = 'persons:' . count($json['persons_found']);
                if (isset($json['key_findings'])) $keys[] = 'findings:' . count($json['key_findings']);
                if (isset($json['next_phase_targets'])) $keys[] = 'targets:' . count($json['next_phase_targets']);
                if (isset($json['proposed_changes'])) $keys[] = 'proposals:' . count($json['proposed_changes']);
                if (isset($json['proposed_relationships'])) $keys[] = 'relationships:' . count($json['proposed_relationships']);
                if (isset($json['proposed_marriages'])) $keys[] = 'marriages:' . count($json['proposed_marriages']);
                if (!empty($keys)) {
                    $this->line("    Output: " . implode(' | ', $keys));
                }
            }
        }

        // Tool frequency
        $this->newLine();
        $this->info("=== Tool Call Summary ({$trace['tool_calls_total']} total) ===");
        $top10 = array_slice($trace['tool_frequency'], 0, 10, true);
        foreach ($top10 as $key => $count) {
            $this->line("  {$key}: {$count}");
        }

        // Validation analysis
        if (!empty($trace['validation'])) {
            $v = $trace['validation'];
            $this->newLine();
            $this->info("=== Report Phase Validation ===");

            if (isset($v['proposals'])) {
                $p = $v['proposals'];
                $this->line("  Proposals: changes={$p['proposed_changes']} rels={$p['proposed_relationships']} marriages={$p['proposed_marriages']}");
                $this->line("  Has proposals: " . ($p['has_proposals'] ? 'YES' : 'NO'));
            }

            $this->line("  Has findings: " . ($v['has_findings'] ? 'YES' : 'NO'));
            $this->line("  Would fail validation: " . (($v['would_fail_validation'] ?? false) ? 'YES — LLM found data but produced no proposals' : 'NO'));

            if (!empty($v['findings_analysis'])) {
                $this->newLine();
                $this->line("  Persons findings analysis:");
                foreach ($v['findings_analysis'] as $fa) {
                    $trigger = $fa['triggers_has_findings'] ? 'TRIGGERS' : 'skip';
                    $neg = $fa['is_negative'] ? 'NEG' : 'pos';
                    $this->line("    #{$fa['id']} {$fa['name']} [{$trigger}] ({$neg}, {$fa['findings_length']}ch)");
                    if ($raw || $fa['triggers_has_findings']) {
                        $this->line("      " . $fa['findings_preview']);
                    }
                }
            }

            if (isset($v['fallback_synthesis'])) {
                $this->line("  Fallback synthesis used: " . ($v['fallback_synthesis'] ? 'YES' : 'NO'));
            }
        }

        // Bottlenecks
        if (!empty($trace['bottlenecks'])) {
            $this->newLine();
            $this->warn("=== Bottlenecks & Issues ===");
            foreach ($trace['bottlenecks'] as $b) {
                $this->line("  [{$b['type']}] {$b['summary']}");
            }
        }

        // Episode summary
        if ($trace['episode_summary']) {
            $es = $trace['episode_summary'];
            $this->newLine();
            $this->info("=== Episode Summary ===");
            $this->line("  Outcome: {$es['outcome']} | Importance: {$es['importance']} | Tools: {$es['tool_count']}");
            $durMs = $es['duration_ms'] > 0 ? round($es['duration_ms'] / 1000) . 's' : 'N/A';
            $this->line("  Tokens: {$es['tokens_used']} | Duration: {$durMs}");

            // Quality metrics from hybrid run (persisted to episode summary notes)
            if (!empty($es['hybrid_metrics'])) {
                $hm = $es['hybrid_metrics'];
                $this->newLine();
                $this->info("=== Quality Metrics ===");
                $this->line("  Templates detected: " . ($hm['template_detections'] ?? 0)
                    . " | Escalations: " . ($hm['claude_escalations'] ?? 0)
                    . " | Proposals filtered: " . ($hm['proposals_filtered'] ?? 0));
                $this->line("  Review items: " . ($hm['review_items_submitted'] ?? 0)
                    . " | Phases: " . ($hm['phases_completed'] ?? 0) . "/" . ($hm['total_phases'] ?? 0));

                if (!empty($hm['phase_providers'])) {
                    $providerParts = [];
                    foreach ($hm['phase_providers'] as $phase => $provider) {
                        $providerParts[] = "{$phase}={$provider}";
                    }
                    $this->line("  Providers: " . implode(', ', $providerParts));
                }

                if (!empty($hm['review_item_types'])) {
                    $typeParts = [];
                    foreach ($hm['review_item_types'] as $type => $count) {
                        $typeParts[] = "{$count} {$type}";
                    }
                    $this->line("  Review types: " . implode(', ', $typeParts));
                }
            }
        }

        $this->newLine();
        $this->info("Tip: Use --phase=report to see full LLM output | --json for machine-readable | --raw for full findings text");
    }
}
