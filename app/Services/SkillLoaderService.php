<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Skill Loader Service
 *
 * Reads agent skill definitions from SKILL.md files (OpenClaw pattern adapted for Laravel).
 * Implements progressive disclosure: compact index for all prompts, full content on activation.
 *
 * Skills are stored in resources/agents/skills/{skill-name}/SKILL.md by default.
 * Each SKILL.md has YAML frontmatter + markdown body with agent instructions.
 */
class SkillLoaderService
{
    private const DEFAULT_SKILLS_PATH = 'resources/agents/skills';

    /**
     * Reserved write_scope values that require extra protection beyond
     * domain-specific partitions. Today only shared_memory qualifies: any skill
     * declaring this write_scope touches cross-agent institutional state and
     * must not run without an explicit human-review gate.
     */
    public const PROTECTED_WRITE_SCOPES = ['shared_memory'];

    /** @var array|null Cached skill index */
    private ?array $skillIndex = null;

    /** @var array Cached full skill content by name */
    private array $loadedSkills = [];

    /** @var SkillVersionService|null */
    private ?SkillVersionService $versionService = null;

    /** @var array Cached version info per skill name */
    private array $versionCache = [];

    private function getVersionService(): SkillVersionService
    {
        if ($this->versionService === null) {
            $this->versionService = app(SkillVersionService::class);
        }
        return $this->versionService;
    }

    public static function configuredSkillsPath(): string
    {
        return trim((string) config('agents.skills_path', self::DEFAULT_SKILLS_PATH), '/');
    }

    public static function configuredSkillsBasePath(): string
    {
        return base_path(self::configuredSkillsPath());
    }

    private function skillsBasePath(): string
    {
        return self::configuredSkillsBasePath();
    }

    private function skillFile(string $skillName): string
    {
        return $this->skillsBasePath().'/'.$skillName.'/SKILL.md';
    }

    /**
     * Scan all skills and return compact index for prompt injection
     *
     * Returns ~1 line per skill (name + description) to keep context budget low.
     * Full skill content loaded only on activation via loadSkill().
     *
     * @return array [['name'=>string, 'description'=>string, 'schedule'=>string|null, ...], ...]
     */
    public function getSkillIndex(): array
    {
        if ($this->skillIndex !== null) {
            return $this->skillIndex;
        }

        $this->skillIndex = [];
        $basePath = $this->skillsBasePath();

        if (!is_dir($basePath)) {
            return $this->skillIndex;
        }

        $dirs = glob($basePath . '/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $skillFile = $dir . '/SKILL.md';
            if (!file_exists($skillFile)) {
                continue;
            }

            $parsed = $this->parseSkillFrontmatter($skillFile);
            if ($parsed) {
                $this->skillIndex[] = [
                    'name' => $parsed['name'] ?? basename($dir),
                    'description' => $parsed['description'] ?? '',
                    'version' => $parsed['version'] ?? '1.0.0',
                    'schedule' => $parsed['schedule'] ?? null,
                    'tools' => $parsed['tools'] ?? [],
                    'permissions' => $parsed['permissions'] ?? [],
                    'workflow_mode' => $parsed['workflow_mode'] ?? 'agentic',
                    'iteration_mode' => $parsed['iteration_mode'] ?? 'batch',
                    'runtime_role' => $parsed['runtime_role'] ?? null,
                    'write_scope' => $parsed['write_scope'] ?? null,
                    'parallel_mode' => $parsed['parallel_mode'] ?? null,
                    'review_mode' => $parsed['review_mode'] ?? null,
                    'tool_phases' => $parsed['tool_phases'] ?? [],
                    'path' => $skillFile,
                ];
            }
        }

        return $this->skillIndex;
    }

    /**
     * Build compact skill index string for prompt injection
     *
     * @return string One line per skill for context-efficient injection
     */
    public function buildSkillIndexPrompt(): string
    {
        $skills = $this->getSkillIndex();

        if (empty($skills)) {
            return '';
        }

        $lines = ["Available agent skills:"];
        foreach ($skills as $skill) {
            $tools = !empty($skill['tools']) ? ' [' . implode(', ', array_slice($skill['tools'], 0, 3)) . ']' : '';
            $runtime = $this->formatRuntimeMetadataForIndex($skill);
            $lines[] = "- {$skill['name']}: {$skill['description']}{$tools}{$runtime}";
        }

        return implode("\n", $lines);
    }

    /**
     * Render runtime metadata fields compactly for the skill-index prompt.
     * Only emits fields that are set; skips silently when all four are null.
     */
    private function formatRuntimeMetadataForIndex(array $skill): string
    {
        $parts = [];
        foreach (['runtime_role', 'write_scope', 'parallel_mode', 'review_mode'] as $field) {
            $value = $skill[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $parts[] = $field . '=' . $value;
            }
        }

        return $parts === [] ? '' : ' (' . implode(', ', $parts) . ')';
    }

