<?php

namespace App\Console\Commands;

use App\Engine\MCPRouter;
use App\Services\AIService;
use App\Services\DevAgent\TraceEnvelopeService;
use App\Services\LLMPoolManagerService;
use App\Services\OfflinePolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\BufferedOutput;

class OfflineDevAssistCommand extends Command
{
    private const EXIT_SHELL = 255;

    protected $signature = 'offline:dev-assist
        {prompt?* : Prompt to run once. If omitted, starts an interactive shell}
        {--profile= : Activate a routing profile before starting}
        {--role=coding : Session model role (fast|standard|quality|coding|vision|embedding|uncensored)}
        {--approval=read-only : Session approval mode (read-only|repo-write)}
        {--max-iterations=5 : Maximum MCP/tool iterations per request}
        {--json : Emit JSON for one-shot calls}';

    protected $description = 'Offline/local development assistant shell with profile-filtered MCP tools and slash commands.';

    private const VALID_ROLES = [
        'fast',
        'standard',
        'quality',
        'coding',
        'vision',
        'embedding',
        'uncensored',
    ];

    private const DEFAULT_APPROVAL_MODE = 'read-only';

    private const VALID_APPROVAL_MODES = [
        'read-only',
        'repo-write',
    ];

    private const DEV_TOOL_SERVERS = [
        'repo-dev',
        'serena',
        'code-review',
        'plos',
        'time',
    ];

    private const TRACEABLE_SLASH_COMMANDS = [
        '/doctor',
        '/tools',
        '/mcp',
        '/git',
        '/preflight',
        '/approval',
    ];

