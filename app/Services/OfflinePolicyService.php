<?php

namespace App\Services;

use App\DTOs\PolicyDecision;
use Illuminate\Support\Facades\Log;

/**
 * OfflinePolicyService — single authority for 3b offline/hybrid decisions (P02b).
 *
 * All consumers (AgentGuardrailService, AgentToolRegistryService, MCPRouter,
 * AIService, LLMPoolManagerService, RoutingProfileCommand) route through this
 * service instead of implementing their own profile logic. The matrix lives
 * in `config/offline_policy.php`; this class reads it and applies per-call
 * classification to evaluate each request.
 *
 * Five evaluators:
 *   - evaluateOperation($op, $context)  — tool-class / path / remote-domain gating
 *   - evaluateMcpServer($name)          — MCP trust boundary + per-server allowlist
 *   - evaluatePath($path, $mode)        — path class only (used by MCP filesystem ops)
 *   - evaluateProvider($instance)       — LLM provider class (local_llm vs cloud_*)
 *   - evaluateRemoteDomain($host)       — standalone remote domain class check
 *
 * Every evaluator returns a PolicyDecision DTO. Callers never apply their own
 * allow/deny logic beyond the decision fields.
 *
 * Fail-closed: any lookup error falls back to the `default` profile name but
 * the caller should combine this with routing.offline_mode (shipped in
 * commit f035ab31) which independently refuses cloud providers when set.
 */
class OfflinePolicyService
{
    public function __construct(
        private ?OfflineAuditService $audit = null,
    ) {}

