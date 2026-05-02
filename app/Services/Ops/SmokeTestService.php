<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class SmokeTestService
{
    private array $results = [];

    private int $pass = 0;

    private int $fail = 0;

    private int $warn = 0;

    private bool $fix = false;

    private string $section = '';

    public function run(bool $quick = false, bool $fix = false): array
    {
        $this->reset($fix);
        $startTime = microtime(true);

        $this->checkToolRegistry();
        $this->checkScheduledJobs();
        $this->checkSkillFiles();

        if (! $quick) {
            $this->checkPipelineQueries();
            $this->checkLlmPool();
            $this->checkOfflinePolicy();
            $this->checkMorningDigest();
            $this->checkConfigKeys();
        }

        return [
            'pass' => $this->pass,
            'fail' => $this->fail,
            'warn' => $this->warn,
            'duration_s' => round(microtime(true) - $startTime, 1),
            'results' => $this->results,
        ];
    }

    private function reset(bool $fix): void
    {
        $this->results = [];
        $this->pass = 0;
        $this->fail = 0;
        $this->warn = 0;
        $this->fix = $fix;
        $this->section = '';
    }

    private function checkToolRegistry(): void
    {
        $this->section('Tool Registry');

        try {
            $tools = DB::select('
                SELECT name, service_class, method, mcp_server, mcp_tool, category
                FROM agent_tool_registry
                WHERE enabled = 1
            ');
        } catch (\Throwable $e) {
            $this->record('tool_registry', 'FAIL', 'Cannot query agent_tool_registry: '.$e->getMessage());

            return;
        }

        $broken = [];
        $mcpTools = 0;
        $serviceTools = 0;

        foreach ($tools as $tool) {
            if (! empty($tool->mcp_server) && ! empty($tool->mcp_tool)) {
                $mcpTools++;

                continue;
            }

            $serviceTools++;

            if (empty($tool->service_class)) {
                $broken[] = "{$tool->name}: no service_class defined";

                continue;
            }

            if (! class_exists($tool->service_class)) {
                $broken[] = "{$tool->name}: class not found — {$tool->service_class}";

                continue;
            }

            if (empty($tool->method)) {
                $broken[] = "{$tool->name}: no method defined";

                continue;
            }

            if (! method_exists($tool->service_class, $tool->method)) {
                $broken[] = "{$tool->name}: method not found — {$tool->service_class}::{$tool->method}()";
            }
        }

        $total = count($tools);
        $this->record('tool_registry_count', 'PASS', "{$total} enabled tools ({$serviceTools} service, {$mcpTools} MCP)");

        if (empty($broken)) {
            $this->record('tool_registry_resolve', 'PASS', 'All service tools resolve to valid class::method');
        } else {
            foreach ($broken as $issue) {
                $context = [];

                if ($this->fix) {
                    $toolName = explode(':', $issue)[0];
                    DB::update("UPDATE agent_tool_registry SET enabled = 0, notes = CONCAT(COALESCE(notes,''), ' [smoke-test disabled ".now()->toDateString()."]') WHERE name = ?", [trim($toolName)]);
                    $context['auto_disabled'] = trim($toolName);
                }

                $this->record('tool_registry_resolve', 'FAIL', $issue, $context);
            }
        }
    }

    private function checkScheduledJobs(): void
    {
        $this->section('Scheduled Jobs');

        try {
            $jobs = DB::select('
                SELECT id, name, command, job_type, timeout_minutes, cron_expression, enabled
                FROM scheduled_jobs
                WHERE enabled = 1
                ORDER BY name
            ');
        } catch (\Throwable $e) {
            $this->record('scheduled_jobs', 'FAIL', 'Cannot query scheduled_jobs: '.$e->getMessage());

            return;
        }

        $this->record('scheduled_jobs_count', 'PASS', count($jobs).' enabled jobs');

        $broken = [];
        $allCommands = collect(Artisan::all())->keys()->toArray();
        $skillDirs = glob(base_path(config('agents.skills_path', 'resources/agents/skills').'/*'), GLOB_ONLYDIR);
        $validSkills = array_map('basename', $skillDirs ?: []);

        foreach ($jobs as $job) {
            $command = $job->command ?? '';
            $jobType = $job->job_type ?? 'command';

            if (empty($command)) {
                continue;
            }

            if ($jobType === 'agent_task') {
                $skillName = trim($command);
                if (! in_array($skillName, $validSkills)) {
                    $broken[] = "{$job->name}: agent skill not found — '{$skillName}'";
                }
            } elseif ($jobType === 'command') {
                $parts = preg_split('/\s+/', trim($command));
                $idx = 0;
                foreach ($parts as $i => $part) {
                    if (in_array(strtolower($part), ['php', 'artisan', 'php8.3'])) {
                        $idx = $i + 1;
                    } else {
                        break;
                    }
                }
                $cmdName = $parts[$idx] ?? '';

                if (! empty($cmdName) && ! str_starts_with($cmdName, '--')) {
                    if (! in_array($cmdName, $allCommands)) {
                        $broken[] = "{$job->name}: command not found — '{$cmdName}'";
                    }
                }
            } elseif ($jobType === 'workflow') {
                try {
                    $wf = DB::selectOne(
                        'SELECT id FROM workflows WHERE id = ? OR name = ? COLLATE utf8mb4_unicode_ci LIMIT 1',
                        [is_numeric($command) ? (int) $command : 0, $command]
                    );
                    if (! $wf) {
                        $broken[] = "{$job->name}: workflow not found — '{$command}'";
                    }
                } catch (\Throwable) {
                    // Skip workflow check if query fails.
                }
            }

            if (($job->timeout_minutes ?? 0) <= 0) {
                $this->record('scheduled_jobs_timeout', 'WARN', "{$job->name}: timeout_minutes is {$job->timeout_minutes}");
            } elseif ($job->timeout_minutes > 480) {
                $this->record('scheduled_jobs_timeout', 'WARN', "{$job->name}: timeout_minutes={$job->timeout_minutes} (> 8h)");
            }
        }

        if (empty($broken)) {
            $this->record('scheduled_jobs_commands', 'PASS', 'All job commands resolve (artisan/agent/workflow)');
        } else {
            foreach ($broken as $issue) {
                $this->record('scheduled_jobs_commands', 'FAIL', $issue);
            }
        }
    }

    private function checkSkillFiles(): void
    {
        $this->section('Agent Skills');

        $basePath = base_path(config('agents.skills_path', 'resources/agents/skills'));
        if (! is_dir($basePath)) {
            $this->record('skills', 'FAIL', "Skills directory not found: {$basePath}");

            return;
        }

        $dirs = array_values(array_filter(
            glob($basePath.'/*', GLOB_ONLYDIR) ?: [],
            static fn (string $dir): bool => ! str_starts_with(basename($dir), '__')
        ));
        $this->record('skills_count', 'PASS', count($dirs).' agent skill directories');

        $enabledTools = [];
        try {
            $rows = DB::select('SELECT name FROM agent_tool_registry WHERE enabled = 1');
            $enabledTools = array_column($rows, 'name');
        } catch (\Throwable) {
            // Can't cross-reference without registry.
        }

        $missingSkillFiles = [];
        $parseErrors = [];
        $orphanedTools = [];

        foreach ($dirs as $dir) {
            $agentName = basename($dir);
            $skillFile = $dir.'/SKILL.md';

            if (! file_exists($skillFile)) {
                $missingSkillFiles[] = $agentName;

                continue;
            }

            $content = file_get_contents($skillFile);
            if (! preg_match('/^---\s*\n(.*?)\n---/s', $content, $matches)) {
                $parseErrors[] = "{$agentName}: no YAML frontmatter found";

                continue;
            }

            $yaml = $matches[1];
            if (preg_match('/^tools:\s*$/m', $yaml, $m, PREG_OFFSET_CAPTURE)) {
                $toolsStart = $m[0][1] + strlen($m[0][0]);
                $toolsSection = substr($yaml, $toolsStart);
                preg_match_all('/^  - (\w+)/m', $toolsSection, $toolMatches);
                $declaredTools = array_unique($toolMatches[1] ?? []);

                if (! empty($enabledTools)) {
                    foreach ($declaredTools as $tool) {
                        if (! in_array($tool, $enabledTools)) {
                            $orphanedTools[] = "{$agentName}/{$tool}";
                        }
                    }
                }
            }

            if (preg_match('/^id:\s*["\']?([^"\'\n]+)/m', $yaml, $idMatch)) {
                $id = trim($idMatch[1]);
                if ($id !== $agentName) {
                    $this->record('skills_id', 'WARN', "{$agentName}: SKILL.md id='{$id}' does not match directory name");
                }
            }
        }

        if (! empty($missingSkillFiles)) {
            foreach ($missingSkillFiles as $name) {
                $this->record('skills_missing', 'FAIL', "{$name}: SKILL.md file missing");
            }
        }

        if (! empty($parseErrors)) {
            foreach ($parseErrors as $err) {
                $this->record('skills_parse', 'FAIL', $err);
            }
        }

        if (! empty($orphanedTools)) {
            foreach ($orphanedTools as $tool) {
                $this->record('skills_orphaned_tool', 'WARN', "Tool declared in SKILL.md but not in registry: {$tool}");
            }
        }

        if (empty($missingSkillFiles) && empty($parseErrors)) {
            $this->record('skills_valid', 'PASS', 'All SKILL.md files found and parseable');
        }

        if (empty($orphanedTools) && ! empty($enabledTools)) {
            $this->record('skills_tools_linked', 'PASS', 'All SKILL.md tools exist in registry');
        }
    }

    private function checkPipelineQueries(): void
    {
        $this->section('Pipeline Queries');

        $imageExts = "'jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif'";
        $docExts = "'pdf','doc','docx','rtf','odt','txt','xls','xlsx','csv','ppt','pptx'";

        $queries = [
            'faces_backlog' => "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND face_scan_at IS NULL AND extension IN ({$imageExts})",
            'ai_backlog' => "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND ai_analyzed_at IS NULL AND extension IN ({$imageExts},{$docExts})",
            'phash_backlog' => "SELECT COUNT(*) as c FROM file_registry fr WHERE fr.status = 'active' AND fr.extension IN ({$imageExts}) AND NOT EXISTS (SELECT 1 FROM file_registry_perceptual_hashes ph WHERE ph.file_registry_id = fr.id)",
            'total_active' => "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active'",
            'total_images' => "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND extension IN ({$imageExts})",
        ];

        foreach ($queries as $name => $sql) {
            try {
                $result = DB::selectOne($sql);
                $count = (int) ($result->c ?? -1);
                $this->record("query_{$name}", 'PASS', "{$name} = {$count}");
            } catch (\Throwable $e) {
                $this->record("query_{$name}", 'FAIL', "{$name} query failed: ".$e->getMessage());
            }
        }

        $ragQueries = [
            'rag_documents' => 'SELECT COUNT(*) as c FROM rag_documents',
            'raptor_eligible' => 'SELECT COUNT(*) as c FROM rag_documents WHERE raptor_eligible = 1',
            'se_eligible' => 'SELECT COUNT(*) as c FROM rag_documents WHERE se_eligible = 1',
        ];

        foreach ($ragQueries as $name => $sql) {
            try {
                $result = DB::connection('pgsql_rag')->selectOne($sql);
                $count = (int) ($result->c ?? -1);
                $this->record("query_{$name}", 'PASS', "{$name} = {$count}");
            } catch (\Throwable $e) {
                $this->record("query_{$name}", 'FAIL', "{$name} (pgsql_rag) query failed: ".$e->getMessage());
            }
        }

        try {
            $row = DB::selectOne("
                SELECT COALESCE(SUM(r.items_processed), 0) as total
                FROM scheduled_job_runs r
                JOIN scheduled_jobs j ON j.id = r.scheduled_job_id
                WHERE j.name = 'file_enrich_ai'
                  AND r.completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $this->record('query_throughput_join', 'PASS', "AI throughput query works (24h total: {$row->total})");
        } catch (\Throwable $e) {
            $this->record('query_throughput_join', 'FAIL', 'Throughput query failed (scheduled_job_runs FK): '.$e->getMessage());
        }
    }

    private function checkLlmPool(): void
    {
        $this->section('LLM Pool');

        try {
            $stats = DB::selectOne("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN is_active = 1 AND is_healthy = 1 AND circuit_state != 'open' THEN 1 ELSE 0 END) as healthy,
                    SUM(CASE WHEN circuit_state = 'open' THEN 1 ELSE 0 END) as circuits_open,
                    SUM(CASE WHEN instance_type = 'ollama' AND is_active = 1 AND is_healthy = 1 THEN 1 ELSE 0 END) as ollama_healthy
                FROM llm_instances
            ");

            $this->record('llm_total', 'PASS', "{$stats->total} instances ({$stats->healthy} healthy, {$stats->circuits_open} circuits open)");

            $enabledJobs = (int) (DB::selectOne('SELECT COUNT(*) as c FROM scheduled_jobs WHERE enabled = 1')->c ?? 0);
            if ($stats->healthy == 0 && $enabledJobs == 0) {
                $this->record('llm_healthy', 'WARN', 'No healthy LLM providers (dev mode — all jobs disabled)');
            } elseif ($stats->healthy == 0) {
                $this->record('llm_healthy', 'FAIL', 'No healthy LLM providers — AI enrichment will stall');
            } elseif ($stats->ollama_healthy == 0) {
                $this->record('llm_ollama', 'WARN', 'No healthy Ollama instances — only external APIs available');
            } else {
                $this->record('llm_healthy', 'PASS', "Ollama healthy: {$stats->ollama_healthy}");
            }
        } catch (\Throwable $e) {
            $this->record('llm_pool', 'FAIL', 'Cannot query llm_instances: '.$e->getMessage());
        }
    }

    private function checkMorningDigest(): void
    {
        $this->section('Daily Report');

        try {
            $process = new Process(
                ['php', 'artisan', 'ops:daily-report', '--dry-run', '--no-fix', '--smoke', '--hours=1'],
                base_path()
            );
            $process->setTimeout(30);
            $process->run();

            if ($process->isSuccessful()) {
                $this->record('daily_report', 'PASS', 'Dry-run completed successfully');
            } elseif ($process->isStarted() && ! $process->isTerminated()) {
                $this->record('daily_report', 'FAIL', 'Dry-run timed out after 60s');
            } else {
                $output = trim($process->getErrorOutput() ?: $process->getOutput());
                $snippet = mb_substr($output, 0, 200);
                $this->record('daily_report', 'FAIL', "Dry-run exit code {$process->getExitCode()}: {$snippet}");
            }
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            $this->record('daily_report', 'FAIL', 'Dry-run timed out after 60s (killed)');
        } catch (\Throwable $e) {
            $this->record('daily_report', 'FAIL', 'Exception: '.$e->getMessage());
        }
    }

    private function checkOfflinePolicy(): void
    {
        $this->section('Offline Policy');

        try {
            $policy = app(\App\Services\OfflinePolicyService::class);
            $profile = $policy->activeProfile();
            $offline = $policy->isOfflineModeActive();
            $this->record('offline_state', 'PASS', "profile={$profile}, offline_mode=".($offline ? 'on' : 'off'));
        } catch (\Throwable $e) {
            $this->record('offline_state', 'FAIL', 'Cannot read offline policy state: '.$e->getMessage());

            return;
        }

        try {
            $exit = Artisan::call('offline:audit', [
                '--json' => true,
                '--hours' => 1,
                '--limit' => 1,
            ]);
            $decoded = json_decode(Artisan::output(), true);

            if ($exit === 0 && is_array($decoded) && isset($decoded['summary'])) {
                $denied = (int) ($decoded['summary']['denied'] ?? 0);
                $rate = (float) ($decoded['summary']['denied_per_hour'] ?? 0.0);
                $this->record('offline_audit', 'PASS', "denied={$denied}, rate={$rate}/h");
            } else {
                $this->record('offline_audit', 'FAIL', 'offline:audit did not return a JSON summary');
            }
        } catch (\Throwable $e) {
            $this->record('offline_audit', 'FAIL', 'offline:audit failed: '.$e->getMessage());
        }

        try {
            $tools = app(\App\Engine\MCPRouter::class)->getAvailableToolsForProfile('offline_review');
            $internetServers = ['research', 'puppeteer', 'web-research', 'searxng'];
            $leaked = array_values(array_unique(array_filter(array_map(
                static fn (array $tool) => in_array((string) ($tool['server'] ?? ''), $internetServers, true)
                    ? (string) $tool['server']
                    : null,
                $tools
            ))));

            if ($leaked === []) {
                $this->record('offline_catalog', 'PASS', 'offline_review catalog excludes internet MCP tools');
            } else {
                $this->record('offline_catalog', 'FAIL', 'offline_review catalog leaked: '.implode(', ', $leaked));
            }
        } catch (\Throwable $e) {
            $this->record('offline_catalog', 'FAIL', 'Profile catalog check failed: '.$e->getMessage());
        }
    }

    private function checkConfigKeys(): void
    {
        $this->section('Config Keys');

        $required = [
            'agents.max_loop_iterations',
            'agents.context_max_tokens',
            'services.ollama.api_url',
            'services.tika.url',
            'file_types.image',
            'file_types.document',
        ];

        $missing = [];
        foreach ($required as $key) {
            if (config($key) === null) {
                $missing[] = $key;
            }
        }

        if (empty($missing)) {
            $this->record('config_keys', 'PASS', count($required).' critical config keys present');
        } else {
            foreach ($missing as $key) {
                $this->record('config_keys', 'FAIL', "Missing config: {$key}");
            }
        }
    }

    private function section(string $name): void
    {
        $this->section = $name;
    }

    private function record(string $check, string $status, string $message, array $context = []): void
    {
        $result = [
            'section' => $this->section,
            'check' => $check,
            'status' => $status,
            'message' => $message,
        ];

        if ($context !== []) {
            $result = array_merge($result, $context);
        }

        $this->results[] = $result;

        match ($status) {
            'PASS' => $this->pass++,
            'FAIL' => $this->fail++,
            'WARN' => $this->warn++,
            default => null,
        };
    }
}
