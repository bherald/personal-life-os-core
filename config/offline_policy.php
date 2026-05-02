<?php

/*
|--------------------------------------------------------------------------
| 3b Offline/Hybrid Policy Authority (P02a)
|--------------------------------------------------------------------------
|
| Single source of truth for the 3b mode ladder. Each profile row carries
| enforceable policy metadata — not just provider hints — so that
| OfflinePolicyService can evaluate tool, MCP, command, path, and provider
| actions consistently across AgentGuardrailService, AgentToolRegistryService,
| MCPRouter, AIService, and LLMPoolManagerService.
|
| Never edit profile metadata without also running:
|   php artisan test --filter=OfflinePolicyServiceTest
|
| See docs/AGENT-SAFETY-CARDS.md and docs/AIService-LLM-Gateway.md for the
| public safety and provider-routing explanation of these classes.
|
*/

return [

    // Redis/system_configs key read by OfflinePolicyService::activeProfile().
    // Matches the row already seeded by 3b2 / routing:profile.
    'active_profile_config_key' => 'routing.active_profile',
    'active_profile_default' => 'default',

    // routing.offline_mode (shipped via commit f035ab31) remains the fail-closed
    // kill switch: when enabled, external LLM providers are refused regardless
    // of the active profile. The profile ladder layers on top of that gate.
    'offline_mode_config_key' => 'routing.offline_mode',

    /*
    |--------------------------------------------------------------------------
    | Tool classes
    |--------------------------------------------------------------------------
    |
    | Every operation (e.g. file_read, artisan_exec, git_push) maps to exactly
    | one class. Profiles then allow or deny by class. Unknown operations
    | default to `unknown` and are denied under offline/hybrid profiles.
    |
    */

    'tool_classes' => [
        'read' => 'Inspect / search / diff / explain — no state change',
        'bounded-write' => 'Bounded edits inside repo + approved additional dirs',
        'command-safe' => 'Local dev commands (artisan, lint, test, build)',
        'command-dangerous' => 'rm / chmod / dd / kill / sudo / pipe-to-shell',
        'deploy' => 'Push / deploy / prod SSH / deploy script',
        'external-network' => 'Reaches any endpoint outside the PLOS LAN',
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP trust boundaries
    |--------------------------------------------------------------------------
    |
    | Each MCP server in config/mcp.php carries a `trust_boundary` field; the
    | active profile's `allowed_mcp_trust` list filters which servers may be
    | invoked. See docs/plos-runtime-architecture.md for runtime boundary
    | context and config/mcp.php for per-server classifications.
    |
    */

    'mcp_trust_boundaries' => [
        'plos_local' => 'Runs inside PLOS, touches PLOS-owned state only',
        'local_host' => 'Same host, separate trust domain (Thunderbird, local FS)',
        'local_lan' => 'LAN-only network reach (Nextcloud, SearXNG on LAN)',
        'internet' => 'Reaches public internet for queries or scraping',
    ],

    /*
    |--------------------------------------------------------------------------
    | Path classes
    |--------------------------------------------------------------------------
    |
    | Any filesystem operation resolves to a path class based on where the
    | target sits relative to the repo root, additional approved directories,
    | and the protected-path registry below.
    |
    */

    'path_classes' => [
        'repo_read',
        'repo_write',
        'additional_dir_read',
        'additional_dir_write',
        'protected_read',
        'protected_write',
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider classes
    |--------------------------------------------------------------------------
    |
    | OfflinePolicyService::evaluateProvider() maps llm_instances rows to one
    | of these three classes and refuses those not in the active profile's
    | `allowed_provider_classes`. `cloud_sensitive_safe` = sensitive_safe=true
    | providers (Anthropic Claude CLI, Groq, SambaNova, Cerebras). Everything
    | else that is not a local Ollama host is `cloud_external`.
    |
    */

    'provider_classes' => [
        'local_llm' => 'Ollama hosts on the PLOS LAN',
        'cloud_sensitive_safe' => 'sensitive_safe=true cloud providers',
        'cloud_external' => 'sensitive_safe=false cloud providers',
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote domain classes
    |--------------------------------------------------------------------------
    |
    | External-network operations carry a `remote_domain` context key that is
    | matched against these classes. Hybrid profiles allow only the allowlisted
    | classes; offline profiles refuse anything outside plos_lan.
    |
    */

    'remote_domain_classes' => [
        'plos_lan' => 'LAN addresses (192.168.*, 127.0.0.1, *.local)',
        'approved_cloud' => 'Allowlisted providers (api.anthropic.com, etc.)',
        'wild_internet' => 'Anything else — always refused in offline/hybrid',
    ],

    /*
    |--------------------------------------------------------------------------
    | Protected paths
    |--------------------------------------------------------------------------
    |
    | PCRE patterns matched against the target path of any filesystem operation.
    | A match bumps the path class to `protected_read` / `protected_write` and
    | — in every profile except `default` — results in hard refusal regardless
    | of the tool class that would otherwise be allowed.
    |
    */

    'protected_paths' => [
        '#^\.env$#',
        '#^\.env\..+#',
        '#(^|/)\.env$#',
        '#(^|/)\.env\..+#',
        '#(^|/)\.ssh/#',
        '#credentials#i',
        '#secret#i',
        '#(^|/)(prod|deploy)[._-]?[^/]*\.sh$#',
        '#(^|/)\.htaccess$#',
        '#(^|/)\.git/#',
    ],

    /*
    |--------------------------------------------------------------------------
    | Additional directories
    |--------------------------------------------------------------------------
    |
    | Writable scope extends beyond the repo root into these paths. Subpaths
    | still respect the protected_paths list above.
    |
    */

    'additional_dirs' => [
        // storage_path() — explicit later because config loads before storage_path helper
    ],

    /*
    |--------------------------------------------------------------------------
    | Operation → tool-class map
    |--------------------------------------------------------------------------
    |
    | The AgentGuardrailService::validate() first argument (operation) maps
    | here. Any operation not listed is classified as `unknown` and refused
    | under offline/hybrid profiles. Keep this map append-only; new operation
    | names land here with their class before shipping.
    |
    */

    'operation_class_map' => [
        // read class
        'file_read' => 'read',
        'file_list' => 'read',
        'file_search' => 'read',
        'file_stat' => 'read',
        'code_review' => 'read',
        'rag_search' => 'read',
        'rag_similar' => 'read',
        'git_status' => 'read',
        'git_diff' => 'read',
        'git_log' => 'read',
        'agent_plan' => 'read',
        'agent_explain' => 'read',

        // bounded-write class
        'file_write' => 'bounded-write',
        'file_edit' => 'bounded-write',
        'file_overwrite' => 'bounded-write',
        'file_create' => 'bounded-write',
        'file_move' => 'bounded-write',
        'rag_index' => 'bounded-write',

        // command-safe class
        'artisan_exec' => 'command-safe',
        'npm_run' => 'command-safe',
        'composer_exec' => 'command-safe',
        'test_run' => 'command-safe',
        'lint_run' => 'command-safe',
        'build_run' => 'command-safe',

        // command-dangerous class
        'shell_exec' => 'command-dangerous',
        'system_command' => 'command-dangerous',
        'process_kill' => 'command-dangerous',
        'env_modify' => 'command-dangerous',
        'credential_access' => 'command-dangerous',
        'file_delete' => 'command-dangerous',
        'database_drop' => 'command-dangerous',
        'database_truncate' => 'command-dangerous',

        // deploy class
        'git_push' => 'deploy',
        'prod_deploy' => 'deploy',
        'prod_ssh_exec' => 'deploy',
        'user_delete' => 'deploy',
        'workflow_delete' => 'deploy',

        // external-network class
        'web_fetch' => 'external-network',
        'web_search' => 'external-network',
        'web_scrape' => 'external-network',
        'email_send' => 'external-network',
        'email_send_bulk' => 'external-network',
        'cloud_llm_call' => 'external-network',
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile definitions
    |--------------------------------------------------------------------------
    |
    | The six-rung mode ladder. `confirmation` lists tool classes that require
    | human confirmation before execution (in addition to operations that carry
    | their own confirmation rule). `audit` = 'always' means every decision
    | (allow / deny / confirm) is persisted to offline_audit_events.
    |
    */

    'profiles' => [

        'default' => [
            'allowed_tool_classes' => ['read', 'bounded-write', 'command-safe', 'command-dangerous', 'deploy', 'external-network'],
            'allowed_mcp_trust' => ['plos_local', 'local_host', 'local_lan', 'internet'],
            'allowed_path_classes' => ['repo_read', 'repo_write', 'additional_dir_read', 'additional_dir_write'],
            'allowed_provider_classes' => ['local_llm', 'cloud_sensitive_safe', 'cloud_external'],
            'allowed_remote_domain_classes' => ['plos_lan', 'approved_cloud', 'wild_internet'],
            'confirmation' => ['command-dangerous', 'deploy'],
            'audit' => 'always',
            'description' => 'Normal routed operation — all classes allowed, existing guardrails apply.',
        ],

        'offline_review' => [
            'allowed_tool_classes' => ['read'],
            'allowed_mcp_trust' => ['plos_local', 'local_lan'],
            'allowed_path_classes' => ['repo_read', 'additional_dir_read'],
            'allowed_provider_classes' => ['local_llm'],
            'allowed_remote_domain_classes' => ['plos_lan'],
            'confirmation' => [],
            'audit' => 'always',
            'description' => 'Local inspect/search/diff/explain/validate only. No writes, no cloud.',
        ],

        'offline_dev_assist' => [
            'allowed_tool_classes' => ['read', 'bounded-write', 'command-safe'],
            'allowed_mcp_trust' => ['plos_local', 'local_host', 'local_lan'],
            'allowed_path_classes' => ['repo_read', 'repo_write', 'additional_dir_read', 'additional_dir_write'],
            'allowed_provider_classes' => ['local_llm'],
            'allowed_remote_domain_classes' => ['plos_lan'],
            'confirmation' => ['bounded-write', 'command-safe'],
            'audit' => 'always',
            'description' => 'offline_review + bounded local edits/lint/test/build + local MCP. Human review/commit boundary intact.',
        ],

        'hybrid_review' => [
            'allowed_tool_classes' => ['read', 'external-network'],
            'allowed_mcp_trust' => ['plos_local', 'local_lan', 'internet'],
            'allowed_path_classes' => ['repo_read', 'additional_dir_read'],
            'allowed_provider_classes' => ['local_llm', 'cloud_sensitive_safe'],
            'allowed_remote_domain_classes' => ['plos_lan', 'approved_cloud'],
            'confirmation' => [],
            'audit' => 'always',
            'description' => 'offline_review + allowlisted cloud reasoning/review. Local write scope unchanged.',
        ],

        'hybrid_dev_assist' => [
            'allowed_tool_classes' => ['read', 'bounded-write', 'command-safe', 'external-network'],
            'allowed_mcp_trust' => ['plos_local', 'local_host', 'local_lan', 'internet'],
            'allowed_path_classes' => ['repo_read', 'repo_write', 'additional_dir_read', 'additional_dir_write'],
            'allowed_provider_classes' => ['local_llm', 'cloud_sensitive_safe'],
            'allowed_remote_domain_classes' => ['plos_lan', 'approved_cloud'],
            'confirmation' => ['bounded-write', 'command-safe'],
            'audit' => 'always',
            'description' => 'offline_dev_assist + allowlisted cloud reasoning/escalation. No auto deploy/push, no unrestricted remote MCP.',
        ],

        'cloud_escalation_only' => [
            'allowed_tool_classes' => ['read', 'external-network'],
            'allowed_mcp_trust' => ['plos_local', 'local_lan'],
            'allowed_path_classes' => ['repo_read', 'additional_dir_read'],
            'allowed_provider_classes' => ['local_llm', 'cloud_sensitive_safe'],
            'allowed_remote_domain_classes' => ['plos_lan', 'approved_cloud'],
            'confirmation' => [],
            'audit' => 'always',
            'description' => 'Cloud reasoning allowed on approved providers; local tool/file/MCP rights stay read-mostly unless separately granted.',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Approved remote domain allowlist
    |--------------------------------------------------------------------------
    |
    | Domains/hosts that resolve to `approved_cloud` when matched. Anything not
    | matched here and not in the `plos_lan` regex falls through to
    | `wild_internet` and is refused under every profile except `default`.
    |
    */

    'approved_cloud_hosts' => [
        'api.anthropic.com',
        'openrouter.ai',
        'api.groq.com',
        'api.sambanova.ai',
        'api.cerebras.ai',
        'api.deepinfra.com',
        'generativelanguage.googleapis.com',
        'api.mistral.ai',
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP tool → tool-class map (R2 — 2026-04-19)
    |--------------------------------------------------------------------------
    |
    | MCP server admission (via `mcp_trust_boundaries`) is necessary but not
    | sufficient — once a server is admitted, individual tools still differ
    | in their effect. `offline_review` must be read-only at tool-execution
    | time: even an allowed server (e.g. nextcloud-files) must refuse
    | mutating tools (upload-file, delete-file) under offline_review.
    |
    | Keys use the form `{server}.{tool}`. Lookups fall back to
    | `{server}.*` for a server-wide default. Anything unmapped falls
    | through to the default class declared below.
    |
    | Class semantics match the operation tool_classes above:
    |   read              — inspection / no state change
    |   bounded-write     — state change inside the tool's configured scope
    |   command-safe      — local dev/ops action
    |   command-dangerous — destructive action; always requires confirmation
    |   external-network  — reaches beyond the PLOS LAN
    |
    */

    // Fix D (2026-04-19): fail-closed for unmapped MCP tools. A newly
    // added MCP tool that nobody classified lands on `unclassified` which
    // is NOT present in any profile's allowed_tool_classes — so every
    // non-default profile refuses it. Under `default` the existing
    // unknown/unclassified pass-through preserves current behavior.
    // Catalog completeness is pinned by OfflinePolicyMcpCatalogCompletenessTest.
    'mcp_tool_default_class' => 'unclassified',

    /*
    |--------------------------------------------------------------------------
    | Local-FS path MCP tools (R2 + Defect B fix — 2026-04-19)
    |--------------------------------------------------------------------------
    |
    | Every MCP tool that reaches the local filesystem MUST appear here so
    | the OfflinePolicyService local-FS path classifier runs. The key is
    | `{server}.{tool}` (wildcard `{server}.*` permitted) and the value is a
    | list of parameter names whose value is a local-FS path.
    |
    | Defect B (2026-04-19): the earlier `local_fs_path_mcp_servers` list
    | only covered the `filesystem` MCP server and only the param name
    | `path`. But several other admitted MCP tools also touch the local
    | filesystem under different param names:
    |   - code-review.code_review_file → `file_path`
    |   - genealogy.gedcom_parse       → `file_path`
    | Both were readable through the formerly-narrow gate, which let
    | offline_review read `.env`/`.ssh/*` via those tools.
    |
    */

    'local_fs_path_mcp_tools' => [
        'filesystem.*' => ['path'],
        'repo-dev.find_repo_files' => ['path'],
        'repo-dev.search_repo' => ['path'],
        'repo-dev.list_repo_directory' => ['path'],
        'repo-dev.read_repo_file' => ['path'],
        'repo-dev.write_repo_file' => ['path'],
        'code-review.code_review_file' => ['file_path'],
        'genealogy.gedcom_parse' => ['file_path'],
        'serena.get_symbols_overview' => ['relative_path'],
        'serena.find_symbol' => ['relative_path'],
        'serena.find_referencing_symbols' => ['relative_path'],
        'serena.search_for_pattern' => ['relative_path'],
        'serena.find_file' => ['relative_path'],
        'serena.list_dir' => ['relative_path'],
        'serena.read_file' => ['relative_path'],
        'serena.create_text_file' => ['relative_path'],
        'serena.replace_content' => ['relative_path'],
        'serena.replace_lines' => ['relative_path'],
        'serena.insert_at_line' => ['relative_path'],
        'serena.delete_lines' => ['relative_path'],
        'serena.insert_after_symbol' => ['relative_path'],
        'serena.insert_before_symbol' => ['relative_path'],
        'serena.replace_symbol_body' => ['relative_path'],
        'serena.rename_symbol' => ['relative_path'],
        'serena.safe_delete_symbol' => ['relative_path'],
        'prompt-compressor.prompt_token_count' => ['path'],
        'prompt-compressor.compress_file' => ['path'],
    ],

    // Deprecated (kept for back-compat with any caller still reading it).
    // The authoritative list is now `local_fs_path_mcp_tools` above.
    'local_fs_path_mcp_servers' => ['filesystem'],

    'mcp_tool_class_map' => [
        // --- plos (internal workflow) ---
        'plos.workflow_list' => 'read',
        'plos.workflow_get' => 'read',
        'plos.execution_list' => 'read',
        'plos.execution_get' => 'read',
        'plos.schedule_list' => 'read',
        'plos.system_diagnostics' => 'read',
        'plos.workflow_run' => 'bounded-write',
        'plos.artisan_execute' => 'command-safe',
        'plos.node_create' => 'bounded-write',

        // --- code-review (internal, read-only analysis) ---
        'code-review.*' => 'read',

        // --- repo-dev (repo-local dev surface) ---
        'repo-dev.find_repo_files' => 'read',
        'repo-dev.search_repo' => 'read',
        'repo-dev.list_repo_directory' => 'read',
        'repo-dev.read_repo_file' => 'read',
        'repo-dev.list_routes' => 'read',
        'repo-dev.write_repo_file' => 'bounded-write',
        'repo-dev.apply_repo_patch' => 'bounded-write',
        'repo-dev.run_verification' => 'command-safe',

        // --- serena (external, repo-local semantic coding MCP) ---
        'serena.activate_project' => 'read',
        'serena.get_current_config' => 'read',
        'serena.check_onboarding_performed' => 'read',
        'serena.initial_instructions' => 'read',
        'serena.onboarding' => 'read',
        'serena.get_symbols_overview' => 'read',
        'serena.find_symbol' => 'read',
        'serena.find_referencing_symbols' => 'read',
        'serena.search_for_pattern' => 'read',
        'serena.find_file' => 'read',
        'serena.list_dir' => 'read',
        'serena.read_file' => 'read',
        'serena.list_memories' => 'read',
        'serena.read_memory' => 'read',
        'serena.insert_after_symbol' => 'bounded-write',
        'serena.insert_before_symbol' => 'bounded-write',
        'serena.replace_symbol_body' => 'bounded-write',
        'serena.rename_symbol' => 'bounded-write',
        'serena.safe_delete_symbol' => 'bounded-write',
        'serena.create_text_file' => 'bounded-write',
        'serena.replace_content' => 'bounded-write',
        'serena.replace_lines' => 'bounded-write',
        'serena.insert_at_line' => 'bounded-write',
        'serena.delete_lines' => 'bounded-write',
        'serena.write_memory' => 'bounded-write',
        'serena.edit_memory' => 'bounded-write',
        'serena.delete_memory' => 'command-dangerous',
        'serena.rename_memory' => 'bounded-write',
        'serena.execute_shell_command' => 'command-dangerous',
        'serena.restart_language_server' => 'command-safe',

        // --- prompt-compressor (repo-local context compression) ---
        'prompt-compressor.prompt_token_count' => 'read',
        'prompt-compressor.compress_prompt' => 'read',
        'prompt-compressor.compress_file' => 'read',
        'prompt-compressor.compress_diff' => 'read',
        'prompt-compressor.context_retrieve' => 'read',
        'prompt-compressor.context_list' => 'read',
        'prompt-compressor.context_store' => 'bounded-write',

        // --- ops (internal) ---
        'ops.ops_health_check' => 'read',
        'ops.ops_log_analyze' => 'read',
        'ops.ops_status' => 'read',
        'ops.ops_report' => 'read',
        'ops.ops_cleanup' => 'bounded-write',
        'ops.ops_alert' => 'external-network',

        // --- nextcloud-files (WebDAV) ---
        'nextcloud-files.test-connection' => 'read',
        'nextcloud-files.list-files' => 'read',
        'nextcloud-files.download-file' => 'read',
        'nextcloud-files.list-shares' => 'read',
        'nextcloud-files.search-files' => 'read',
        'nextcloud-files.get-file-versions' => 'read',
        'nextcloud-files.upload-file' => 'bounded-write',
        'nextcloud-files.create-directory' => 'bounded-write',
        'nextcloud-files.move-file' => 'bounded-write',
        'nextcloud-files.copy-file' => 'bounded-write',
        'nextcloud-files.create-share' => 'bounded-write',
        'nextcloud-files.restore-file-version' => 'bounded-write',
        'nextcloud-files.delete-file' => 'command-dangerous',
        'nextcloud-files.delete-share' => 'bounded-write',

        // --- rag (internal) ---
        'rag.rag_search' => 'read',
        'rag.rag_similar' => 'read',
        'rag.rag_index' => 'bounded-write',

        // --- research / web-research / puppeteer / searxng (internet) ---
        'research.*' => 'external-network',
        'web-research.*' => 'external-network',
        'puppeteer.*' => 'external-network',
        'searxng.*' => 'external-network',

        // --- nextcloud-calendar / nextcloud-contacts (LAN, mixed) ---
        'nextcloud-calendar.get_calendar_events' => 'read',
        'nextcloud-calendar.list_calendars' => 'read',
        'nextcloud-contacts.get_address_books' => 'read',
        'nextcloud-contacts.get_contacts' => 'read',
        'nextcloud-contacts.search_contacts' => 'read',
        'nextcloud-contacts.get_contact_stats' => 'read',

        // --- joplin-files (read-only) ---
        'joplin-files.*' => 'read',

        // --- thunderbird (email) ---
        'thunderbird.listFolders' => 'read',
        'thunderbird.listMailboxes' => 'read',
        'thunderbird.searchMessages' => 'read',
        'thunderbird.getRecentMessages' => 'read',
        'thunderbird.getStats' => 'read',
        'thunderbird.sendEmail' => 'external-network',

        // --- memory (knowledge graph) ---
        'memory.read_graph' => 'read',
        'memory.search_nodes' => 'read',
        'memory.create_entities' => 'bounded-write',
        'memory.create_relations' => 'bounded-write',
        'memory.add_observations' => 'bounded-write',
        'memory.delete_entities' => 'command-dangerous',

        // --- sequential-thinking / time / everything (pure compute) ---
        'sequential-thinking.*' => 'read',
        'time.*' => 'read',
        'everything.*' => 'read',

        // --- genealogy ---
        'genealogy.gedcom_parse' => 'read',
        'genealogy.tree_search' => 'read',
        'genealogy.tree_stats' => 'read',
        'genealogy.source_extract' => 'read',
        'genealogy.person_research' => 'read',
        'genealogy.gedcom_export' => 'bounded-write',

        // --- filesystem (local files, path-sensitive) ---
        'filesystem.read_file' => 'read',
        'filesystem.list_directory' => 'read',
        'filesystem.search_files' => 'read',
        'filesystem.get_file_info' => 'read',
        'filesystem.write_file' => 'bounded-write',
        'filesystem.create_directory' => 'bounded-write',
        'filesystem.move_file' => 'bounded-write',
        'filesystem.delete_file' => 'command-dangerous',
    ],

    // Loopback/LAN patterns for the `plos_lan` domain class. PCRE regex.
    'plos_lan_host_patterns' => [
        '#^127\.0\.0\.1$#',
        '#^localhost$#',
        '#^192\.168\.\d+\.\d+$#',
        '#^10\.\d+\.\d+\.\d+$#',
        '#^172\.(1[6-9]|2\d|3[01])\.\d+\.\d+$#',
        '#\.local$#',
        '#\.lan$#',
    ],

];