    private function auditOperation(PolicyDecision $decision, string $operation, array $context): void
    {
        if (($context['_audit'] ?? true) === false) {
            return;
        }

        try {
            $audit = $this->audit ?? app(OfflineAuditService::class);
            $audit->recordDecision(
                decision: $decision,
                operation: $operation,
                context: $context,
                actor: $context['agent_id'] ?? null,
                mcpServer: $context['mcp_server'] ?? null,
                target: $context['path'] ?? $context['remote_domain'] ?? null,
                offlineModeActive: $this->isOfflineModeActive(),
            );
        } catch (\Throwable $e) {
            Log::debug('OfflinePolicyService: audit write failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Read the active profile name from system_configs. Returns 'default' when
     * no row exists, the value is empty, or SystemConfigService raises.
     */
    public function activeProfile(): string
    {
        $default = (string) config('offline_policy.active_profile_default', 'default');

        try {
            $svc = app(SystemConfigService::class);
            $key = (string) config('offline_policy.active_profile_config_key', 'routing.active_profile');
            $value = $svc->get($key, $default);

            if (! is_string($value) || trim($value) === '') {
                return $default;
            }

            $value = strtolower(trim($value));
            $profiles = (array) config('offline_policy.profiles', []);

            return array_key_exists($value, $profiles) ? $value : $default;
        } catch (\Throwable $e) {
            Log::warning('OfflinePolicyService: active profile lookup failed', [
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    /**
     * routing.offline_mode kill switch (parity with AIService::isOfflineMode()).
     * Fail-closed: any exception → true.
     */
    public function isOfflineModeActive(): bool
    {
        try {
            $svc = app(SystemConfigService::class);
            $key = (string) config('offline_policy.offline_mode_config_key', 'routing.offline_mode');
            $value = $svc->get($key, 'disabled');

            if (! is_string($value)) {
                return true;
            }

            return strtolower(trim($value)) !== 'disabled';
        } catch (\Throwable $e) {
            Log::warning('OfflinePolicyService: offline mode lookup failed', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Map an operation name to its tool class via config/offline_policy.php.
     * Unknown operations return 'unknown' — refused by every non-default profile.
     */
    public function classifyOperation(string $operation): string
    {
        $map = (array) config('offline_policy.operation_class_map', []);

        return $map[$operation] ?? 'unknown';
    }

    /**
     * Fix C (2026-04-19): classify `tool:<name>` operations emitted by
     * AgentToolRegistryService. The registry knows each tool's risk_level
     * ('read' | 'write' | 'destructive' | 'blocked') from the DB — pass it
     * as context and we map it to the 3b tool class. For MCP-backed tools
     * the caller should route directly to evaluateMcpTool, which also
     * enforces server-admission and trust boundary; this helper covers the
     * non-MCP case and the blended fallback.
     *
     * risk_level → tool_class:
     *   read        → read
     *   write       → bounded-write
     *   destructive → command-dangerous
     *   blocked     → command-dangerous  (already refused upstream)
     *   (absent)    → fallback to explicit operation_class_map; then unknown
     */
    public function classifyToolOperation(string $operation, array $context): string
    {
        $map = [
            'read' => 'read',
            'write' => 'bounded-write',
            'destructive' => 'command-dangerous',
            'blocked' => 'command-dangerous',
        ];

        $risk = $context['risk_level'] ?? null;
        if (is_string($risk) && isset($map[$risk])) {
            return $map[$risk];
        }

        // Fall back to explicit operation_class_map — this lets operators
        // register a tool-specific override by adding `tool:<name>` to the
        // map, which takes precedence even over risk_level absence.
        return (string) (config('offline_policy.operation_class_map', [])[$operation] ?? 'unknown');
    }

    /**
     * Classify a filesystem path into a path_class. Protected paths always win
     * regardless of location — they force `protected_read`/`protected_write`
     * even when the path sits inside the repo or an additional dir.
     *
     * R1 (2026-04-19 defect fix): `classifyPath` previously treated any
     * non-absolute path as repo-scoped, which let `../../tmp/poc.txt` escape
     * the repo and still be classified as `repo_write`. Relative paths are
     * now normalized against the repo root and `..` segments are collapsed
     * before classification. If the resolved path sits outside both the repo
     * root and every configured additional directory, it is treated as
     * protected (fail-closed).
     */
    public function classifyPath(string $path, string $mode = 'read'): string
    {
        $write = $mode === 'write';

        $repoRoot = rtrim(base_path(), '/');
        $additionalDirs = array_values(array_filter(array_map(
            static fn ($d) => $d === null || $d === '' ? null : rtrim((string) $d, '/'),
            array_merge(
                [storage_path()],
                (array) config('offline_policy.additional_dirs', []),
            )
        )));

        // R1: normalize relative paths against repo root and collapse `..`
        // segments. `classifyPath('../../tmp/poc.txt')` now resolves to an
        // absolute path outside the repo and is refused, not classified as
        // repo_write.
        $normalized = $this->normalizePathAgainstRepo($path, $repoRoot);

        // Finding H1: follow symlinks so a repo-internal symlink pointing at
        // /etc/passwd can't pose as repo_*. If the physical target differs
        // from the lexically normalized path, the physical target wins for
        // classification. Unresolvable paths fall back to the lexical form.
        $normalized = $this->resolvePhysicalPath($normalized);

        // Protected-path regex runs against BOTH the original input (to
        // catch payloads like `credentials.json` that a caller might pass
        // as a relative name) AND the normalized absolute form (to catch
        // `../../.env` that resolves to the real .env).
        foreach ((array) config('offline_policy.protected_paths', []) as $pattern) {
            if (@preg_match($pattern, $path) === 1
                || @preg_match($pattern, $normalized) === 1
            ) {
                return $write ? 'protected_write' : 'protected_read';
            }
        }

        // Inside repo root?
        if ($normalized === $repoRoot || str_starts_with($normalized, $repoRoot.'/')) {
            return $write ? 'repo_write' : 'repo_read';
        }

        // Inside any configured additional dir?
        foreach ($additionalDirs as $dir) {
            if ($normalized === $dir || str_starts_with($normalized, $dir.'/')) {
                return $write ? 'additional_dir_write' : 'additional_dir_read';
            }
        }

        // Everything else — absolute path outside repo/additional dirs, or a
        // traversal that escaped the repo root — is treated as protected so
        // non-default profiles refuse it.
        return $write ? 'protected_write' : 'protected_read';
    }

    /**
     * Resolve a user-supplied path (absolute or relative) against the repo
     * root. Collapses `.` and `..` segments without touching the filesystem
     * so the result is stable for non-existent targets.
     */
    private function normalizePathAgainstRepo(string $path, string $repoRoot): string
    {
        if ($path === '') {
            return $repoRoot;
        }

        $absolute = str_starts_with($path, '/') ? $path : $repoRoot.'/'.$path;

        $parts = explode('/', $absolute);
        $stack = [];
        foreach ($parts as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($stack);

                continue;
            }
            $stack[] = $segment;
        }

        return '/'.implode('/', $stack);
    }

    /**
     * Walk the ancestor chain until a real filesystem target is found, then
     * rejoin any unresolved tail. Mirrors RepoDevMCPService so symlinks are
     * resolved uniformly regardless of caller entrypoint.
     */
    private function resolvePhysicalPath(string $absolute): string
    {
        $candidate = $absolute;
        for ($i = 0; $i < 32; $i++) {
            if ($candidate === '' || $candidate === '/') {
                return $absolute;
            }
            $real = @realpath($candidate);
            if ($real !== false) {
                if ($candidate === $absolute) {
                    return $real;
                }
                $tail = substr($absolute, strlen($candidate));

                return rtrim($real, '/').$tail;
            }
            $candidate = dirname($candidate);
        }

        return $absolute;
    }

    /**
     * Classify a hostname into plos_lan / approved_cloud / wild_internet.
     */
    public function classifyRemoteDomain(string $host): string
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return 'wild_internet';
        }

        foreach ((array) config('offline_policy.plos_lan_host_patterns', []) as $pattern) {
            if (@preg_match($pattern, $host) === 1) {
                return 'plos_lan';
            }
        }

        foreach ((array) config('offline_policy.approved_cloud_hosts', []) as $allowed) {
            if ($host === strtolower((string) $allowed)) {
                return 'approved_cloud';
            }
        }

        return 'wild_internet';
    }

    /**
     * Classify an llm_instances row into local_llm / cloud_sensitive_safe / cloud_external.
     *
     * @param  object|array  $instance
     */
    public function classifyProvider($instance): string
    {
        $type = $this->readField($instance, 'instance_type');
        if ($type === 'ollama') {
            return 'local_llm';
        }

        $capabilities = $this->decodeJsonField($instance, 'capabilities');
        $config = $this->decodeJsonField($instance, 'config');

        $sensitiveSafe = (bool) ($config['sensitive_safe'] ?? false)
            || in_array('sensitive_safe', $capabilities, true);

        return $sensitiveSafe ? 'cloud_sensitive_safe' : 'cloud_external';
    }

    /**
     * Evaluate an operation against the active (or supplied) profile.
     *
     * Context keys honored:
     *   - path            → classifyPath + allowed_path_classes gate
     *   - remote_domain   → classifyRemoteDomain + allowed_remote_domain_classes gate
     */
    public function evaluateOperation(string $operation, array $context = [], ?string $profile = null): PolicyDecision
    {
        $profile = $profile ?? $this->activeProfile();
        $profileDef = $this->profileDef($profile);
        if ($profileDef === null) {
            $decision = PolicyDecision::deny("Unknown profile '{$profile}'", $profile);
            $this->auditOperation($decision, "path:{$mode}", ['path' => $path]);

            return $decision;
        }

        // Fix C: when AgentToolRegistryService dispatches a registry tool,
        // it emits `tool:<name>` as the operation. Route that through
        // classifyToolOperation so risk_level from the DB row drives the
        // class assignment. MCP-backed registry tools come in with
        // mcp_server+mcp_tool context — evaluateMcpTool is the stricter
        // path and is used by callers who know the tool is MCP-backed.
        if (str_starts_with($operation, 'tool:')) {
            if (! empty($context['mcp_server']) && ! empty($context['mcp_tool'])) {
                return $this->evaluateMcpTool(
                    (string) $context['mcp_server'],
                    (string) $context['mcp_tool'],
                    $context,
                    $profile,
                );
            }
            $class = $this->classifyToolOperation($operation, $context);
        } else {
            $class = $this->classifyOperation($operation);
        }
        $allowedClasses = (array) ($profileDef['allowed_tool_classes'] ?? []);

        if ($class === 'unknown') {
            if ($profile === 'default') {
                // Under the default profile, pass unknown operations through so
                // downstream guardrail rules / safety patterns still decide.
                // Classification refinement belongs in config/offline_policy.php,
                // not in a blanket denial at this layer.
                $d = PolicyDecision::allow(
                    "Operation '{$operation}' passed through (unknown class, profile=default)",
                    $profile, toolClass: $class,
                );
                $this->auditOperation($d, $operation, $context);

                return $d;
            }

            $d = PolicyDecision::deny(
                "Operation '{$operation}' has no registered tool class — refused under '{$profile}'",
                $profile, toolClass: $class,
            );
            $this->auditOperation($d, $operation, $context);

            return $d;
        }

        if (! in_array($class, $allowedClasses, true)) {
            $d = PolicyDecision::deny(
                "Tool class '{$class}' for operation '{$operation}' not allowed under profile '{$profile}'",
                $profile, toolClass: $class,
            );
            $this->auditOperation($d, $operation, $context);

            return $d;
        }

        // Path gate — only applied under non-default profiles. Under `default`,
        // the existing safety/rule checks (DANGEROUS_PATH_PATTERNS) handle
        // protection; the 3b policy layer does not add path restrictions.
        if (! empty($context['path']) && $profile !== 'default') {
            $writeLikeClasses = ['bounded-write', 'command-safe', 'command-dangerous', 'deploy'];
            $pathMode = in_array($class, $writeLikeClasses, true) ? 'write' : 'read';
            $pathClass = $this->classifyPath((string) $context['path'], $pathMode);
            $allowedPaths = (array) ($profileDef['allowed_path_classes'] ?? []);

            if (in_array($pathClass, ['protected_read', 'protected_write'], true)) {
                $d = PolicyDecision::deny(
                    "Path '{$context['path']}' is protected under profile '{$profile}'",
                    $profile, toolClass: $class, pathClass: $pathClass,
                );
                $this->auditOperation($d, $operation, $context);

                return $d;
            }

            if (! in_array($pathClass, $allowedPaths, true)) {
                $d = PolicyDecision::deny(
                    "Path class '{$pathClass}' not allowed under profile '{$profile}'",
                    $profile, toolClass: $class, pathClass: $pathClass,
                );
                $this->auditOperation($d, $operation, $context);

                return $d;
            }
        }

        // Remote domain gate
        if (! empty($context['remote_domain'])) {
            $domClass = $this->classifyRemoteDomain((string) $context['remote_domain']);
            $allowedDomains = (array) ($profileDef['allowed_remote_domain_classes'] ?? []);

            if (! in_array($domClass, $allowedDomains, true)) {
                $d = PolicyDecision::deny(
                    "Remote domain class '{$domClass}' not allowed under profile '{$profile}'",
                    $profile, toolClass: $class, remoteDomainClass: $domClass,
                );
                $this->auditOperation($d, $operation, $context);

                return $d;
            }
        }

        $requiresConfirmation = in_array(
            $class,
            (array) ($profileDef['confirmation'] ?? []),
            true,
        );

        $decision = PolicyDecision::allow(
            "Operation '{$operation}' allowed as '{$class}' under '{$profile}'",
            $profile,
            requiresConfirmation: $requiresConfirmation,
            toolClass: $class,
        );
        $this->auditOperation($decision, $operation, $context);

        return $decision;
    }

    /**
     * R2 (2026-04-19): classify an MCP tool by `{server}.{tool}` key via
     * `config/offline_policy.mcp_tool_class_map`, with wildcard fallback
     * (`{server}.*`) and a final default from `mcp_tool_default_class`.
     */
    public function classifyMcpTool(string $serverName, string $toolName): string
    {
        $map = (array) config('offline_policy.mcp_tool_class_map', []);
        $default = (string) config('offline_policy.mcp_tool_default_class', 'read');

        $exact = $serverName.'.'.$toolName;
        if (array_key_exists($exact, $map)) {
            return (string) $map[$exact];
        }

        $wildcard = $serverName.'.*';
        if (array_key_exists($wildcard, $map)) {
            return (string) $map[$wildcard];
        }

        return $default;
    }

    /**
     * R2 (2026-04-19): evaluate an MCP tool call AFTER the server gate passes.
     * Combines server admission (`evaluateMcpServer`) with per-tool class
     * enforcement. This closes the "admitted server, mutating tool" gap
     * where `offline_review` admitted `nextcloud-files` and then ran
     * `upload-file` anyway.
     */
    public function evaluateMcpTool(string $serverName, string $toolName, array $context = [], ?string $profile = null): PolicyDecision
    {
        $profile = $profile ?? $this->activeProfile();

        $serverDecision = $this->evaluateMcpServer($serverName, $profile, false);
        if (! $serverDecision->allowed) {
            $this->auditOperation($serverDecision, "mcp:{$serverName}.{$toolName}", array_merge($context, [
                'mcp_server' => $serverName,
                'mcp_tool' => $toolName,
            ]));

            return $serverDecision;
        }

        $toolClass = $this->classifyMcpTool($serverName, $toolName);
        $profileDef = $this->profileDef($profile);
        if ($profileDef === null) {
            $decision = PolicyDecision::deny("Unknown profile '{$profile}'", $profile);
            $this->auditOperation($decision, "mcp:{$serverName}.{$toolName}", $context);

            return $decision;
        }

        // Fix D (2026-04-19): fail-closed for unmapped MCP tools under
        // non-default profiles. An unclassified tool means the catalog
        // has drifted past the policy map — refuse it rather than
        // silently treating as read. `default` profile preserves prior
        // behavior (pass-through) so existing usage in a relaxed mode is
        // not impacted.
        if ($toolClass === 'unclassified') {
            if ($profile !== 'default') {
                $decision = PolicyDecision::deny(
                    "MCP tool '{$serverName}.{$toolName}' has no mcp_tool_class_map entry — refused under profile '{$profile}' (fail-closed default)",
                    $profile,
                    toolClass: $toolClass,
                    mcpTrustBoundary: $serverDecision->mcpTrustBoundary,
                );
                $this->auditOperation($decision, "mcp:{$serverName}.{$toolName}", $context);

                return $decision;
            }
            // Fall through and allow under default only.
        }

        $allowedClasses = (array) ($profileDef['allowed_tool_classes'] ?? []);

        if ($toolClass !== 'unclassified' && ! in_array($toolClass, $allowedClasses, true)) {
            $decision = PolicyDecision::deny(
                "MCP tool '{$serverName}.{$toolName}' (class '{$toolClass}') not allowed under profile '{$profile}'",
                $profile,
                toolClass: $toolClass,
                mcpTrustBoundary: $serverDecision->mcpTrustBoundary,
            );
            $this->auditOperation($decision, "mcp:{$serverName}.{$toolName}", $context);

            return $decision;
        }

        // Honor path gate for filesystem-like tools whose params include a path.
        if (! empty($context['path']) && $profile !== 'default') {
            $writeLikeClasses = ['bounded-write', 'command-safe', 'command-dangerous', 'deploy'];
            $pathMode = in_array($toolClass, $writeLikeClasses, true) ? 'write' : 'read';
            $pathClass = $this->classifyPath((string) $context['path'], $pathMode);
            $allowedPaths = (array) ($profileDef['allowed_path_classes'] ?? []);

            if (in_array($pathClass, ['protected_read', 'protected_write'], true)) {
                $decision = PolicyDecision::deny(
                    "MCP tool '{$serverName}.{$toolName}' refused: path '{$context['path']}' is protected under profile '{$profile}'",
                    $profile,
                    toolClass: $toolClass,
                    mcpTrustBoundary: $serverDecision->mcpTrustBoundary,
                    pathClass: $pathClass,
                );
                $this->auditOperation($decision, "mcp:{$serverName}.{$toolName}", $context);

                return $decision;
            }

            if (! in_array($pathClass, $allowedPaths, true)) {
                $decision = PolicyDecision::deny(
                    "MCP tool '{$serverName}.{$toolName}' refused: path class '{$pathClass}' not allowed under profile '{$profile}'",
                    $profile,
                    toolClass: $toolClass,
                    mcpTrustBoundary: $serverDecision->mcpTrustBoundary,
                    pathClass: $pathClass,
                );
                $this->auditOperation($decision, "mcp:{$serverName}.{$toolName}", $context);

                return $decision;
            }
        }

        $requiresConfirmation = in_array(
            $toolClass,
            (array) ($profileDef['confirmation'] ?? []),
            true,
        );

        $decision = PolicyDecision::allow(
            "MCP tool '{$serverName}.{$toolName}' allowed as '{$toolClass}' under profile '{$profile}'",
            $profile,
            requiresConfirmation: $requiresConfirmation,
            toolClass: $toolClass,
            mcpTrustBoundary: $serverDecision->mcpTrustBoundary,
        );
        $this->auditOperation($decision, "mcp:{$serverName}.{$toolName}", $context);

        return $decision;
    }

    /**
     * Evaluate an MCP server invocation against the active profile.
     *
     * Both the profile's allowed_mcp_trust AND the server's own
     * offline_profiles_allowed / hybrid_profiles_allowed list must permit it.
     * Missing trust_boundary metadata => refusal (safety default).
     */
    public function evaluateMcpServer(string $serverName, ?string $profile = null, bool $audit = true): PolicyDecision
    {
        $profile = $profile ?? $this->activeProfile();
        $profileDef = $this->profileDef($profile);
        if ($profileDef === null) {
            $decision = PolicyDecision::deny("Unknown profile '{$profile}'", $profile);
            if ($audit) {
                $this->auditOperation($decision, "mcp_server:{$serverName}", ['mcp_server' => $serverName]);
            }

            return $decision;
        }

        $server = (array) config("mcp.servers.{$serverName}", []);
        if ($server === []) {
            $decision = PolicyDecision::deny("MCP server '{$serverName}' not found", $profile);
            if ($audit) {
                $this->auditOperation($decision, "mcp_server:{$serverName}", ['mcp_server' => $serverName]);
            }

            return $decision;
        }
        if (empty($server['enabled'])) {
            $decision = PolicyDecision::deny("MCP server '{$serverName}' is disabled", $profile);
            if ($audit) {
                $this->auditOperation($decision, "mcp_server:{$serverName}", ['mcp_server' => $serverName]);
            }

            return $decision;
        }

        $trustBoundary = $server['trust_boundary'] ?? null;
        if (! is_string($trustBoundary) || $trustBoundary === '') {
            $decision = PolicyDecision::deny(
                "MCP server '{$serverName}' has no trust_boundary — refused for safety",
                $profile,
            );
            if ($audit) {
                $this->auditOperation($decision, "mcp_server:{$serverName}", ['mcp_server' => $serverName]);
            }

            return $decision;
        }

        $allowedTrust = (array) ($profileDef['allowed_mcp_trust'] ?? []);
        if (! in_array($trustBoundary, $allowedTrust, true)) {
            $decision = PolicyDecision::deny(
                "MCP trust boundary '{$trustBoundary}' on '{$serverName}' not allowed under profile '{$profile}'",
                $profile, mcpTrustBoundary: $trustBoundary,
            );
            if ($audit) {
                $this->auditOperation($decision, "mcp_server:{$serverName}", ['mcp_server' => $serverName]);
            }

            return $decision;
        }

        $offlineAllowed = (array) ($server['offline_profiles_allowed'] ?? []);
        $hybridAllowed = (array) ($server['hybrid_profiles_allowed'] ?? []);

        if (in_array($profile, ['offline_review', 'offline_dev_assist'], true)
            && ! in_array($profile, $offlineAllowed, true)
        ) {
            $decision = PolicyDecision::deny(
                "MCP server '{$serverName}' not in offline_profiles_allowed for profile '{$profile}'",
                $profile, mcpTrustBoundary: $trustBoundary,
            );
            if ($audit) {
                $this->auditOperation($decision, "mcp_server:{$serverName}", ['mcp_server' => $serverName]);
            }

            return $decision;
        }

        if (in_array($profile, ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'], true)
            && ! in_array($profile, $hybridAllowed, true)
        ) {
            $decision = PolicyDecision::deny(
                "MCP server '{$serverName}' not in hybrid_profiles_allowed for profile '{$profile}'",
                $profile, mcpTrustBoundary: $trustBoundary,
            );
            if ($audit) {
                $this->auditOperation($decision, "mcp_server:{$serverName}", ['mcp_server' => $serverName]);
            }

            return $decision;
        }

        $decision = PolicyDecision::allow(
            "MCP server '{$serverName}' allowed under profile '{$profile}'",
            $profile, mcpTrustBoundary: $trustBoundary,
        );
        if ($audit) {
            $this->auditOperation($decision, "mcp_server:{$serverName}", ['mcp_server' => $serverName]);
        }

        return $decision;
    }

    /**
     * Evaluate a filesystem path in isolation (no operation context).
     */
    public function evaluatePath(string $path, string $mode = 'read', ?string $profile = null): PolicyDecision
    {
        $profile = $profile ?? $this->activeProfile();
        $profileDef = $this->profileDef($profile);
        if ($profileDef === null) {
            return PolicyDecision::deny("Unknown profile '{$profile}'", $profile);
        }

        $pathClass = $this->classifyPath($path, $mode);
        $allowedPaths = (array) ($profileDef['allowed_path_classes'] ?? []);

        if (in_array($pathClass, ['protected_read', 'protected_write'], true) && $profile !== 'default') {
            $decision = PolicyDecision::deny(
                "Path '{$path}' is protected under profile '{$profile}'",
                $profile, pathClass: $pathClass,
            );
            $this->auditOperation($decision, "path:{$mode}", ['path' => $path]);

            return $decision;
        }

        if (! in_array($pathClass, $allowedPaths, true)) {
            $decision = PolicyDecision::deny(
                "Path class '{$pathClass}' not allowed under profile '{$profile}'",
                $profile, pathClass: $pathClass,
            );
            $this->auditOperation($decision, "path:{$mode}", ['path' => $path]);

            return $decision;
        }

        $decision = PolicyDecision::allow(
            "Path '{$path}' allowed as '{$pathClass}' under '{$profile}'",
            $profile, pathClass: $pathClass,
        );
        $this->auditOperation($decision, "path:{$mode}", ['path' => $path]);

        return $decision;
    }

    /**
     * Evaluate an llm_instances row against the active profile's
     * allowed_provider_classes list.
     *
     * @param  object|array  $instance
     */
    public function evaluateProvider($instance, ?string $profile = null): PolicyDecision
    {
        $profile = $profile ?? $this->activeProfile();
        $profileDef = $this->profileDef($profile);
        if ($profileDef === null) {
            $decision = PolicyDecision::deny("Unknown profile '{$profile}'", $profile);
            $this->auditOperation($decision, 'provider:unknown', []);

            return $decision;
        }

        $class = $this->classifyProvider($instance);
        $allowed = (array) ($profileDef['allowed_provider_classes'] ?? []);
        $instanceId = (string) ($this->readField($instance, 'instance_id') ?? 'unknown');

        if (! in_array($class, $allowed, true)) {
            $decision = PolicyDecision::deny(
                "Provider class '{$class}' (instance '{$instanceId}') not allowed under profile '{$profile}'",
                $profile, providerClass: $class,
            );
            $this->auditOperation($decision, "provider:{$instanceId}", []);

            return $decision;
        }

        $decision = PolicyDecision::allow(
            "Provider class '{$class}' allowed under profile '{$profile}'",
            $profile, providerClass: $class,
        );
        $this->auditOperation($decision, "provider:{$instanceId}", []);

        return $decision;
    }

    /**
     * Evaluate a bare remote domain.
     */
    public function evaluateRemoteDomain(string $host, ?string $profile = null): PolicyDecision
    {
        $profile = $profile ?? $this->activeProfile();
        $profileDef = $this->profileDef($profile);
        if ($profileDef === null) {
            $decision = PolicyDecision::deny("Unknown profile '{$profile}'", $profile);
            $this->auditOperation($decision, "remote_domain:{$host}", ['remote_domain' => $host]);

            return $decision;
        }

        $class = $this->classifyRemoteDomain($host);
        $allowed = (array) ($profileDef['allowed_remote_domain_classes'] ?? []);

        if (! in_array($class, $allowed, true)) {
            $decision = PolicyDecision::deny(
                "Remote domain class '{$class}' (host '{$host}') not allowed under profile '{$profile}'",
                $profile, remoteDomainClass: $class,
            );
            $this->auditOperation($decision, "remote_domain:{$host}", ['remote_domain' => $host]);

            return $decision;
        }

        $decision = PolicyDecision::allow(
            "Remote domain '{$host}' allowed as '{$class}' under '{$profile}'",
            $profile, remoteDomainClass: $class,
        );
        $this->auditOperation($decision, "remote_domain:{$host}", ['remote_domain' => $host]);

        return $decision;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function profileDef(string $profile): ?array
    {
        $def = config("offline_policy.profiles.{$profile}");

        return is_array($def) ? $def : null;
    }

    private function readField($instance, string $field)
    {
        if (is_object($instance)) {
            return $instance->{$field} ?? null;
        }
        if (is_array($instance)) {
            return $instance[$field] ?? null;
        }

        return null;
    }

    private function decodeJsonField($instance, string $field): array
    {
        $raw = $this->readField($instance, $field);
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
