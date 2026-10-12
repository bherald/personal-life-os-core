<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AgentReferenceBoundaryService
{
    public const VERSION = 'agent-reference-boundaries-2026-07-13';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function references(): array
    {
        return [
            'hermes_agent' => [
                'id' => 'hermes_agent',
                'name' => 'Hermes Agent',
                'category' => 'developer_agent',
                'posture' => 'license_review_required_adoption_candidate',
                'allowed_use' => 'review_license_then_adopt_or_adapt',
                'adoption_state' => 'not_a_dependency',
                'current_snapshot' => [
                    'version' => '0.15.2',
                    'release' => 'v2026.5.29.2',
                    'refreshed_at' => '2026-06-03',
                    'primary_sources' => [
                        'https://github.com/NousResearch/hermes-agent',
                        'https://pypi.org/project/hermes-agent/',
                    ],
                ],
                'plos_targets' => [
                    'agent memory hygiene',
                    'skill lifecycle discipline',
                    'subagent/delegation governance',
                    'provider and local-proxy routing posture',
                    'session search and trace evidence',
                ],
                'prohibited_use' => [
                    'import_runtime_dependency',
                    'replace_laravel_control_plane',
                ],
                'refresh_required_before' => [
                    'new_implementation_slice',
                    'public_claim',
                    'dependency_or_runtime_change',
                ],
                'todo_lane' => 'DEV-HERMES-WR-001',
                'todo_state' => 'closed',
                'notes' => 'Previous HWR slice set is closed. Future work requires a new or reopened TODO lane and a current upstream refresh.',
            ],
            'claw_code' => [
                'id' => 'claw_code',
                'name' => 'Claw Code',
                'category' => 'developer_agent',
                'posture' => 'high_caution_clean_room_provenance_watch',
                'allowed_use' => 'watch_only_with_clean_room_lessons',
                'adoption_state' => 'not_a_dependency',
                'current_snapshot' => [
                    'version' => null,
                    'release' => null,
                    'refreshed_at' => '2026-06-03',
                    'primary_sources' => [
                        'https://github.com/ultraworkers/claw-code',
                        'https://claw-code.codes/',
                    ],
                ],
                'plos_targets' => [
                    'permission gate clarity',
                    'tool execution boundary checks',
                    'local OpenAI-compatible provider posture',
                    'clean-room provenance discipline',
                    'operator-facing parity inventory ideas',
                ],
                'prohibited_use' => [
                    'copy_source',
                    'copy_prompts',
                    'copy_tests',
                    'copy_docs',
                    'copy_ui_text',
                    'copy_leaked_architecture_wording',
                    'import_runtime_dependency',
                    'use_as_adoption_candidate',
                ],
                'refresh_required_before' => [
                    'any_source_level_study',
                    'public_claim',
                    'new_implementation_slice',
                ],
                'todo_lane' => 'TODO-015',
                'todo_state' => 'watch',
                'notes' => 'Treat as high-caution provenance watch because the project frames itself around clean-room/leaked-architecture reconstruction.',
            ],
            'laravel_pao' => [
                'id' => 'laravel_pao',
                'name' => 'Laravel PAO',
                'category' => 'developer_tool',
                'posture' => 'license_review_required_adoption_candidate',
                'allowed_use' => 'review_license_then_use_dev_output_patterns',
                'adoption_state' => 'dev_dependency_present',
                'current_snapshot' => [
                    'version' => '1.1.2',
                    'release' => 'v1.1.2',
                    'revision' => '41b3c61ebeddce52a446afe6d21e0b02983fb2f6',
                    'refreshed_at' => '2026-07-13',
                    'primary_sources' => [
                        'https://github.com/laravel/pao',
                        'https://github.com/laravel/agent-detector',
                    ],
                ],
                'plos_targets' => [
                    'AI-readable PHPUnit and PHPStan output',
                    'targeted verification runner ergonomics',
                    'agent detection environment discipline',
                    'developer-loop clarity without product behavior change',
                ],
                'prohibited_use' => [
                    'widen_tool_permissions',
                    'replace_test_harness',
                    'expose_private_data',
                    'change_production_behavior',
                ],
                'refresh_required_before' => [
                    'composer_update',
                    'new_runner_behavior',
                    'public_claim',
                ],
                'todo_lane' => 'TODO-015',
                'todo_state' => 'watch',
                'notes' => 'MIT PAO v1.1.2 is locked as a dev dependency for compact agent-readable verification output. The upgrade adds runtime-exit and fatal-hook result fixes, boolean environment handling, PHPStan guidance, Rector support, and explicit force/detection behavior without changing production runtime policy.',
            ],
            'microsoft_agent_framework' => [
                'id' => 'microsoft_agent_framework',
                'name' => 'Microsoft Agent Framework',
                'category' => 'developer_agent',
                'posture' => 'license_review_required_adoption_candidate',
                'allowed_use' => 'review_license_then_adopt_or_adapt',
                'adoption_state' => 'not_a_dependency',
                'current_snapshot' => [
                    'version' => 'python-1.6.0',
                    'release' => 'python-1.6.0',
                    'refreshed_at' => '2026-06-05',
                    'primary_sources' => [
                        'https://github.com/microsoft/agent-framework',
                        'https://learn.microsoft.com/en-us/agent-framework/',
                    ],
                ],
                'plos_targets' => [
                    'multi-agent orchestration vocabulary',
                    'workflow graph and handoff patterns',
                    'A2A and MCP protocol posture',
                    'human checkpoint and observability design',
                ],
                'prohibited_use' => [
                    'import_runtime_dependency',
                    'replace_laravel_control_plane',
                    'widen_provider_routes',
                ],
                'refresh_required_before' => [
                    'new_implementation_slice',
                    'dependency_or_runtime_change',
                    'public_claim',
                ],
                'todo_lane' => 'TODO-015',
                'todo_state' => 'watch',
                'notes' => 'Current Microsoft agent-orchestration watch item; AutoGen and Semantic Kernel now point toward this successor framework.',
            ],
            'openai_codex_cli' => [
                'id' => 'openai_codex_cli',
                'name' => 'OpenAI Codex CLI',
                'category' => 'developer_agent',
                'posture' => 'existing_external_tool_reference',
                'allowed_use' => 'watch_tool_governance_patterns',
                'adoption_state' => 'operator_tool_not_app_dependency',
                'current_snapshot' => [
                    'version' => '0.137.0',
                    'release' => 'rust-v0.137.0',
                    'refreshed_at' => '2026-06-05',
                    'primary_sources' => [
                        'https://github.com/openai/codex',
                        'https://github.com/openai/codex/releases',
                    ],
                ],
                'plos_targets' => [
                    'approval profile clarity',
                    'plugin and skill metadata posture',
                    'subagent lifecycle observability',
                    'diagnostics and remote-control governance',
                ],
                'prohibited_use' => [
                    'import_runtime_dependency',
                    'widen_mcp_tools',
                    'auto_execute_without_operator',
                ],
                'refresh_required_before' => [
                    'new_implementation_slice',
                    'tool_policy_change',
                    'public_claim',
                ],
                'todo_lane' => 'TODO-015',
                'todo_state' => 'watch',
                'notes' => 'Existing operator tool reference; PLOS may learn governance and diagnostics patterns without vendoring Codex.',
            ],
            'cline' => [
                'id' => 'cline',
                'name' => 'Cline',
                'category' => 'developer_agent',
                'posture' => 'license_review_required_adoption_candidate_with_supply_chain_caution',
                'allowed_use' => 'review_license_and_release_provenance_then_adopt',
                'adoption_state' => 'not_a_dependency',
                'current_snapshot' => [
                    'version' => '3.85.0',
                    'release' => 'v3.85.0',
                    'refreshed_at' => '2026-06-05',
                    'primary_sources' => [
                        'https://github.com/cline/cline',
                    ],
                ],
                'plos_targets' => [
                    'approval-before-action UX',
                    'headless CLI output patterns',
                    'agent access-control posture',
                    'supply-chain incident lessons',
                ],
                'prohibited_use' => [
                    'import_runtime_dependency',
                    'widen_tool_permissions',
                ],
                'refresh_required_before' => [
                    'new_implementation_slice',
                    'dependency_or_runtime_change',
                    'public_claim',
                ],
                'todo_lane' => 'TODO-015',
                'todo_state' => 'watch',
                'notes' => 'Useful for permission and headless-output lessons; supply-chain events make release provenance checks mandatory before any deeper use.',
            ],
            'goose' => [
                'id' => 'goose',
                'name' => 'Goose',
                'category' => 'developer_agent',
                'posture' => 'license_review_required_adoption_candidate',
                'allowed_use' => 'review_license_then_adopt_mcp_native_patterns',
                'adoption_state' => 'not_a_dependency',
                'current_snapshot' => [
                    'version' => null,
                    'release' => null,
                    'refreshed_at' => '2026-06-05',
                    'primary_sources' => [
                        'https://github.com/block/goose',
                    ],
                ],
                'plos_targets' => [
                    'MCP-native agent workflow posture',
                    'local developer tool governance',
                    'tool boundary and extension ergonomics',
                ],
                'prohibited_use' => [
                    'import_runtime_dependency',
                    'widen_mcp_tools',
                ],
                'refresh_required_before' => [
                    'new_implementation_slice',
                    'dependency_or_runtime_change',
                    'public_claim',
                ],
                'todo_lane' => 'TODO-015',
                'todo_state' => 'watch',
                'notes' => 'Monitor as a local MCP-native agent reference only.',
            ],
            'opencode' => [
                'id' => 'opencode',
                'name' => 'opencode',
                'category' => 'developer_agent',
                'posture' => 'license_review_required_adoption_candidate',
                'allowed_use' => 'review_license_then_adopt_terminal_agent_patterns',
                'adoption_state' => 'not_a_dependency',
                'current_snapshot' => [
                    'version' => null,
                    'release' => null,
                    'refreshed_at' => '2026-06-05',
                    'primary_sources' => [
                        'https://github.com/sst/opencode',
                    ],
                ],
                'plos_targets' => [
                    'terminal coding-agent ergonomics',
                    'provider and model routing posture',
                    'developer workflow evidence patterns',
                ],
                'prohibited_use' => [
                    'import_runtime_dependency',
                    'widen_tool_permissions',
                ],
                'refresh_required_before' => [
                    'new_implementation_slice',
                    'dependency_or_runtime_change',
                    'public_claim',
                ],
                'todo_lane' => 'TODO-015',
                'todo_state' => 'watch',
                'notes' => 'Monitor as a terminal-agent reference only.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(?string $referenceId = null, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now('UTC');
        $references = $this->filterReferences($referenceId);
        $rows = array_map(fn (array $reference): array => $this->row($reference, $asOf), array_values($references));
        $statusCounts = [];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        return [
            'version' => self::VERSION,
            'generated_at' => $asOf->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'status' => $this->overallStatus($rows),
            'summary' => [
                'total' => count($rows),
                'status_counts' => $statusCounts,
                'high_caution_count' => count(array_filter(
                    $rows,
                    static fn (array $row): bool => (bool) ($row['high_caution'] ?? false)
                )),
                'refresh_required_count' => count(array_filter(
                    $rows,
                    static fn (array $row): bool => (bool) ($row['refresh_required'] ?? false)
                )),
            ],
            'references' => $rows,
        ];
    }

    public function guidanceBlock(?string $referenceId = null): string
    {
        $rows = $this->report($referenceId)['references'];
        $lines = [
            'Third-party adoption guidance:',
            '- Direct source, prompt, test, schema, documentation, and research adoption is allowed when current license and provenance review confirms compatibility.',
            '- Record the source URL, revision, license, copied or modified files, required notices, attribution, and material PLOS changes.',
            '- Do not copy proprietary, unclear-license, private, or leaked-source-derived material; high-caution provenance rows remain reference-only.',
            '- Dependencies, provider routes, scheduled jobs, and runtime behavior still require normal PLOS tests, privacy review, and operator gates.',
            '- Current private PLOS product value takes priority over hypothetical public-export compatibility.',
        ];

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '- %s: posture=%s; allowed=%s; todo=%s/%s; refresh_required_before=%s.',
                $row['name'],
                $row['posture'],
                $row['allowed_use'],
                $row['todo_lane'],
                $row['todo_state'],
                implode(',', $row['refresh_required_before'])
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function filterReferences(?string $referenceId): array
    {
        $references = $this->references();
        if ($referenceId === null || $referenceId === '' || $referenceId === 'all') {
            return $references;
        }

        $normalized = strtolower(trim($referenceId));

        return array_filter(
            $references,
            static fn (array $reference): bool => $reference['id'] === $normalized
                || strtolower((string) $reference['name']) === $normalized
        );
    }

    /**
     * @param  array<string, mixed>  $reference
     * @return array<string, mixed>
     */
    private function row(array $reference, CarbonInterface $asOf): array
    {
        $snapshot = is_array($reference['current_snapshot'] ?? null) ? $reference['current_snapshot'] : [];
        $refreshedAt = $this->dateFromString($snapshot['refreshed_at'] ?? null);
        $ageDays = $refreshedAt === null ? null : $refreshedAt->diffInDays($asOf->copy()->utc());
        $highCaution = str_contains((string) ($reference['posture'] ?? ''), 'high_caution');
        $refreshRequired = $refreshedAt === null || ($ageDays !== null && $ageDays > 14);
        $adoptionPolicy = $highCaution
            ? 'provenance_or_license_unclear_reference_only'
            : 'direct_use_allowed_after_license_and_provenance_review';
        $posture = $highCaution
            ? 'high_caution_provenance_watch'
            : 'license_review_required_adoption_candidate';
        $prohibitedUse = $highCaution
            ? $reference['prohibited_use']
            : array_values(array_filter(
                $reference['prohibited_use'],
                static fn (mixed $rule): bool => ! is_string($rule) || ! str_starts_with($rule, 'copy_')
            ));
        if (! $highCaution) {
            $prohibitedUse = array_values(array_unique(array_merge($prohibitedUse, [
                'copy_without_license_review',
                'omit_required_attribution_or_notices',
                'use_unclear_or_incompatible_provenance',
            ])));
        }

        return [
            'id' => $reference['id'],
            'name' => $reference['name'],
            'category' => $reference['category'],
            'posture' => $posture,
            'allowed_use' => $reference['allowed_use'],
            'adoption_state' => $reference['adoption_state'],
            'adoption_policy' => $adoptionPolicy,
            'license_review_required' => true,
            'status' => $refreshRequired ? 'refresh_due' : 'current',
            'high_caution' => $highCaution,
            'refresh_required' => $refreshRequired,
            'snapshot_version' => $snapshot['version'] ?? null,
            'snapshot_release' => $snapshot['release'] ?? null,
            'snapshot_revision' => $snapshot['revision'] ?? null,
            'snapshot_refreshed_at' => $snapshot['refreshed_at'] ?? null,
            'snapshot_age_days' => $ageDays,
            'primary_sources' => $snapshot['primary_sources'] ?? [],
            'plos_targets' => $reference['plos_targets'],
            'prohibited_use' => $prohibitedUse,
            'refresh_required_before' => $reference['refresh_required_before'],
            'todo_lane' => $reference['todo_lane'],
            'todo_state' => $reference['todo_state'],
            'notes' => $reference['notes'],
        ];
    }

    private function dateFromString(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function overallStatus(array $rows): string
    {
        if ($rows === []) {
            return 'missing';
        }

        foreach ($rows as $row) {
            if (($row['status'] ?? null) === 'refresh_due') {
                return 'refresh_due';
            }
        }

        return 'current';
    }
}