    /**
     * True when this skill declares a protected write_scope. Downstream
     * gates (guardrails, review queues) should refuse to auto-approve any
     * output from such skills without human sign-off.
     */
    public function hasProtectedWriteScope(string $skillName): bool
    {
        $skill = $this->loadSkill($skillName);
        $scope = $skill['frontmatter']['write_scope'] ?? null;

        return is_string($scope) && in_array($scope, self::PROTECTED_WRITE_SCOPES, true);
    }

    /**
     * List every distinct write_scope currently declared across all skills.
     * Useful for drift-report consumers (B3) to see coverage at a glance.
     */
    public function listDeclaredWriteScopes(): array
    {
        $scopes = [];
        foreach ($this->getSkillIndex() as $skill) {
            $scope = $skill['write_scope'] ?? null;
            if (is_string($scope) && $scope !== '') {
                $scopes[$scope] = true;
            }
        }

        return array_keys($scopes);
    }

    /**
     * Load full skill content for active use
     *
     * @param string $skillName Skill name (directory name)
     * @return array|null Full skill data with frontmatter + body, or null if not found
     */
    public function loadSkill(string $skillName): ?array
    {
        if (isset($this->loadedSkills[$skillName])) {
            return $this->loadedSkills[$skillName];
        }

        $skillFile = $this->skillFile($skillName);

        if (!file_exists($skillFile)) {
            Log::warning("SkillLoaderService: Skill not found", ['skill' => $skillName]);
            return null;
        }

        $content = file_get_contents($skillFile);
        $parsed = $this->parseSkillFile($content);

        if (!$parsed) {
            return null;
        }

        // Track version in DB (non-blocking — catches errors to avoid disrupting agent execution)
        try {
            $versionInfo = $this->getVersionService()->trackVersion(
                $skillName,
                $parsed['frontmatter'],
                $parsed['body'],
                $content
            );
            $parsed['version_info'] = $versionInfo;
            $this->versionCache[$skillName] = $versionInfo;
        } catch (\Throwable $e) {
            Log::warning("SkillLoaderService: Version tracking failed (non-fatal)", [
                'skill' => $skillName,
                'error' => $e->getMessage(),
            ]);
            $parsed['version_info'] = null;
        }

        $this->loadedSkills[$skillName] = $parsed;

        // Cache max_timeout_minutes for adaptive timeout ceiling resolution
        if (isset($parsed['frontmatter']['max_timeout_minutes'])) {
            Cache::put(
                "skill_max_timeout:{$skillName}",
                (int) $parsed['frontmatter']['max_timeout_minutes'],
                3600
            );
        }

        Log::debug("SkillLoaderService: Skill loaded", [
            'skill' => $skillName,
            'version' => $parsed['frontmatter']['version'] ?? '1.0.0',
            'tools_count' => count($parsed['frontmatter']['tools'] ?? []),
            'is_new_version' => $versionInfo['is_new'] ?? false,
        ]);

        return $parsed;
    }

    /**
     * Get the instruction body of a skill (markdown content after frontmatter)
     *
     * @param string $skillName Skill name
     * @return string|null Skill instructions or null if not found
     */
    public function getSkillInstructions(string $skillName): ?string
    {
        $skill = $this->loadSkill($skillName);
        return $skill['body'] ?? null;
    }

    /**
     * Get skill frontmatter configuration
     *
     * @param string $skillName Skill name
     * @return array|null Frontmatter data or null
     */
    public function getSkillConfig(string $skillName): ?array
    {
        $skill = $this->loadSkill($skillName);
        return $skill['frontmatter'] ?? null;
    }

    /**
     * List all available skill names
     *
     * @return array Skill names
     */
    public function listSkills(): array
    {
        return array_column($this->getSkillIndex(), 'name');
    }

    /**
     * Check if a skill exists
     */
    public function skillExists(string $skillName): bool
    {
        return file_exists($this->skillFile($skillName));
    }

    /**
     * Parse YAML frontmatter from a SKILL.md file (header only, for index)
     */
    private function parseSkillFrontmatter(string $filePath): ?array
    {
        $content = file_get_contents($filePath);

        if (!str_starts_with(trim($content), '---')) {
            return null;
        }

        $parts = preg_split('/^---\s*$/m', $content, 3);

        if (count($parts) < 3) {
            return null;
        }

        return $this->parseYaml($parts[1]);
    }