    public function handle(
        AIService $ai,
        MCPRouter $router,
        OfflinePolicyService $policy,
        LLMPoolManagerService $pool,
        TraceEnvelopeService $traces,
    ): int {
        $prompt = $this->readPromptArgument();
        $json = (bool) $this->option('json');

        if ($json && $prompt === '') {
            $this->error('--json requires a one-shot prompt or slash command.');

            return self::FAILURE;
        }

        $role = $this->normalizeRole((string) $this->option('role'));
        if ($role === null) {
            $this->error('Unknown role. Allowed: '.implode(', ', self::VALID_ROLES));

            return self::FAILURE;
        }

        $approvalMode = $this->normalizeApprovalMode((string) $this->option('approval'));
        if ($approvalMode === null) {
            $this->error('Unknown approval mode. Allowed: '.implode(', ', self::VALID_APPROVAL_MODES));

            return self::FAILURE;
        }

        if (! $this->activateProfileIfRequested((string) ($this->option('profile') ?? ''))) {
            return self::FAILURE;
        }

        $session = [
            'role' => $role,
            'approval_mode' => $approvalMode,
            'history' => [],
        ];

        if ($prompt !== '') {
            $exit = $this->handleInput(
                input: $prompt,
                session: $session,
                ai: $ai,
                router: $router,
                policy: $policy,
                pool: $pool,
                traces: $traces,
                emitJson: $json,
            );

            return $exit === self::EXIT_SHELL ? self::SUCCESS : $exit;
        }

        $this->renderBanner($policy->activeProfile(), $role, $approvalMode);

        while (true) {
            $line = $this->ask($this->promptLabel($policy->activeProfile(), (string) $session['role'], (string) $session['approval_mode']));
            if ($line === null) {
                $this->newLine();

                return self::SUCCESS;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $exit = $this->handleInput(
                input: $line,
                session: $session,
                ai: $ai,
                router: $router,
                policy: $policy,
                pool: $pool,
                traces: $traces,
                emitJson: false,
            );

            if ($exit === self::EXIT_SHELL) {
                return self::SUCCESS;
            }

            if ($exit !== self::SUCCESS) {
                return $exit;
            }
        }
    }

    /**
     * @param  array{role:string,approval_mode:string,history:array<int,array<string,string>>}  $session
     */
    private function handleInput(
        string $input,
        array &$session,
        AIService $ai,
        MCPRouter $router,
        OfflinePolicyService $policy,
        LLMPoolManagerService $pool,
        TraceEnvelopeService $traces,
        bool $emitJson,
    ): int {
        if (str_starts_with($input, '/')) {
            $result = $this->handleSlashCommand($input, $session, $router, $policy, $pool);
            $result = $this->appendSlashCommandTrace(
                result: $result,
                session: $session,
                traces: $traces,
                policy: $policy,
            );

            if (($result['exit_shell'] ?? false) === true) {
                if ($emitJson) {
                    $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    $this->renderSlashResult($result);
                }

                return self::EXIT_SHELL;
            }

            if (($result['type'] ?? null) === 'handoff' && ($result['defer_to_model'] ?? false) === true) {
                $profile = $policy->activeProfile();
                $tools = $router->getAvailableToolsForProfile($profile);
                $payload = $this->runAssistantRequest(
                    request: (string) $result['request'],
                    mode: (string) $result['mode'],
                    session: $session,
                    ai: $ai,
                    profile: $profile,
                    availableTools: $tools,
                    maxIterations: (int) $this->option('max-iterations'),
                    traces: $traces,
                );

                if ($emitJson) {
                    $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    $this->line((string) $payload['response']);
                }

                return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
            }

            if ($emitJson) {
                $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->renderSlashResult($result);
            }

            return self::SUCCESS;
        }

        $shortcut = $this->shortcutSlashCommand($input);
        if ($shortcut !== null) {
            return $this->handleInput($shortcut, $session, $ai, $router, $policy, $pool, $traces, $emitJson);
        }

        $profile = $policy->activeProfile();
        $tools = $this->sessionTools($profile, $router, $session);
        $mode = 'ask';

        $payload = $this->runAssistantRequest(
            request: $input,
            mode: $mode,
            session: $session,
            ai: $ai,
            profile: $profile,
            availableTools: $tools,
            maxIterations: (int) $this->option('max-iterations'),
            traces: $traces,
        );

        if ($emitJson) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line((string) $payload['response']);
        }

        return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{role:string,approval_mode:string,history:array<int,array<string,string>>}  $session
     * @return array<string,mixed>
     */
    private function handleSlashCommand(
        string $input,
        array &$session,
        MCPRouter $router,
        OfflinePolicyService $policy,
        LLMPoolManagerService $pool,
    ): array {
        [$command, $tail] = $this->parseSlashCommand($input);
        $profile = $policy->activeProfile();

        switch ($command) {
            case 'help':
            case '?':
                return [
                    'command' => '/help',
                    'type' => 'help',
                    'profile' => $profile,
                    'role' => $session['role'],
                    'content' => $this->helpText(),
                ];

            case 'clear':
            case 'reset':
            case 'new':
            case 'newtask':
                return $this->clearSession($session, $profile);

            case 'context':
            case 'history':
            case 'memory':
                return $this->contextPayload($session, $profile);

            case 'status':
            case 'session':
                return $this->statusPayload($session, $profile, $router, $policy, $pool);

            case 'doctor':
            case 'ready':
                return $this->doctorPayload($session, $profile, $router, $policy, $pool);

            case 'approval':
                return $this->approvalPayload($session, $profile, $tail);

            case 'permissions':
                return $this->permissionsPayload($profile, $policy, (string) $session['approval_mode']);

            case 'tools':
                return $this->toolsPayload($profile, $router, $session, $tail);

            case 'mcp':
                return $this->mcpPayload($profile, $router, $session, $tail);

            case 'routes':
                return $this->routesPayload($profile, $router, $tail);

            case 'git':
                return $this->gitPayload($profile, $tail);

            case 'model':
                return $this->modelPayload($profile, $tail !== '' ? $tail : (string) $session['role'], $pool);

            case 'role':
                return $this->rolePayload($session, $tail);

            case 'profile':
                return $this->profilePayload($profile, $tail);

            case 'mode':
                return $this->modePayload($policy, $tail);

            case 'preflight':
                return $this->preflightPayload($tail);

            case 'reassert':
                return $this->reassertPayload($tail);

            case 'prompt':
                return $this->promptPayload($profile, (string) $session['role'], (string) $session['approval_mode']);

            case 'plan':
            case 'ask':
            case 'run':
                if ($tail === '') {
                    return [
                        'command' => '/'.$command,
                        'type' => 'error',
                        'content' => "Provide a request after /{$command}.",
                    ];
                }

                return [
                    'command' => '/'.$command,
                    'type' => 'handoff',
                    'mode' => $command,
                    'request' => $tail,
                    'defer_to_model' => true,
                ];

            case 'exit':
            case 'quit':
                return [
                    'command' => '/'.$command,
                    'type' => 'exit',
                    'exit_shell' => true,
                    'content' => 'Session closed.',
                ];

            default:
                return [
                    'command' => '/'.$command,
                    'type' => 'error',
                    'content' => "Unknown slash command '/{$command}'. Use /help.",
                ];
        }
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array{role:string,approval_mode:string,history:array<int,array<string,string>>}  $session
     * @return array<string,mixed>
     */
    private function appendSlashCommandTrace(
        array $result,
        array $session,
        TraceEnvelopeService $traces,
        OfflinePolicyService $policy,
    ): array {
        $command = strtolower((string) ($result['command'] ?? ''));
        if (! in_array($command, self::TRACEABLE_SLASH_COMMANDS, true)) {
            return $result;
        }

        $traceId = 'trc_'.\Illuminate\Support\Str::uuid()->toString();
        $profile = $this->redactSensitiveSlashText((string) ($result['profile'] ?? $policy->activeProfile()));
        $status = ($result['type'] ?? null) === 'error' ? 'failed' : 'success';

        try {
            $trace = $traces->append([
                'trace_id' => $traceId,
                'sequence' => 1,
                'event_type' => 'slash_command',
                'surface' => 'offline:dev-assist',
                'actor' => [
                    'type' => 'operator',
                    'id' => 'local_operator',
                ],
                'policy' => [
                    'profile' => $profile,
                    'approval_mode' => (string) ($session['approval_mode'] ?? self::DEFAULT_APPROVAL_MODE),
                    'tool_class' => 'read',
                    'reason_summary' => 'offline dev-assist slash command',
                ],
                'classification' => [
                    'redaction_class' => 'private_metadata',
                    'data_class' => 'repo_metadata',
                    'risk_class' => 'read',
                ],
                'command' => [
                    'name' => $command,
                ],
                'result' => [
                    'status' => $status,
                ],
            ]);
        } catch (\Throwable) {
            $trace = ['success' => false];
        }

        $result['trace_id'] = $traceId;
        $result['trace_written'] = (bool) ($trace['success'] ?? false);

        return $result;
    }

    /**
     * @param  array{role:string,approval_mode:string,history:array<int,array<string,string>>}  $session
     * @return array<string,mixed>
     */
    private function clearSession(array &$session, string $profile): array
    {
        $session['history'] = [];

        return [
            'command' => '/clear',
            'type' => 'status',
            'profile' => $profile,
            'role' => $session['role'],
            'approval_mode' => $session['approval_mode'],
            'content' => 'Session history cleared.',
        ];
    }

    /**
     * @param  array{role:string,approval_mode:string,history:array<int,array<string,string>>}  $session
     * @return array<string,mixed>
     */
    private function contextPayload(array $session, string $profile): array
    {
        $history = $session['history'];
        $recent = array_slice($history, -6);

        return [
            'command' => '/context',
            'type' => 'context',
            'profile' => $profile,
            'role' => $session['role'],
            'approval_mode' => $session['approval_mode'],
            'turn_count' => count($history),
            'recent_turns' => $recent,
        ];
    }

    /**
     * @param  array{role:string,approval_mode:string,history:array<int,array<string,string>>}  $session
     * @return array<string,mixed>
     */
    private function statusPayload(
        array $session,
        string $profile,
        MCPRouter $router,
        OfflinePolicyService $policy,
        LLMPoolManagerService $pool,
    ): array {
        $tools = $this->sessionTools($profile, $router, $session);
        $availability = $pool->describeLocalAvailability();
        $model = $this->modelPayload($profile, (string) $session['role'], $pool);

        return [
            'command' => '/status',
            'type' => 'status',
            'profile' => $profile,
            'role' => $session['role'],
            'approval_mode' => $session['approval_mode'],
            'offline_mode_active' => $policy->isOfflineModeActive(),
            'local_availability' => $availability,
            'tool_count' => count($tools),
            'selected_local' => $model['selected_local'] ?? null,
            'selected_routed' => $model['selected_routed'] ?? null,
            'turn_count' => count($session['history']),
        ];
    }

    /**
     * @param  array{role:string,approval_mode:string,history:array<int,array<string,string>>}  $session
     * @return array<string,mixed>
     */
    private function doctorPayload(
        array $session,
        string $profile,
        MCPRouter $router,
        OfflinePolicyService $policy,
        LLMPoolManagerService $pool,
    ): array {
        $tools = $this->sessionTools($profile, $router, $session);
        $toolReadiness = $this->toolReadinessPayload($tools, (string) $session['approval_mode']);
        $model = $this->modelPayload($profile, (string) $session['role'], $pool);
        $runtimeScorecard = $this->runtimeScorecardPayload($model, (string) $session['role']);
        $availability = $pool->describeLocalAvailability();
        $git = $this->runGitCommand(['status', '--short']);

        $checks = [
            'offline_mode_active' => [
                'ok' => $policy->isOfflineModeActive(),
                'detail' => 'Offline kill switch is active',
            ],
            'local_model_selected' => [
                'ok' => ($model['selected_local'] ?? null) !== null,
                'detail' => (string) (($model['selected_local']['instance_id'] ?? 'none').' / '.($model['selected_local']['selected_model'] ?? 'none')),
            ],
            'repo_read_tools_visible' => [
                'ok' => ($toolReadiness['missing_read_tools'] ?? []) === [],
                'detail' => "{$toolReadiness['present_read_tools']}/{$toolReadiness['required_read_tools']} configured read tool(s) visible",
            ],
            'write_mode_explicit' => [
                'ok' => $session['approval_mode'] === 'repo-write'
                    ? ($toolReadiness['missing_write_tools'] ?? []) === []
                    : ($toolReadiness['unexpected_write_tools_visible'] ?? []) === [],
                'detail' => $session['approval_mode'] === 'repo-write'
                    ? "{$toolReadiness['visible_write_tools']}/{$toolReadiness['required_write_tools']} configured write tool(s) visible"
                    : 'Read-only mode is hiding configured write tools',
            ],
            'patch_and_verify_tools_explicit' => [
                'ok' => $session['approval_mode'] === 'repo-write'
                    ? ($toolReadiness['missing_patch_verify_tools'] ?? []) === []
                    : ($toolReadiness['unexpected_patch_verify_tools_visible'] ?? []) === [],
                'detail' => $session['approval_mode'] === 'repo-write'
                    ? "{$toolReadiness['visible_patch_verify_tools']}/{$toolReadiness['required_patch_verify_tools']} configured patch/verify tool(s) visible"
                    : 'Read-only mode is hiding patch and verification tools',
            ],
            'git_available' => [
                'ok' => (bool) ($git['success'] ?? false),
                'detail' => 'Local git status can be inspected',
            ],
        ];

        $recommendations = [];
        if (($availability['state'] ?? '') !== 'all_locals_up') {
            $recommendations[] = 'One or more local Ollama hosts are degraded; coding can continue if selected_local is present.';
        }
        if ($session['approval_mode'] !== 'repo-write') {
            $recommendations[] = 'Use /approval repo-write or --approval=repo-write only when you want the assistant to edit files.';
        }
        if (! (bool) ($git['success'] ?? false)) {
            $recommendations[] = 'Run from a git checkout if you want /git status and /git diff support.';
        }
        if (($toolReadiness['missing_read_tools'] ?? []) !== []) {
            $recommendations[] = 'Review config/dev_agent.php readiness tools against the active MCP profile.';
        }

        return [
            'command' => '/doctor',
            'type' => 'doctor',
            'profile' => $profile,
            'role' => $session['role'],
            'approval_mode' => $session['approval_mode'],
            'local_availability' => $availability,
            'selected_local' => $model['selected_local'] ?? null,
            'runtime_scorecard' => $runtimeScorecard,
            'tool_readiness' => $toolReadiness,
            'tool_count' => count($tools),
            'checks' => $checks,
            'ready' => ! in_array(false, array_column($checks, 'ok'), true),
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function permissionsPayload(string $profile, OfflinePolicyService $policy, string $approvalMode): array
    {
        $definition = $this->profileDefinition($profile);

        return [
            'command' => '/permissions',
            'type' => 'permissions',
            'profile' => $profile,
            'approval_mode' => $approvalMode,
            'offline_mode_active' => $policy->isOfflineModeActive(),
            'definition' => $definition,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function toolsPayload(string $profile, MCPRouter $router, array $session, string $tail): array
    {
        $serverFilter = strtolower(trim($tail));
        $tools = $this->sessionTools($profile, $router, $session);
        $groups = [];

        foreach ($tools as $tool) {
            $server = (string) ($tool['server'] ?? '');
            if ($server === '') {
                continue;
            }

            if ($serverFilter !== '' && $server !== $serverFilter) {
                continue;
            }

            $groups[$server][] = [
                'name' => $tool['name'] ?? '',
                'description' => $tool['description'] ?? '',
                'tool_class' => $tool['tool_class'] ?? null,
                'requires_confirmation' => (bool) ($tool['requires_confirmation'] ?? false),
            ];
        }

        ksort($groups);

        return [
            'command' => '/tools',
            'type' => 'tools',
            'profile' => $profile,
            'approval_mode' => $session['approval_mode'],
            'server_filter' => $serverFilter !== '' ? $serverFilter : null,
            'servers' => $groups,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mcpPayload(string $profile, MCPRouter $router, array $session, string $tail): array
    {
        $toolsPayload = $this->toolsPayload($profile, $router, $session, $tail);
        $serverSummary = [];

        foreach (($toolsPayload['servers'] ?? []) as $server => $tools) {
            $serverSummary[] = [
                'server' => $server,
                'tool_count' => count($tools),
            ];
        }

        return [
            'command' => '/mcp',
            'type' => 'mcp',
            'profile' => $profile,
            'approval_mode' => $session['approval_mode'],
            'servers' => $serverSummary,
            'tools' => $toolsPayload['servers'] ?? [],
        ];
    }

    /**
     * @param  array{role:string,approval_mode:string,history:array<int,array<string,string>>}  $session
     * @return array<string,mixed>
     */
    private function approvalPayload(array &$session, string $profile, string $tail): array
    {
        $profile = $this->redactSensitiveSlashText($profile);
        $requested = $this->normalizeApprovalMode($tail);
        if (trim($tail) === '') {
            return [
                'command' => '/approval',
                'type' => 'approval',
                'profile' => $profile,
                'approval_mode' => $session['approval_mode'],
                'available_modes' => self::VALID_APPROVAL_MODES,
            ];
        }

        if ($requested === null) {
            return [
                'command' => '/approval',
                'type' => 'error',
                'content' => 'Unknown approval mode. Allowed: '.implode(', ', self::VALID_APPROVAL_MODES),
            ];
        }

        $session['approval_mode'] = $requested;

        return [
            'command' => '/approval',
            'type' => 'approval',
            'profile' => $profile,
            'approval_mode' => $session['approval_mode'],
            'available_modes' => self::VALID_APPROVAL_MODES,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function routesPayload(string $profile, MCPRouter $router, string $tail): array
    {
        [$scope, $filter] = $this->parseRoutesTail($tail);

        try {
            $result = $router->callTool('repo-dev', 'list_routes', [
                'scope' => $scope,
                'filter' => $filter !== '' ? $filter : null,
            ]);
        } catch (\Throwable $e) {
            return [
                'command' => '/routes',
                'type' => 'error',
                'content' => $e->getMessage(),
            ];
        }

        return [
            'command' => '/routes',
            'type' => 'routes',
            'profile' => $profile,
            'scope' => $scope,
            'filter' => $filter !== '' ? $filter : null,
            'result' => $result,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function gitPayload(string $profile, string $tail): array
    {
        $parts = preg_split('/\s+/', trim($tail)) ?: [];
        $action = strtolower((string) ($parts[0] ?? 'status'));
        if ($action === '') {
            $action = 'status';
        }

        $result = match ($action) {
            'status', 's' => $this->runGitCommand(['status', '--short']),
            'files', 'changed' => $this->runGitCommand(['diff', '--name-only']),
            'staged' => $this->runGitCommand(['diff', '--cached', '--stat']),
            'diff' => $this->runGitCommand(['diff', '--stat']),
            default => [
                'success' => false,
                'exit_code' => 1,
                'output' => 'Use /git status, /git files, /git diff, or /git staged.',
            ],
        };

        return [
            'command' => '/git',
            'type' => ($result['success'] ?? false) ? 'git' : 'error',
            'profile' => $profile,
            'action' => $action,
            'result' => $result,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function modelPayload(string $profile, string $requestedRole, LLMPoolManagerService $pool): array
    {
        $role = $this->normalizeRole($requestedRole) ?? 'coding';
        $capabilities = $this->capabilitiesForRole($role);
        $instances = array_values(array_filter(
            $pool->getInstancesForMonitoring(),
            static fn ($instance) => ((int) ($instance->is_active ?? 0)) === 1
                && (($instance->routability ?? 'allowed') === 'allowed')
        ));

        $localInstances = [];
        $nonLocalIds = [];

        foreach ($instances as $instance) {
            if (($instance->instance_type ?? null) !== 'ollama') {
                $nonLocalIds[] = (string) $instance->instance_id;

                continue;
            }

            $localInstances[] = [
                'instance_id' => (string) $instance->instance_id,
                'instance_name' => (string) ($instance->instance_name ?? ''),
                'host_affinity' => (string) ($instance->host_affinity ?? ''),
                'priority' => (int) ($instance->priority ?? 0),
                'health_score' => (int) ($instance->health_score ?? 0),
                'avg_response_ms' => $instance->avg_response_ms === null ? null : (int) $instance->avg_response_ms,
                'models' => (array) ($instance->config['models'] ?? []),
            ];
        }

        $selectedLocal = $pool->selectInstance([
            'role' => $role,
            'capabilities' => $capabilities,
            'exclude_instances' => $nonLocalIds,
        ]);

        $selectedRouted = $pool->selectInstance([
            'role' => $role,
            'capabilities' => $capabilities,
        ]);

        return [
            'command' => '/model',
            'type' => 'model',
            'profile' => $profile,
            'requested_role' => $role,
            'selected_local' => $this->serializeSelection($selectedLocal, $role),
            'selected_routed' => $this->serializeSelection($selectedRouted, $role),
            'local_instances' => $localInstances,
        ];
    }

    /**
     * @param  array{role:string,approval_mode:string,history:array<int,array<string,string>>}  $session
     * @return array<string,mixed>
     */
    private function rolePayload(array &$session, string $tail): array
    {
        $requested = trim(strtolower($tail));
        if ($requested === '') {
            return [
                'command' => '/role',
                'type' => 'role',
                'current_role' => $session['role'],
                'approval_mode' => $session['approval_mode'],
                'available_roles' => self::VALID_ROLES,
            ];
        }

        $role = $this->normalizeRole($requested);
        if ($role === null) {
            return [
                'command' => '/role',
                'type' => 'error',
                'content' => 'Unknown role. Allowed: '.implode(', ', self::VALID_ROLES),
            ];
        }

        $session['role'] = $role;

        return [
            'command' => '/role',
            'type' => 'role',
            'current_role' => $session['role'],
            'approval_mode' => $session['approval_mode'],
            'available_roles' => self::VALID_ROLES,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function profilePayload(string $currentProfile, string $tail): array
    {
        $requested = trim(strtolower($tail));

        if ($requested === '') {
            return [
                'command' => '/profile',
                'type' => 'profile',
                'active_profile' => $currentProfile,
                'profile_definition' => $this->profileDefinition($currentProfile),
            ];
        }

        if ($requested === 'list') {
            return [
                'command' => '/profile',
                'type' => 'profile_list',
                'active_profile' => $currentProfile,
                'profiles' => array_keys((array) config('offline_policy.profiles', [])),
            ];
        }

        $exit = Artisan::call('routing:profile', [
            'action' => 'activate',
            'name' => $requested,
        ]);

        if ($exit !== 0) {
            return [
                'command' => '/profile',
                'type' => 'error',
                'content' => trim((string) Artisan::output()) !== ''
                    ? trim((string) Artisan::output())
                    : "Failed to activate profile '{$requested}'.",
            ];
        }

        return [
            'command' => '/profile',
            'type' => 'profile',
            'active_profile' => $requested,
            'profile_definition' => $this->profileDefinition($requested),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function modePayload(OfflinePolicyService $policy, string $tail): array
    {
        $requested = trim(strtolower($tail));
        if ($requested === '' || $requested === 'status') {
            return [
                'command' => '/mode',
                'type' => 'mode',
                'offline_mode_active' => $policy->isOfflineModeActive(),
            ];
        }

        if (! in_array($requested, ['enable', 'disable'], true)) {
            return [
                'command' => '/mode',
                'type' => 'error',
                'content' => 'Use /mode status, /mode enable, or /mode disable.',
            ];
        }

        $exit = Artisan::call('ollama:offline-mode', ['action' => $requested]);

        return [
            'command' => '/mode',
            'type' => $exit === 0 ? 'mode' : 'error',
            'offline_mode_active' => $requested === 'enable',
            'content' => trim((string) Artisan::output()),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function preflightPayload(string $tail): array
    {
        $strict = in_array(trim(strtolower($tail)), ['strict', '--strict'], true);
        $output = new BufferedOutput;
        $exit = $this->runCommand('ollama:offline-preflight', [
            '--json' => true,
            '--strict' => $strict,
        ], $output);

        $payload = $this->sanitizeSlashOutputPayload($this->decodeJsonOutput($output->fetch()));

        return [
            'command' => '/preflight',
            'type' => $exit === 0 ? 'preflight' : 'error',
            'strict' => $strict,
            'result' => $payload,
        ];
    }

    private function sanitizeSlashOutputPayload(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSensitiveSlashKey($key)) {
                    $sanitized[$key] = '[REDACTED]';

                    continue;
                }

                $sanitized[$key] = $this->sanitizeSlashOutputPayload($item);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            return $this->redactSensitiveSlashText($value);
        }

        return is_scalar($value) || $value === null ? $value : '[non-scalar]';
    }

    private function redactSensitiveSlashText(string $value): string
    {
        $roots = array_filter([
            base_path(),
            storage_path(),
            (string) getenv('HOME'),
        ], static fn (string $path): bool => $path !== '' && $path !== '/');

        usort($roots, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach (array_unique($roots) as $root) {
            $value = preg_replace(
                '#'.preg_quote(rtrim($root, '/'), '#').'(?=/|$)[^\s,"\')\]}]*#',
                '[REDACTED_PATH]',
                $value
            ) ?? $value;
        }

        $replacements = [
            '/\b(?:api[_-]?(?:key|token)|access[_-]?token|refresh[_-]?token|id[_-]?token|auth[_-]?token|token|secret|password|authorization)\s*[:=]\s*[^\s,;\]}]+/i' => '[REDACTED_SECRET]',
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i' => 'Bearer [REDACTED]',
            '/([A-Za-z][A-Za-z0-9+.-]*:\/\/)([^:@\/\s]+):([^@\/\s]+)@/i' => '$1[REDACTED]@',
            '/\b(?:sk|ghp|github_pat|glpat|xox[baprs]?)-[A-Za-z0-9_=-]{8,}\b/i' => '[REDACTED_TOKEN]',
            '#/(?:home|Users|root)/[^\s,"\')\]}]+#' => '[REDACTED_PATH]',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return $value;
    }

    private function isSensitiveSlashKey(string $key): bool
    {
        return preg_match(
            '/\b(?:api[_-]?(?:key|token)|access[_-]?token|refresh[_-]?token|id[_-]?token|auth[_-]?token|token|secret|password|authorization|bearer)\b/i',
            $key
        ) === 1;
    }

    /**
     * @return array<string,mixed>
     */
    private function reassertPayload(string $tail): array
    {
        $stale = (int) ($tail !== '' ? $tail : '10');
        if ($stale <= 0) {
            $stale = 10;
        }

        $exit = Artisan::call('routing:reassert', [
            '--json' => true,
            '--stale' => $stale,
        ]);

        $payload = $this->decodeJsonOutput((string) Artisan::output());

        return [
            'command' => '/reassert',
            'type' => $exit === 0 ? 'reassert' : 'error',
            'stale_minutes' => $stale,
            'result' => $payload,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function promptPayload(string $profile, string $role, string $approvalMode): array
    {
        return [
            'command' => '/prompt',
            'type' => 'prompt',
            'profile' => $profile,
            'role' => $role,
            'approval_mode' => $approvalMode,
            'system_prompt' => $this->systemPrompt($profile, $role, $approvalMode),
        ];
    }

    /**
     * @param  list<string>  $arguments
     * @return array{success:bool,exit_code:int|null,output:string,error:string}
     */
    private function runGitCommand(array $arguments): array
    {
        $process = Process::path(base_path())
            ->timeout(10)
            ->run(array_merge(['git'], $arguments));

        return [
            'success' => $process->successful(),
            'exit_code' => $process->exitCode(),
            'output' => $this->truncateOutput(trim($process->output())),
            'error' => $this->truncateOutput(trim($process->errorOutput())),
        ];
    }

    private function truncateOutput(string $output, int $limit = 12000): string
    {
        if (strlen($output) <= $limit) {
            return $output;
        }

        return substr($output, 0, $limit)."\n...[truncated]";
    }

    /**
     * @param  array{role:string,approval_mode:string,history:array<int,array<string,string>>}  $session
     * @param  array<int,array<string,mixed>>  $availableTools
     * @return array<string,mixed>
     */
    private function runAssistantRequest(
        string $request,
        string $mode,
        array &$session,
        AIService $ai,
        string $profile,
        array $availableTools,
        int $maxIterations,
        TraceEnvelopeService $traces,
    ): array {
        $traceId = 'trc_'.\Illuminate\Support\Str::uuid()->toString();
        $prompt = $this->buildPrompt($request, $mode, $session, $profile);
        $config = [
            'model_role' => $session['role'],
            'system_prompt' => $this->systemPrompt($profile, (string) $session['role'], (string) $session['approval_mode']),
            'available_tools' => $availableTools,
            // The catalog is already filtered by the active routing profile.
            // Do not let AIRouter apply a second heuristic server filter here,
            // or dev/repo requests can lose the exact tools they need.
            'tool_filter' => [],
        ];

        $requestTrace = $traces->append([
            'trace_id' => $traceId,
            'sequence' => 1,
            'event_type' => 'model_request',
            'surface' => 'offline:dev-assist',
            'actor' => [
                'type' => 'operator',
                'id' => 'local_operator',
            ],
            'policy' => [
                'profile' => $profile,
                'tool_class' => 'read',
                'reason_summary' => 'offline dev-assist one-shot request',
            ],
            'classification' => [
                'redaction_class' => 'private_metadata',
                'data_class' => 'repo_metadata',
                'risk_class' => $session['approval_mode'] === 'repo-write' ? 'bounded_write' : 'read',
            ],
            'request' => [
                'prompt_hash' => 'sha256:'.hash('sha256', $prompt),
                'mode' => $mode,
            ],
            'model' => [
                'role' => $session['role'],
                'provider_class' => 'local_llm',
            ],
            'result' => [
                'status' => 'started',
            ],
        ]);

        $result = $ai->processWithTools($prompt, $config, $maxIterations);
        $response = (string) ($result['response'] ?? '');
        $success = (bool) ($result['success'] ?? false);

        $responseTrace = $traces->append([
            'trace_id' => $traceId,
            'sequence' => 2,
            'event_type' => $success ? 'model_response' : 'error',
            'surface' => 'offline:dev-assist',
            'actor' => [
                'type' => 'system',
                'id' => 'offline:dev-assist',
            ],
            'policy' => [
                'profile' => $profile,
                'tool_class' => 'read',
            ],
            'classification' => [
                'redaction_class' => 'private_metadata',
                'data_class' => 'repo_metadata',
                'risk_class' => $session['approval_mode'] === 'repo-write' ? 'bounded_write' : 'read',
            ],
            'model' => [
                'role' => $session['role'],
                'provider_class' => 'local_llm',
            ],
            'result' => [
                'status' => $success ? 'success' : 'failed',
                'duration_ms' => $result['duration_ms'] ?? null,
                'output_hash' => 'sha256:'.hash('sha256', $response),
                'error_class' => ($result['error'] ?? null) !== null ? 'assistant_request_failed' : null,
            ],
        ]);

        $session['history'][] = [
            'mode' => $mode,
            'user' => $request,
            'assistant' => $response,
        ];

        return [
            'success' => $success,
            'trace_id' => $traceId,
            'trace_written' => (bool) (($requestTrace['success'] ?? false) && ($responseTrace['success'] ?? false)),
            'mode' => $mode,
            'profile' => $profile,
            'role' => $session['role'],
            'approval_mode' => $session['approval_mode'],
            'tool_count' => count($availableTools),
            'provider' => $result['provider'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'response' => $response,
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * @param  array{role:string,approval_mode:string,history:array<int,array<string,string>>}  $session
     */
    private function buildPrompt(string $request, string $mode, array $session, string $profile): string
    {
        $history = array_slice($session['history'], -6);
        $sections = [
            'Current session role: '.$session['role'],
            'Active routing profile: '.$profile,
            'Session approval mode: '.$session['approval_mode'],
        ];

        if ($history !== []) {
            $historyLines = [];
            foreach ($history as $turn) {
                $historyLines[] = strtoupper((string) ($turn['mode'] ?? 'ask')).' USER: '.(string) ($turn['user'] ?? '');
                $historyLines[] = 'ASSISTANT: '.(string) ($turn['assistant'] ?? '');
            }
            $sections[] = "Recent session context:\n".implode("\n", $historyLines);
        }

        $sections[] = 'Operator request: '.$request;
        $sections[] = match ($mode) {
            'plan' => 'Respond with a concrete execution plan. Prefer ordered steps, note likely files or systems touched, and do not invent tools that are not exposed in this session.',
            'run' => 'Execute the request end-to-end using only the exposed tools. Keep the response focused on what changed, what was run, and any remaining risk.',
            default => 'Respond directly and use the exposed tools when they materially help.',
        };

        return implode("\n\n", $sections);
    }

    private function systemPrompt(string $profile, string $role, string $approvalMode): string
    {
        return implode("\n", [
            'You are the PLOS offline development assistant running inside the plos repository.',
            'Honor the active routing profile and only plan with tools that are visible in this session.',
            'Prefer direct, concise engineering answers over generic assistant filler.',
            'If asked to change code, keep edits minimal, explain what changed, and mention any verification you performed.',
            'If write-class tools are not visible in this session, say so explicitly and tell the operator to use /approval repo-write or --approval=repo-write.',
            "Current profile: {$profile}.",
            "Current model role: {$role}.",
            "Current approval mode: {$approvalMode}.",
        ]);
    }

    private function helpText(): string
    {
        return implode("\n", [
            'offline:dev-assist slash commands',
            '/help                Show this command list and one-shot usage',
            '/status              Show profile, offline mode, local availability, selected model, and tool count',
            '/doctor              Check whether the offline programming session is ready',
            '/approval [mode]     Show or set the session approval mode',
            '/model [role]        Show local model inventory and the selected winner for a role',
            '/role [name]         Show or set the session role',
            '/tools [server]      List tools allowed under the active profile',
            '/mcp [server]        Show MCP server summary and visible tools',
            '/routes [scope]      Show frontend, api, or all routes (default: frontend)',
            '/git [status|files|diff|staged]  Inspect local git state without mutating files',
            '/permissions         Show the active profile policy envelope',
            '/profile [name|list] Show or activate a routing profile',
            '/mode [status|enable|disable]  Show or toggle offline mode',
            '/preflight [strict]  Run offline readiness checks',
            '/reassert [minutes]  Reassert profile/offline state and clean stale confirmations',
            '/context             Show the current in-memory session history',
            '/prompt              Show the effective system prompt',
            '/clear               Clear the current session history',
            '/plan <request>      Run the assistant in planning mode for one request',
            '/ask <request>       Run a normal one-shot request',
            '/run <request>       Run an execution-oriented one-shot request',
            '/exit                Leave the interactive shell',
            '',
            'One-shot examples:',
            'php artisan offline:dev-assist "/help"',
            'php artisan offline:programming "/doctor"',
            'php artisan offline:dev-assist --profile=offline_dev_assist --role=coding --approval=repo-write "Add tests for the scheduler parser"',
            'php artisan offline:dev-assist --approval=repo-write "Update the offline shell help text"',
            'php artisan offline:dev-assist --json "/model coding"',
        ]);
    }

    private function renderBanner(string $profile, string $role, string $approvalMode): void
    {
        $this->line('PLOS Offline Dev Assist');
        $this->line("profile={$profile} role={$role} approval={$approvalMode}");
        $this->line('Type /help for commands. Type /exit to leave.');
        $this->newLine();
    }

    private function promptLabel(string $profile, string $role, string $approvalMode): string
    {
        return "dev-assist [{$profile}|{$role}|{$approvalMode}]";
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function renderSlashResult(array $result): void
    {
        $type = (string) ($result['type'] ?? 'status');

        if (isset($result['content']) && is_string($result['content'])) {
            $this->line($result['content']);

            return;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($type === 'handoff' && ($result['defer_to_model'] ?? false) === true) {
            $mode = (string) ($result['mode'] ?? 'ask');
            $request = trim((string) ($result['request'] ?? ''));
            $this->line("Re-run without the slash to execute, or use the one-shot form: php artisan offline:dev-assist \"/{$mode} {$request}\"");
        }
    }

    private function readPromptArgument(): string
    {
        $prompt = $this->argument('prompt');
        if (is_array($prompt)) {
            return trim(implode(' ', $prompt));
        }

        return trim((string) $prompt);
    }

    private function normalizeRole(string $role): ?string
    {
        $role = trim(strtolower($role));

        return in_array($role, self::VALID_ROLES, true) ? $role : null;
    }

    private function normalizeApprovalMode(string $mode): ?string
    {
        $mode = trim(strtolower($mode));
        if ($mode === '') {
            return self::DEFAULT_APPROVAL_MODE;
        }

        return match ($mode) {
            'ro', 'read', 'read-only', 'readonly' => 'read-only',
            'repo-write', 'write', 'writable' => 'repo-write',
            default => null,
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $tools
     * @return array<string,mixed>
     */
    private function toolReadinessPayload(array $tools, string $approvalMode): array
    {
        $toolKeys = $this->toolKeys($tools);
        $requiredRead = $this->configuredToolKeys('dev_agent.readiness.required_read_tools');
        $requiredWrite = $this->configuredToolKeys('dev_agent.readiness.required_write_tools');
        $requiredPatchVerify = $this->configuredToolKeys('dev_agent.readiness.required_patch_verify_tools');

        $presentRead = $this->toolKeyIntersection($requiredRead, $toolKeys);
        $visibleWrite = $this->toolKeyIntersection($requiredWrite, $toolKeys);
        $visiblePatchVerify = $this->toolKeyIntersection($requiredPatchVerify, $toolKeys);

        return [
            'source' => (string) config('dev_agent.readiness.source', 'config/dev_agent.php'),
            'approval_mode' => $approvalMode,
            'visible_tool_count' => count($tools),
            'required_read_tools' => count($requiredRead),
            'present_read_tools' => count($presentRead),
            'missing_read_tools' => array_values(array_diff($requiredRead, $toolKeys)),
            'required_write_tools' => count($requiredWrite),
            'visible_write_tools' => count($visibleWrite),
            'missing_write_tools' => $approvalMode === 'repo-write' ? array_values(array_diff($requiredWrite, $toolKeys)) : [],
            'unexpected_write_tools_visible' => $approvalMode === 'read-only' ? $visibleWrite : [],
            'required_patch_verify_tools' => count($requiredPatchVerify),
            'visible_patch_verify_tools' => count($visiblePatchVerify),
            'missing_patch_verify_tools' => $approvalMode === 'repo-write' ? array_values(array_diff($requiredPatchVerify, $toolKeys)) : [],
            'unexpected_patch_verify_tools_visible' => $approvalMode === 'read-only' ? $visiblePatchVerify : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $model
     * @return array<string,mixed>
     */
    private function runtimeScorecardPayload(array $model, string $role): array
    {
        $locals = is_array($model['local_instances'] ?? null) ? $model['local_instances'] : [];
        $healthy = array_filter($locals, static fn (array $instance): bool => (int) ($instance['health_score'] ?? 0) >= 80);
        $selectedLocal = is_array($model['selected_local'] ?? null) ? $model['selected_local'] : null;
        $selectedRouted = is_array($model['selected_routed'] ?? null) ? $model['selected_routed'] : null;

        return [
            'source' => LLMPoolManagerService::class,
            'role' => $role,
            'local_instances' => count($locals),
            'healthy_local_instances' => count($healthy),
            'degraded_local_instances' => max(0, count($locals) - count($healthy)),
            'selected_local_present' => $selectedLocal !== null,
            'selected_local_id' => $selectedLocal['instance_id'] ?? null,
            'selected_local_model' => $selectedLocal['selected_model'] ?? null,
            'selected_routed_id' => $selectedRouted['instance_id'] ?? null,
            'selected_routed_model' => $selectedRouted['selected_model'] ?? null,
            'routed_selection_is_local' => ($selectedLocal['instance_id'] ?? null) !== null
                && ($selectedLocal['instance_id'] ?? null) === ($selectedRouted['instance_id'] ?? null),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $tools
     * @return list<string>
     */
    private function toolKeys(array $tools): array
    {
        $keys = [];
        foreach ($tools as $tool) {
            $server = trim((string) ($tool['server'] ?? ''));
            $name = trim((string) ($tool['name'] ?? ''));
            if ($server === '' || $name === '') {
                continue;
            }

            $keys[] = "{$server}.{$name}";
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    private function configuredToolKeys(string $key): array
    {
        $tools = config($key, []);
        if (! is_array($tools)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $tool): string => trim((string) $tool),
            $tools
        )));
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $visible
     * @return list<string>
     */
    private function toolKeyIntersection(array $expected, array $visible): array
    {
        return array_values(array_intersect($expected, $visible));
    }

    /**
     * @param  array{role:string,approval_mode:string,history:array<int,array<string,string>>}  $session
     * @return array<int,array<string,mixed>>
     */
    private function sessionTools(string $profile, MCPRouter $router, array $session): array
    {
        $tools = $router->getAvailableToolsForProfile($profile);
        $tools = array_values(array_filter($tools, function (array $tool): bool {
            $server = (string) ($tool['server'] ?? '');

            return in_array($server, self::DEV_TOOL_SERVERS, true);
        }));

        if (($session['approval_mode'] ?? self::DEFAULT_APPROVAL_MODE) === 'read-only') {
            $tools = array_values(array_filter($tools, static function (array $tool): bool {
                return (string) ($tool['tool_class'] ?? '') === 'read';
            }));
        }

        usort($tools, static function (array $a, array $b): int {
            $serverCompare = strcmp((string) ($a['server'] ?? ''), (string) ($b['server'] ?? ''));

            if ($serverCompare !== 0) {
                return $serverCompare;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $tools;
    }

    private function shortcutSlashCommand(string $input): ?string
    {
        $normalized = strtolower(trim($input));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\b(mcps?|model context protocol)\b/', $normalized) === 1
            && preg_match('/\b(list|show|available|what)\b/', $normalized) === 1
        ) {
            return '/mcp';
        }

        if (preg_match('/\b(frontend|web)\b/', $normalized) === 1
            && preg_match('/\broutes?\b/', $normalized) === 1
        ) {
            return '/routes frontend';
        }

        if (preg_match('/\bapi\b/', $normalized) === 1
            && preg_match('/\broutes?\b/', $normalized) === 1
        ) {
            return '/routes api';
        }

        if (preg_match('/\b(list|show)\b/', $normalized) === 1
            && preg_match('/\bavailable tools?\b|\btools? available\b/', $normalized) === 1
        ) {
            return '/tools';
        }

        return null;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function parseRoutesTail(string $tail): array
    {
        $tail = trim(strtolower($tail));
        if ($tail === '') {
            return ['frontend', ''];
        }

        $parts = preg_split('/\s+/', $tail, 2) ?: [];
        $scope = match ($parts[0] ?? '') {
            'frontend', 'web' => 'frontend',
            'api' => 'api',
            'all' => 'all',
            default => 'frontend',
        };

        if ($scope === 'frontend' && ! in_array(($parts[0] ?? ''), ['frontend', 'web', 'api', 'all'], true)) {
            return ['frontend', $tail];
        }

        return [$scope, trim((string) ($parts[1] ?? ''))];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function parseSlashCommand(string $input): array
    {
        $input = ltrim(trim($input), '/');
        $parts = preg_split('/\s+/', $input, 2) ?: [];
        $command = strtolower(trim((string) ($parts[0] ?? '')));
        $tail = trim((string) ($parts[1] ?? ''));

        return [$command, $tail];
    }

    private function activateProfileIfRequested(string $profile): bool
    {
        $profile = trim(strtolower($profile));
        if ($profile === '') {
            return true;
        }

        $exit = Artisan::call('routing:profile', [
            'action' => 'activate',
            'name' => $profile,
        ]);

        if ($exit === 0) {
            return true;
        }

        $output = trim((string) Artisan::output());
        $this->error($output !== '' ? $output : "Failed to activate profile '{$profile}'.");

        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function profileDefinition(string $profile): ?array
    {
        $definition = config('offline_policy.profiles.'.$profile);

        return is_array($definition) ? $definition : null;
    }

    /**
     * @return array<int,string>
     */
    private function capabilitiesForRole(string $role): array
    {
        return match ($role) {
            'vision' => ['vision'],
            'embedding' => ['embedding'],
            default => ['text'],
        };
    }

    /**
     * @param  array<string,mixed>|null  $selection
     * @return array<string,mixed>|null
     */
    private function serializeSelection(?array $selection, string $role): ?array
    {
        if (! is_array($selection) || ! isset($selection['instance'])) {
            return null;
        }

        $instance = $selection['instance'];
        $config = is_array($instance->config ?? null) ? $instance->config : (json_decode((string) ($instance->config ?? '{}'), true) ?: []);
        $models = (array) ($config['models'] ?? []);

        return [
            'instance_id' => (string) ($instance->instance_id ?? ''),
            'instance_name' => (string) ($instance->instance_name ?? ''),
            'instance_type' => (string) ($instance->instance_type ?? ''),
            'host_affinity' => (string) ($instance->host_affinity ?? ''),
            'selected_model' => $models[$role] ?? $models['standard'] ?? null,
            'score' => $selection['score'] ?? null,
            'is_busy' => $selection['is_busy'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonOutput(string $output): array
    {
        $output = trim($output);
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end < $start) {
            return ['raw_output' => $output];
        }

        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : ['raw_output' => $output];
    }
}