    /**
     * Parse full SKILL.md file (frontmatter + body)
     */
    private function parseSkillFile(string $content): ?array
    {
        if (!str_starts_with(trim($content), '---')) {
            return ['frontmatter' => [], 'body' => $content];
        }

        $parts = preg_split('/^---\s*$/m', $content, 3);

        if (count($parts) < 3) {
            return ['frontmatter' => [], 'body' => $content];
        }

        $frontmatter = $this->parseYaml($parts[1]);
        $body = trim($parts[2]);

        return [
            'frontmatter' => $frontmatter,
            'body' => $body,
        ];
    }

    /**
     * Simple YAML parser for frontmatter (no Symfony YAML dependency needed)
     * Handles: scalar values, simple lists (- item), 2-level nested maps with lists
     *
     * Supports:
     *   key: value              → ['key' => 'value']
     *   key:                    → ['key' => []]
     *     - item                → ['key' => ['item']]
     *   key:                    → ['key' => ['sub1' => ['a','b'], 'sub2' => ['c']]]
     *     sub1:
     *       - a
     *       - b
     *     sub2:
     *       - c
     */
    private function parseYaml(string $yaml): array
    {
        $result = [];
        $lines = explode("\n", $yaml);
        $currentKey = null;      // Top-level key (indent 0)
        $currentSubKey = null;   // Second-level key (indent 2)
        $isNestedMap = false;    // Whether currentKey contains a nested map (not a flat list)

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Strip inline YAML comments (e.g., "tool_name  # description")
            // Only strip when # is preceded by whitespace to avoid false positives
            $trimmed = preg_replace('/\s+#\s.*$/', '', $trimmed);
            $line = preg_replace('/\s+#\s.*$/', '', $line);

            // Detect indentation level
            $indent = strlen($line) - strlen(ltrim($line));

            // List item at 6+ spaces (fourth level: 3rd-level map's list)
            if ($indent >= 6 && preg_match('/^\s+-\s+(.+)$/', $line, $m) && $currentKey && $currentSubKey && $isNestedMap) {
                // Append to current sub-sub-key's list (e.g., recursion.budget.items[])
                // Find last key in the sub-key map that is an array
                if (is_array($result[$currentKey][$currentSubKey])) {
                    $lastSubSubKey = array_key_last($result[$currentKey][$currentSubKey]);
                    if ($lastSubSubKey !== null && is_array($result[$currentKey][$currentSubKey][$lastSubSubKey])) {
                        $result[$currentKey][$currentSubKey][$lastSubSubKey][] = trim($m[1]);
                        continue;
                    }
                }
                // Fallback: append to sub-key directly
                $result[$currentKey][$currentSubKey][] = trim($m[1]);
                continue;
            }

            // List item at 4+ spaces (third level: nested map's list)
            if ($indent >= 4 && preg_match('/^\s+-\s+(.+)$/', $line, $m) && $currentKey && $currentSubKey && $isNestedMap) {
                $result[$currentKey][$currentSubKey][] = trim($m[1]);
                continue;
            }

            // Key: value at 4+ spaces (third level: sub-sub-key under current sub-key)
            if ($indent >= 4 && preg_match('/^\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*:\s*(.*)$/', $line, $m) && $currentKey && $currentSubKey && $isNestedMap) {
                $subSubKey = $m[1];
                $subSubValue = trim($m[2]);

                // Ensure sub-key is a map
                if (!is_array($result[$currentKey][$currentSubKey])) {
                    $result[$currentKey][$currentSubKey] = [];
                }

                if ($subSubValue === '' || $subSubValue === '[]') {
                    $result[$currentKey][$currentSubKey][$subSubKey] = [];
                } else {
                    $result[$currentKey][$currentSubKey][$subSubKey] = $this->parseScalar($subSubValue);
                }
                continue;
            }

            // List item at 2+ spaces (second level: flat list under top-level key)
            if ($indent >= 2 && preg_match('/^\s+-\s+(.+)$/', $line, $m) && $currentKey && !$isNestedMap) {
                if (!is_array($result[$currentKey])) {
                    $result[$currentKey] = [];
                }
                $result[$currentKey][] = trim($m[1]);
                continue;
            }

            // Sub-key at 2 spaces indentation (nested map entry)
            if ($indent >= 2 && preg_match('/^\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*:\s*(.*)$/', $line, $m) && $currentKey) {
                $subKey = $m[1];
                $subValue = trim($m[2]);
                $currentSubKey = $subKey;
                $isNestedMap = true;

                if (!is_array($result[$currentKey]) || array_is_list($result[$currentKey])) {
                    $result[$currentKey] = [];
                }

                if ($subValue === '' || $subValue === '[]') {
                    $result[$currentKey][$subKey] = [];
                } else {
                    $result[$currentKey][$subKey] = $this->parseScalar($subValue);
                }
                continue;
            }

            // Top-level key: value pair (no indentation)
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*:\s*(.*)$/', $trimmed, $m) && $indent === 0) {
                $key = $m[1];
                $value = trim($m[2]);
                $currentKey = $key;
                $currentSubKey = null;
                $isNestedMap = false;

                if ($value === '' || $value === '[]') {
                    $result[$key] = [];
                } else {
                    $result[$key] = $this->parseScalar($value);
                }
            }
        }

        return $result;
    }

    /**
     * Parse a scalar YAML value
     */
    private function parseScalar(string $value): mixed
    {
        if ($value === 'null' || $value === '~') {
            return null;
        }
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        if (preg_match('/^["\'](.*)["\']\s*$/', $value, $qm)) {
            return $qm[1];
        }
        return $value;
    }

    /**
     * Get version info for a loaded skill (from cache after loadSkill)
     *
     * @return array|null ['version_id' => int, 'version' => string, 'is_new' => bool]
     */
    public function getVersionInfo(string $skillName): ?array
    {
        return $this->versionCache[$skillName] ?? null;
    }

    /**
     * Clear cached data
     */
    public function clearCache(): void
    {
        $this->skillIndex = null;
        $this->loadedSkills = [];
        $this->versionCache = [];
    }

    /**
     * AG-16: Export a PLOS SKILL.md to AgentSkills.io standard format.
     *
     * Maps PLOS frontmatter to the Anthropic/Microsoft/OpenAI standard:
     * - name → agent_id
     * - description → description
     * - permissions → capabilities
     * - tool_phases → tools (flattened with phase metadata)
     * - workflow_mode → execution_mode
     *
     * @param string $skillName Agent/skill name
     * @return array|null Standard-format agent card, or null if not found
     */
    public function exportToAgentSkillsFormat(string $skillName): ?array
    {
        $skill = $this->loadSkill($skillName);
        if (!$skill) {
            return null;
        }

        $config = $skill['frontmatter'] ?? $skill['config'] ?? [];

        // Flatten tool_phases into a single tools list with phase metadata
        $tools = [];
        $toolPhases = $config['tool_phases'] ?? [];
        if (!empty($toolPhases)) {
            foreach ($toolPhases as $phase => $phaseTools) {
                foreach ($phaseTools as $toolName) {
                    $tools[] = [
                        'name' => $toolName,
                        'phase' => $phase,
                    ];
                }
            }
        }

        // Map permissions to capabilities
        $capabilities = [];
        foreach ($config['permissions'] ?? [] as $perm) {
            [$domain, $level] = explode(':', $perm) + [null, null];
            $capabilities[] = [
                'domain' => $domain,
                'access' => $level ?? 'read',
            ];
        }

        return [
            // AgentSkills.io standard fields
            'agent_id' => $config['name'] ?? $skillName,
            'version' => $config['version'] ?? '1.0.0',
            'description' => $config['description'] ?? '',
            'execution_mode' => match ($config['workflow_mode'] ?? 'agentic') {
                'auto' => 'autonomous',
                'agentic' => 'autonomous',
                'hybrid' => 'semi_autonomous',
                'deterministic' => 'sequential',
                default => 'autonomous',
            },
            'capabilities' => $capabilities,
            'tools' => $tools,
            'constraints' => [
                'max_iterations' => $config['max_iterations'] ?? 15,
                'max_tokens' => $config['max_tokens'] ?? 50000,
                'temperature' => $config['temperature'] ?? 0.3,
                'num_ctx' => $config['num_ctx'] ?? null,
            ],
            'schedule' => $config['schedule'] ?? null,
            'notifications' => $config['notifications'] ?? null,

            // PLOS extensions (non-standard, prefixed)
            'x_plos' => [
                'workflow_mode' => $config['workflow_mode'] ?? 'agentic',
                'default_mode' => $config['default_mode'] ?? 'agentic',
                'iteration_mode' => $config['iteration_mode'] ?? 'batch',
                'model_role' => $config['model_role'] ?? 'standard',
                'runtime_role' => $config['runtime_role'] ?? null,
                'write_scope' => $config['write_scope'] ?? null,
                'parallel_mode' => $config['parallel_mode'] ?? null,
                'review_mode' => $config['review_mode'] ?? null,
                'tool_phases' => $toolPhases,
            ],
        ];
    }

    /**
     * AG-16: Export all skills to AgentSkills.io format.
     *
     * @return array Array of standard-format agent cards
     */
    public function exportAllToAgentSkillsFormat(): array
    {
        $skills = $this->getSkillIndex();
        $exported = [];

        foreach ($skills as $skill) {
            $card = $this->exportToAgentSkillsFormat($skill['name']);
            if ($card) {
                $exported[] = $card;
            }
        }

        return $exported;
    }
}
