<?php

use App\Services\CodeReviewService;
use App\Services\Genealogy\GenealogyMCPService;
use App\Services\JoplinFilesService;
use App\Services\NextcloudContactsService;
use App\Services\NextcloudService;
use App\Services\OpsMCPService;
use App\Services\RAGService;
use App\Services\RepoDevMCPService;
use App\Services\ResearchMCPService;
use App\Services\SearXNGMCPService;
use App\Services\TimeService;
use App\Services\UnifiedCli\CliCodexSkillReader;
use App\Services\UnifiedCli\CliLemmaPodMcpService;
use App\Services\WebResearchService;
use App\Services\WorkflowService;

$filesystemMcpAllowedDirs = array_values(array_filter(
    array_map('trim', explode(',', (string) env('FILESYSTEM_MCP_ALLOWED_DIRS', base_path('storage')))),
    static fn (string $path): bool => $path !== ''
));

if ($filesystemMcpAllowedDirs === []) {
    $filesystemMcpAllowedDirs = [base_path('storage')];
}

return [
    /*
    |--------------------------------------------------------------------------
    | MCP Servers Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for all MCP (Model Context Protocol)
    | servers that can be called by Ollama via the MCPRouter.
    |
    | Each server entry defines:
    | - command: Executable command (node, python, etc.)
    | - args: Command arguments (path to MCP server entry point)
    | - env: Environment variables needed by the server
    | - tools: Number of tools provided (for documentation)
    | - enabled: Whether the server is active
    |
    */

    // Opt-in flag for live tool-list discovery. When false (default), the
    // router uses hand-maintained static definitions from getStaticToolDefinitions()
    // — fast, reliable, no subprocess at boot. When true, MCPRouter spawns
    // the server and walks initialize → notifications/initialized → tools/list
    // to pull the real advertised tool schemas, falling back to static on
    // any failure. Intended for deployments that want new MCP tools to
    // appear automatically without editing the static map.
    'dynamic_discovery_enabled' => env('MCP_DYNAMIC_DISCOVERY_ENABLED', false),

    'servers' => [
        'codex-skills' => [
            'enabled' => true,
            'type' => 'internal',
            'service' => CliCodexSkillReader::class,
            'env' => [],
            'tools' => 2,
            'description' => 'Read installed Codex skill instructions and support text on this host',
            'trust_boundary' => 'plos_local',
            'transport' => 'internal_service',
            'network_required' => 'none',
            'write_scope' => 'none',
            'secret_surface_risk' => 'private_files',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist'],
        ],
        'lemma-pod' => [
            'enabled' => env('LEMMA_POD_MCP_ENABLED', false),
            'type' => 'internal',
            'service' => CliLemmaPodMcpService::class,
            'env' => [
                'LEMMA_POD_PILOT_PATH' => env('LEMMA_POD_PILOT_PATH'),
                'LEMMA_POD_CREDENTIAL_PATH' => env('LEMMA_POD_CREDENTIAL_PATH'),
            ],
            'timeout_ms' => env('LEMMA_POD_MCP_TIMEOUT_MS', 30000),
            'coordination_bindings_path' => env('LEMMA_COORDINATION_BINDINGS_PATH', storage_path('app/private/plos-cli/bindings.json')),
            'tools' => 16,
            'description' => 'Existing local Lemma pod MCP with agent-account permissions and exact write approvals',
            'trust_boundary' => 'plos_local',
            'transport' => 'internal_service',
            'network_required' => 'lan_only',
            'write_scope' => 'lemma_pod',
            'secret_surface_risk' => 'private_credentials',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist'],
        ],
        'lemma-local' => [
            'enabled' => env('LEMMA_LOCAL_MCP_ENABLED', false),
            'command' => 'node',
            'args' => [base_path('scripts/lemma-local-mcp.mjs')],
            // Values stay on the execution host; registry snapshots carry only
            // local binding references and the portable PLOS entrypoint.
            'env' => [
                'LEMMA_LOCAL_CONTROL_ENTRY' => env('LEMMA_LOCAL_CONTROL_ENTRY'),
                'LEMMA_LOCAL_CONTROL_SHA256' => env('LEMMA_LOCAL_CONTROL_SHA256'),
                'HOME' => env('HOME'),
            ],
            'tools' => 3,
            'description' => 'Opt-in, fixed-action control of the local rootless Lemma stack',
            'trust_boundary' => 'local_host',
            'transport' => 'external_process',
            // Docker uses a rootless Unix socket; the status adapter tolerates
            // unavailable loopback HTTP by reporting container health.
            'network_required' => 'none',
            'write_scope' => 'local_runtime',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_dev_assist'],
        ],

        'hister' => [
            'enabled' => env('HISTER_ENABLED', false),
            'command' => 'node',
            'args' => [base_path('scripts/hister-mcp.mjs')],
            'env' => [],
            'tools' => 3,
            'description' => 'Optional authenticated local reference recall; explicitly imported documents only',
            'trust_boundary' => 'plos_local',
            'transport' => 'external_process',
            'network_required' => 'none',
            'write_scope' => 'none',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        'gortex' => [
            'enabled' => env('GORTEX_ENABLED', false),
            'command' => 'node',
            'args' => [base_path('scripts/gortex-mcp.mjs')],
            'env' => [],
            'tools' => 6,
            'description' => 'Optional local source and schema navigation with exact source reads',
            // The pinned PLOS bridge exposes indexed PLOS symbols only.
            'trust_boundary' => 'plos_local',
            'transport' => 'external_process',
            'network_required' => 'none',
            'write_scope' => 'none',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | PLOS Workflow MCP Server
        |--------------------------------------------------------------------------
        |
        | Internal MCP server for workflow operations. Provides 9 tools:
        | - workflow_list: List all workflows
        | - workflow_get: Get workflow details
        | - workflow_run: Execute workflow
        | - execution_list: List execution history
        | - execution_get: Get execution details
        | - artisan_execute: Run whitelisted artisan commands
        | - node_create: Generate new workflow nodes from template
        | - schedule_list: List scheduled workflows with cron info
        | - system_diagnostics: System health check (database, queue, AI)
        |
        | All tools fully implemented and tested.
        |
        */
        'plos' => [
            'enabled' => true,
            'type' => 'internal',  // Use internal Laravel service for performance
            'service' => WorkflowService::class,
            'tools' => 9,
            'description' => 'Workflow automation and management tools',
            // 3b P02a trust metadata
            'trust_boundary' => 'plos_local',
            'transport' => 'internal_service',
            'network_required' => 'lan_only',
            'write_scope' => 'plos_data',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Code Review MCP Server (Internal)
        |--------------------------------------------------------------------------
        |
        | Internal MCP server for AI-assisted code review. Provides 4 tools:
        | - code_review: Review code snippet for issues
        | - code_review_file: Review entire file
        | - code_review_diff: Review git diff
        | - code_suggest_improvements: Get improvement suggestions
        |
        | Supports 15+ languages including PHP, JavaScript, Python, TypeScript.
        | Check types: security, performance, bugs, best_practices, style
        |
        */
        'code-review' => [
            'enabled' => true,
            'type' => 'internal',
            'service' => CodeReviewService::class,
            'tools' => 4,
            'description' => 'AI-assisted code review and analysis',
            'trust_boundary' => 'plos_local',
            'transport' => 'internal_service',
            'network_required' => 'none',
            'write_scope' => 'none',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Repo Dev MCP Server (Internal)
        |--------------------------------------------------------------------------
        |
        | Internal repo-local development helpers for offline:dev-assist.
        | Keeps file and route inspection/editing inside the Laravel app so
        | the existing offline profile and path policy can govern it.
        |
        | Tools provided (11):
        | - find_repo_files: Find files by path/name substring
        | - search_repo: Search repository contents
        | - context_map: Rank task-relevant files and PHP declarations
        | - list_repo_directory: List files in a repo directory
        | - read_repo_file: Read source files with line numbers
        | - write_repo_file: Write a file with recovery and diagnostics
        | - apply_repo_patch: Apply a unified diff with recovery and diagnostics
        | - restore_repo_edit: Restore one unchanged-since-edit recovery packet
        | - summarize_repo_edits: Summarize ordered recovery-backed edits without content
        | - run_verification: Run allowlisted test/static verification commands
        | - list_routes: Show frontend, API, or all Laravel routes
        |
        */
        'repo-dev' => [
            'enabled' => true,
            'type' => 'internal',
            'service' => RepoDevMCPService::class,
            'tools' => 11,
            'description' => 'Repository-local development tools for offline dev assist',
            'trust_boundary' => 'plos_local',
            'transport' => 'internal_service',
            'network_required' => 'none',
            'write_scope' => 'repo_worktree',
            'secret_surface_risk' => 'medium',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Prompt Compressor MCP Server (External)
        |--------------------------------------------------------------------------
        |
        | Repo-local context compression for agents and MCP clients. Provides
        | deterministic extractive compression and optional local Ollama
        | compression for large prompts, diffs, files, and handoff context.
        |
        | Operator-disabled because compressed prompts lost material detail.
        | The source workspace is retained for audit history, but PLOS cannot
        | register or enable this server through an environment override.
        |
        */
        'prompt-compressor' => [
            'enabled' => false,
            'type' => 'external',
            'command' => env('PROMPT_COMPRESSOR_COMMAND', 'node'),
            'args' => [
                env('PROMPT_COMPRESSOR_ENTRY', base_path('mcp-servers/prompt-compressor/dist/index.js')),
            ],
            'env' => [
                'PROMPT_COMPRESSOR_ALLOWED_ROOTS' => env('PROMPT_COMPRESSOR_ALLOWED_ROOTS', base_path()),
                'PROMPT_COMPRESSOR_STORE_DIR' => env('PROMPT_COMPRESSOR_STORE_DIR', storage_path('app/mcp/prompt-compressor')),
                'PROMPT_COMPRESSOR_AUTO_OLLAMA' => env('PROMPT_COMPRESSOR_AUTO_OLLAMA', 'false'),
                'PROMPT_COMPRESSOR_OLLAMA_HOST' => env('PROMPT_COMPRESSOR_OLLAMA_HOST', env('OLLAMA_API_URL', env('OLLAMA_HOST', 'http://127.0.0.1:11434'))),
                'PROMPT_COMPRESSOR_OLLAMA_SECONDARY_HOSTS' => env('PROMPT_COMPRESSOR_OLLAMA_SECONDARY_HOSTS', env('OLLAMA_SECONDARY_URLS', '')),
                'PROMPT_COMPRESSOR_ALLOW_PROTECTED_READS' => env('PROMPT_COMPRESSOR_ALLOW_PROTECTED_READS', 'false'),
            ],
            'tools' => 0,
            'description' => 'Retired and source-disabled by the lossless AI prompt policy',
            'trust_boundary' => 'plos_local',
            'transport' => 'external_process',
            'network_required' => 'lan_only',
            'write_scope' => 'local_context_cache',
            'secret_surface_risk' => 'medium',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Codebase Memory MCP Server (External, Optional)
        |--------------------------------------------------------------------------
        |
        | Developer-only code graph sidecar for architecture queries, call-path
        | tracing, indexed code search, impact analysis, and ADR notes. Keep this
        | disabled by default: the upstream installer can modify agent configs
        | and the graph cache is an evaluation artifact, not a committed PLOS
        | source of truth.
        |
        | Install/evaluate manually with binary-only or skipped agent config
        | setup. Enable in trusted dev/operator environments with:
        |   CODEBASE_MEMORY_MCP_ENABLED=true
        |
        | Tools tracked by static policy (14):
        | - index_repository, list_projects, delete_project, index_status
        | - search_graph, trace_path, detect_changes, query_graph
        | - get_graph_schema, get_code_snippet, get_architecture, search_code
        | - manage_adr, ingest_traces
        |
        */
        'codebase-memory' => [
            'enabled' => env('CODEBASE_MEMORY_MCP_ENABLED', false),
            'type' => 'external',
            'command' => env('CODEBASE_MEMORY_MCP_COMMAND', 'codebase-memory-mcp'),
            'args' => [],
            'env' => [
                'HOME' => env('HOME')
                    ?: (function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                        ? (posix_getpwuid(posix_geteuid())['dir'] ?? base_path())
                        : base_path()),
                'CBM_CACHE_DIR' => env('CODEBASE_MEMORY_CACHE_DIR', storage_path('app/mcp/codebase-memory')),
                'CBM_NO_UPDATE_CHECK' => env('CODEBASE_MEMORY_NO_UPDATE_CHECK', 'true'),
            ],
            'tools' => 14,
            'description' => 'Developer-only codebase graph MCP sidecar for architecture and call-path queries',
            'trust_boundary' => 'plos_local',
            'transport' => 'external_process',
            'network_required' => 'none',
            'write_scope' => 'local_code_graph_cache',
            'secret_surface_risk' => 'medium',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Ops MCP Server (Infrastructure Maintenance)
        |--------------------------------------------------------------------------
        |
        | Internal MCP server for AI-driven infrastructure maintenance.
        | Allows AI to monitor, maintain, and report on system health
        | without modifying source code.
        |
        | Tools provided (6):
        | - ops_health_check: Full system health assessment (Redis, Horizon, DB, disk)
        | - ops_log_analyze: Scan logs for errors/patterns
        | - ops_cleanup: Execute cleanup tasks (logs, failed jobs, old executions)
        | - ops_report: Generate formatted Pushover report
        | - ops_alert: Send Pushover notification
        | - ops_status: Quick status summary
        |
        | Thresholds:
        | - Redis memory warning: >80%
        | - Failed jobs warning: >5 in 24hr
        | - Stuck workflow: >6 hours
        | - Disk space critical: >90%
        | - Log file warning: >50MB
        | - Min Horizon workers: 2
        |
        | Scheduled: Daily at 5 AM via OpsMaintenanceJob
        |
        */
        'ops' => [
            'enabled' => true,
            'type' => 'internal',
            'service' => OpsMCPService::class,
            'tools' => 6,
            'description' => 'Infrastructure monitoring and maintenance',
            'trust_boundary' => 'plos_local',
            'transport' => 'internal_service',
            'network_required' => 'lan_only',
            'write_scope' => 'plos_data',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Nextcloud Files MCP Server (abdullahMASHUK/nextcloud-mcp-server) ✓
        |--------------------------------------------------------------------------
        |
        | Working external MCP server for Nextcloud file operations via WebDAV.
        | Provides 14 comprehensive tools for file management and sharing.
        |
        | Tools provided (14):
        | - test-connection: Verify Nextcloud server connectivity
        | - list-files: List files/directories
        | - create-directory: Create new directories
        | - delete-file: Delete files or directories
        | - upload-file: Upload files (base64 encoded)
        | - download-file: Download files
        | - create-share: Create share links (public, user, group, email)
        | - list-shares: List existing shares
        | - delete-share: Delete shares
        | - move-file: Move or rename files
        | - copy-file: Copy files or directories
        | - search-files: Search by name or content
        | - get-file-versions: View file version history
        | - restore-file-version: Restore previous versions
        |
        | Complements internal nextcloud-calendar for full Nextcloud integration.
        |
        | Installation: npm install --save-exact nextcloud-mcp-server@1.1.0
        | Version: 1.1.0 (tested and working)
        |
        */
        'nextcloud-files' => [
            'enabled' => true,
            'type' => 'external',
            'command' => base_path('node_modules/.bin/nextcloud-mcp-server'),
            'args' => [],
            'runtime_pin' => ['type' => 'npm_lock', 'package' => 'nextcloud-mcp-server', 'version' => '1.1.0'],
            'env' => [
                'NEXTCLOUD_URL' => env('NEXTCLOUD_URL'),
                'NEXTCLOUD_USERNAME' => env('NEXTCLOUD_USERNAME'),
                'NEXTCLOUD_PASSWORD' => env('NEXTCLOUD_PASSWORD'),
            ],
            'tools' => 14,
            'description' => 'Nextcloud file operations (WebDAV, sharing, versioning)',
            'trust_boundary' => 'local_lan',
            'transport' => 'external_process',
            'network_required' => 'lan_only',
            'write_scope' => 'nextcloud_data',
            'secret_surface_risk' => 'medium', // holds NEXTCLOUD_PASSWORD env
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | RAG MCP Server (Internal)
        |--------------------------------------------------------------------------
        |
        | Internal MCP server for hierarchical RAG retrieval. Provides 4 tools:
        | - rag_search: Semantic search over indexed documents
        | - rag_index: Index new document with embeddings
        | - rag_similar: Find similar documents
        | - rag_read: Read exact, resumable document windows by stable ID
        |
        | This exposes the existing RAGService as MCP tools for both
        | Ollama and MCP clients to access.
        |
        */
        'rag' => [
            'enabled' => true,
            'type' => 'internal',  // Uses Laravel service, not external process
            'service' => RAGService::class,
            'tools' => 4,
            'description' => 'Hierarchical RAG search and exact document retrieval',
            'trust_boundary' => 'plos_local',
            'transport' => 'internal_service',
            'network_required' => 'lan_only',
            'write_scope' => 'plos_data',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Research MCP Server (Internal)
        |--------------------------------------------------------------------------
        |
        | Internal MCP server for multi-source news research and balanced reporting.
        |
        | Tools provided (2):
        | - research_query: Research a topic across all sources (NewsAPI, GNews)
        | - research_status: Service status and available sources
        |
        | Sources integrated:
        | - NewsAPI.org: 100/day (optional, requires API key)
        | - GNews API: 100/day (optional, requires API key)
        |
        | Features:
        | - Parallel search execution for speed
        | - AI-powered result analysis and summarization
        | - Bias detection and balanced perspective
        | - Automatic deduplication and aggregation
        |
        */
        'research' => [
            'enabled' => true,
            'type' => 'internal',
            'service' => ResearchMCPService::class,
            'tools' => 2,
            'description' => 'Multi-source news research',
            // Hits NewsAPI.org / Wikipedia / SearXNG / public web — must never be
            // invoked under offline profiles. Hybrid profiles may use it.
            'trust_boundary' => 'internet',
            'transport' => 'internal_service',
            'network_required' => 'internet',
            'write_scope' => 'none',
            'secret_surface_risk' => 'medium', // holds API keys
            'offline_profiles_allowed' => [],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Puppeteer MCP Server (Browser Automation)
        |--------------------------------------------------------------------------
        |
        | External MCP server for browser automation via Puppeteer. Provides tools:
        | - puppeteer_navigate: Navigate to a URL
        | - puppeteer_screenshot: Take screenshots
        | - puppeteer_click: Click elements
        | - puppeteer_fill: Fill input fields
        | - puppeteer_select: Select dropdown options
        | - puppeteer_hover: Hover over elements
        | - puppeteer_evaluate: Execute JavaScript on page
        |
        | Used for web scraping (e.g., Ground News bias indicators).
        |
        | Installation: npm install --save-exact @modelcontextprotocol/server-puppeteer@2025.5.12
        |
        */
        'puppeteer' => [
            'enabled' => true,
            'type' => 'external',
            'command' => base_path('node_modules/.bin/mcp-server-puppeteer'),
            'args' => [],
            'runtime_pin' => ['type' => 'npm_lock', 'package' => '@modelcontextprotocol/server-puppeteer', 'version' => '2025.5.12'],
            'env' => [
                // Prefer a maintained system Chrome when present. The cached
                // Puppeteer browser remains a fallback for older hosts and
                // isolated public installs that have not installed Chrome.
                'PUPPETEER_EXECUTABLE_PATH' => env(
                    'PLOS_PUPPETEER_CHROME',
                    is_executable('/usr/bin/google-chrome')
                        ? '/usr/bin/google-chrome'
                        : (is_executable('/usr/bin/chromium')
                            ? '/usr/bin/chromium'
                            : (is_executable('/usr/bin/chromium-browser')
                                ? '/usr/bin/chromium-browser'
                                : env('HOME').'/.cache/puppeteer/chrome/linux-131.0.6778.204/chrome-linux64/chrome'))
                ),
            ],
            'tools' => 7,
            'description' => 'Browser automation for web scraping',
            // Full public-internet browser — blocked under offline profiles.
            'trust_boundary' => 'internet',
            'transport' => 'external_process',
            'network_required' => 'internet',
            'write_scope' => 'none',
            'secret_surface_risk' => 'medium',
            'offline_profiles_allowed' => [],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Nextcloud Calendar MCP Server (Internal)
        |--------------------------------------------------------------------------
        |
        | Internal MCP server for Nextcloud calendar via CalDAV. Provides 2 tools:
        | - get_calendar_events: Get events for a time range
        | - list_calendars: List available calendars
        |
        | Direct CalDAV integration - no broken npm packages required.
        | Works reliably with Nextcloud CalDAV API.
        |
        */
        'nextcloud-calendar' => [
            'enabled' => true,
            'type' => 'internal',
            'service' => NextcloudService::class,
            'tools' => 2,
            'description' => 'Nextcloud calendar (CalDAV)',
            'trust_boundary' => 'local_lan',
            'transport' => 'internal_service',
            'network_required' => 'lan_only',
            'write_scope' => 'nextcloud_calendar',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Nextcloud Contacts MCP Server (Internal)
        |--------------------------------------------------------------------------
        |
        | Internal MCP server for Nextcloud contacts via CardDAV. Provides 4 tools:
        | - get_address_books: List available address books
        | - get_contacts: Get contacts from an address book
        | - search_contacts: Search contacts by query string
        | - get_contact_stats: Get contact statistics
        |
        | Direct CardDAV integration - no broken npm packages required.
        | Works reliably with Nextcloud CardDAV API.
        | Parses vCard format (3.0 and 4.0) with support for:
        | - Name (FN, N), Email, Phone, Organization, Title, Notes
        |
        */
        'nextcloud-contacts' => [
            'enabled' => true,
            'type' => 'internal',
            'service' => NextcloudContactsService::class,
            'tools' => 4,
            'description' => 'Nextcloud contacts (CardDAV)',
            'trust_boundary' => 'local_lan',
            'transport' => 'internal_service',
            'network_required' => 'lan_only',
            'write_scope' => 'nextcloud_contacts',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Joplin Files MCP Server (Internal - via Nextcloud)
        |--------------------------------------------------------------------------
        |
        | Internal MCP server that reads Joplin notes directly from Nextcloud
        | sync folder. Provides read-only access (write support planned).
        |
        | Access to 285 notes + attachments via WebDAV.
        |
        | Tools provided (6):
        | - joplin_search: Search notes by content/title (limit 10 for performance)
        | - joplin_get_note: Get note by ID
        | - joplin_list_notebooks: List all notebooks/folders
        | - joplin_get_notebook: Get notes in notebook
        | - joplin_get_resource: Get attachment info
        | - joplin_status: Service status (total files)
        |
        | Performance Note:
        | - Operations parse markdown files over WebDAV (network I/O)
        | - Status check: ~5-10s (lists 285 files)
        | - Search/notebook operations: slower due to content parsing
        | - Use specific note IDs when possible for best performance
        |
        */
        'joplin-files' => [
            'enabled' => true,
            'type' => 'internal',
            'service' => JoplinFilesService::class,
            'tools' => 6,
            'description' => 'Joplin notes (read-only via Nextcloud sync)',
            'trust_boundary' => 'local_lan',
            'transport' => 'internal_service',
            'network_required' => 'lan_only',
            'write_scope' => 'read_only',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Thunderbird MCP Server (Extension + HTTP API)
        |--------------------------------------------------------------------------
        |
        | Hybrid MCP server that combines:
        | 1. Node.js MCP server (port 8766) for MCP protocol
        | 2. Thunderbird extension (port 8765) for email sending via browser.compose API
        |
        | Architecture:
        | Workflow/AI → MCP Server (8766) → HTTP → TB Extension (8765) → Thunderbird SMTP
        |
        | Location: mcp-server/thunderbird-mcp.cjs
        | Extension: storage/plos-email-sender.xpi
        | Ports: 8766 (MCP), 8765 (Extension)
        |
        | Email Reading:
        | - Direct mbox file access for search/read
        | - Loads: First 5000 emails from top 5 folders (895MB+ files)
        | - Startup: ~15-20 seconds to parse and load emails
        |
        | Email Sending:
        | - Uses Thunderbird WebExtension API (browser.compose.sendMessage)
        | - No SMTP credentials needed (Thunderbird handles auth)
        | - Supports all configured accounts (OAuth2, app passwords, etc.)
        | - Requires: Thunderbird extension installed and TB running
        |
        | Tools provided (6):
        | - listFolders: List all available mbox folders (15 folders, 1.7GB total)
        | - listMailboxes: List all configured email accounts/mailboxes
        | - sendEmail: Send via Thunderbird extension HTTP API (WORKS!)
        | - searchMessages: Search across all loaded folders (supports folder filter)
        | - getRecentMessages: Get N most recent messages from specific folder
        | - getStats: Statistics about loaded folders and emails
        |
        | Benefits:
        | - Uses official Thunderbird APIs (browser.compose.sendMessage)
        | - No SMTP/OAuth2 management needed
        | - Works with all account types (Microsoft, Gmail, etc.)
        | - Leverages existing Thunderbird authentication
        | - Fast email search via direct mbox access
        |
        | Setup Required:
        | 1. Install extension: Open about:addons in Thunderbird
        | 2. Install from file: storage/plos-email-sender.xpi
        | 3. Grant "compose.send" permission
        | 4. Keep Thunderbird running
        |
        */
        'thunderbird' => [
            'enabled' => true,
            'type' => 'external',
            'command' => 'node',
            'args' => [base_path('mcp-server/thunderbird-mcp.cjs')],
            'env' => [
                'THUNDERBIRD_MCP_PORT' => '8766',
                'THUNDERBIRD_MCP_HOST' => '127.0.0.1',
            ],
            'tools' => 6,
            'description' => 'Thunderbird inbox search and email sending (direct mbox access)',
            // Thunderbird runs on the local host but manages OAuth tokens for
            // upstream email accounts — treat as separate trust domain. Reads
            // (listFolders, search) are local; sendEmail touches remote SMTP.
            'trust_boundary' => 'local_host',
            'transport' => 'external_process',
            'network_required' => 'localhost',
            'write_scope' => 'email_outbox',
            'secret_surface_risk' => 'high', // OAuth tokens
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Memory MCP Server (Official Anthropic)
        |--------------------------------------------------------------------------
        |
        | Knowledge graph-based persistent memory system. Stores information
        | about entities, relationships, and observations across workflow runs.
        |
        | Tools provided (~6):
        | - create_entities: Create nodes in knowledge graph
        | - create_relations: Define relationships between entities
        | - add_observations: Add facts/observations to entities
        | - delete_entities: Remove nodes
        | - read_graph: Retrieve knowledge graph data
        | - search_nodes: Search for entities
        |
        | Benefits for workflows:
        | - Remember user preferences across runs
        | - Build knowledge about recurring tasks
        | - Context-aware automation decisions
        | - Track entity relationships (contacts, projects, etc.)
        |
        | Installation: npm install --save-exact @modelcontextprotocol/server-memory@2025.11.25
        |
        */
        'memory' => [
            'enabled' => true,
            'command' => base_path('node_modules/.bin/mcp-server-memory'),
            'args' => [],
            'runtime_pin' => ['type' => 'npm_lock', 'package' => '@modelcontextprotocol/server-memory', 'version' => '2025.11.25'],
            'env' => [],
            'tools' => 6,
            'description' => 'Persistent knowledge graph memory',
            'trust_boundary' => 'plos_local',
            'transport' => 'external_process',
            'network_required' => 'none',
            'write_scope' => 'local_memory_graph',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Sequential Thinking MCP Server (Official Anthropic)
        |--------------------------------------------------------------------------
        |
        | Dynamic and reflective problem-solving through thought sequences.
        | Enables workflows to perform multi-step reasoning and planning.
        |
        | Tools provided (~1):
        | - sequentialthinking: Break down complex problems into reasoning steps
        |
        | Benefits for workflows:
        | - Complex decision making with step-by-step analysis
        | - Workflow planning and optimization
        | - Problem decomposition for automation tasks
        | - Reflective reasoning for adaptive workflows
        |
        | Installation: npm install --save-exact @modelcontextprotocol/server-sequential-thinking@2025.12.18
        |
        */
        'sequential-thinking' => [
            'enabled' => true,
            'command' => base_path('node_modules/.bin/mcp-server-sequential-thinking'),
            'args' => [],
            'runtime_pin' => ['type' => 'npm_lock', 'package' => '@modelcontextprotocol/server-sequential-thinking', 'version' => '2025.12.18'],
            'env' => [],
            'tools' => 1,
            'description' => 'Multi-step reasoning and problem-solving',
            'trust_boundary' => 'plos_local',
            'transport' => 'external_process',
            'network_required' => 'none',
            'write_scope' => 'none',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Time MCP Server (Official Anthropic - Python)
        |--------------------------------------------------------------------------
        |
        | Time and timezone conversion capabilities for temporal operations.
        | Critical for EST/EDT-aware workflow scheduling.
        |
        | Tools provided (2):
        | - get_current_time: Current time in specified IANA timezone
        | - convert_time: Convert time between timezones
        |
        | Benefits for workflows:
        | - Schedule workflows across timezones (EST/EDT handling)
        | - Time-aware notifications and reminders
        | - Calendar event timing with timezone support
        | - Deadline calculations with timezone awareness
        |
        | Installation: uvx mcp-server-time (or pip install mcp-server-time)
        |
        */
        'time' => [
            'enabled' => true,
            'type' => 'internal',  // Use internal PHP implementation instead of unreliable uvx
            'service' => TimeService::class,
            'tools' => 2,
            'description' => 'Time and timezone operations',
            'trust_boundary' => 'plos_local',
            'transport' => 'internal_service',
            'network_required' => 'none',
            'write_scope' => 'none',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Web Research MCP Server (Internal)
        |--------------------------------------------------------------------------
        |
        | AI-driven web research using Puppeteer to scrape privacy-respecting
        | search engines. Provides fault-tolerant multi-engine search with
        | health tracking and automatic failover.
        |
        | Tools provided (5):
        | - web_search: Search using DuckDuckGo, Startpage, Searx, etc.
        | - discover_sources: Find authoritative sources for a topic
        | - get_engine_status: Check search engine health
        | - scrape_page: Extract content from a specific URL
        | - add_source: Register new authoritative source
        |
        | Features:
        | - Multi-engine fallback (YouTube transcript pattern)
        | - Health tracking with auto-disable on repeated failures
        | - Date filtering for current information
        | - Deduplication of results
        | - AI-driven source discovery and vetting
        |
        */
        'web-research' => [
            'enabled' => true,
            'type' => 'internal',
            'service' => WebResearchService::class,
            'tools' => 7,
            'description' => 'AI-driven web research with privacy-respecting search engines',
            // Uses Puppeteer to scrape public search engines — internet class.
            'trust_boundary' => 'internet',
            'transport' => 'internal_service',
            'network_required' => 'internet',
            'write_scope' => 'none',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => [],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | SearXNG MCP Server (Internal)
        |--------------------------------------------------------------------------
        |
        | Local SearXNG privacy-respecting meta search engine.
        | Provides federated search across multiple search engines without tracking.
        |
        | Tools provided (4):
        | - searxng_search: General web search
        | - searxng_images: Image search
        | - searxng_news: News article search
        | - searxng_status: Service health and status
        |
        | Features:
        | - Circuit breaker pattern for fault tolerance
        | - JSON API format for structured results
        | - Multi-category search (general, images, news)
        | - Time range filtering
        | - Auto-recovery after failures
        |
        | Setup: pip install searxng in /opt/searxng/venv
        | Port: 8888 (configurable via SEARXNG_URL)
        |
        | Fallback Position: After Wikipedia, before Curl scraping
        |
        */
        'searxng' => [
            'enabled' => true,
            'type' => 'internal',
            'service' => SearXNGMCPService::class,
            'tools' => 4,
            'description' => 'SearXNG privacy-respecting meta search engine',
            // SearXNG runs on PLOS LAN (port 8888) but federates to public
            // engines — LAN MCP endpoint but public network downstream. Treat
            // the MCP boundary as local_lan and gate external-network tool
            // class separately.
            'trust_boundary' => 'local_lan',
            'transport' => 'internal_service',
            'network_required' => 'internet',
            'write_scope' => 'none',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => [],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Genealogy MCP Server (Internal)
        |--------------------------------------------------------------------------
        |
        | Internal MCP server for genealogy operations. Provides 66 tools:
        | - gedcom_parse: Parse GEDCOM file -> structured data
        | - gedcom_export: Export tree -> GEDCOM string
        | - source_audit_workbook: Generate FT source-audit manifest/CSV/DOCX/ODT package
        | - tree_search: Search persons/families/sources
        | - person_search: Tree-scoped person search
        | - person_profile: Complete read-only person profile
        | - name_variant_add: Add vetted name variants for maiden/married/alias/nickname searches
        | - person_research: AI research suggestions
        | - health_audit: Read-only health audit over selected sections
        | - health_review_packet: Canonical read-only review packets for health-audit issues
        | - review_packet_context: Compact read-only genealogy review-packet context by target ref or token
        | - review_packet_decision: Guarded dry-run-first genealogy review-packet decision wrapper
        | - relationship_audit: Read-only relationship integrity audit
        | - tree_stats: Get tree statistics
        | - tree_status: Aggregated tree readiness/status summary
        | - work_status: Count-only work counters for batching decisions
        | - coverage_rebuild: Dry-run-first ancestor path and person coverage rebuild
        | - schedule_status: Compact read-only scheduler coverage and recent failures
        | - research_task_queue: Compact read-only research task queue by tree
        | - research_task_profile: Read-only detail for one research task
        | - research_task_create: Dry-run-first guarded research task creation
        | - source_extract: Extract source citations with URLs
        | - source_profile: Read-only source detail, citation, and media context
        | - person_source_gap_batch: Ranked people lacking direct person-source links
        | - source_gap_decision_lookup: Read source-gap review memory
        | - source_gap_decision_add: Store source-gap review memory
        | - media_profile: Read-only media detail and link context
        | - media_review_packet: Compact document/OCR/media evidence review packet
        | - media_ocr_escalation_batch: Compact OCR/HTR/vision escalation candidates
        | - person_fact_extract: Extract review-first fact/relationship candidates from one evidence packet
        | - media_unlinked: List unlinked media candidates with metadata hints
        | - media_triage_batch: Compact unlinked media triage buckets
        | - media_review_mark: Store non-destructive review memory for unresolved media
        | - media_quarantine: Guarded quarantine/delete for unlinked uncited media
        | - media_duplicate_consolidate: Guarded consolidation for byte-identical duplicate media
        | - media_rag_batch: Bounded media RAG indexing batch wrapper
        | - rag_index_batch: Bounded person/place/source RAG indexing batch wrapper
        | - media_htr_batch: Bounded HTR transcription status, dry-run, or confirmed batch
        | - media_link_integrity: Audit and repair deterministic missing media links
        | - person_source_link_integrity: Audit and repair deterministic missing person-source links
        | - source_citation_link_apply: Dry-run-first bounded source citation/link creation
        | - evidence_capture_plan: Plan capture-ready evidence media for FT storage
        | - evidence_capture_review: Materialize tree-scoped capture approval rows
        | - evidence_capture_execute: Preflight or execute approved FT media capture
        | - evidence_capture_direct: Dry-run-first one-off vetted evidence URL capture
        | - source_media_backfill: Backfill URL-only source rows into FT storage
        | - nara_placeholder_capture_batch: Capture NARA URL-only media placeholders into FT storage
        | - media_attach_proposal: Create review-first person/family media attachment proposals
        | - media_identity_apply: Apply operator-confirmed media identity metadata and links
        | - source_add_proposal: Create review-first person/source attachment proposals
        | - fact_update_proposal: Create review-first person fact update proposals
        | - relationship_link_proposal: Create review-first existing-person relationship proposals
        | - apply_approved_proposal: Apply already-approved proposals with dry-run preview
        | - person_fact_apply_batch: Apply approved source-backed person fact proposals in bounded batches
        | - approve_apply_proposal: Confirm proposal approval and application through canonical review services
        | - proposal_queue: Read-only tree-scoped proposal review queue
        | - review_packet_memory_batch: Feed accepted/rejected review-packet outcomes into semantic memory
        | - rag_status: Get person/media RAG and embedding coverage
        | - export_readiness: Get self-contained export readiness checks
        | - export_standalone_status: Summarize GEDZip/tree-folder standalone export status
        | - duplicate_candidates: List read-only duplicate person candidates
        | - family_duplicate_retire: Retire isolated duplicate family rows after strict no-reference checks
        | - non_ft_name_add: Store tree-scoped rejected/non-FT name memory
        | - non_ft_name_lookup: Search rejected/non-FT name memory
        | - research_memo_save: Save guarded FT-local research memos and source-gap memory
        |
        | Enables AI orchestration of family tree research and data management.
        | Uses RAW SQL - no Eloquent models.
        |
        */
        'genealogy' => [
            'enabled' => true,
            'type' => 'internal',
            'service' => GenealogyMCPService::class,
            'tools' => 66,
            'description' => 'Genealogy operations (GEDCOM, search, AI research)',
            'trust_boundary' => 'plos_local',
            'transport' => 'internal_service',
            'network_required' => 'lan_only',
            'write_scope' => 'plos_data',
            'secret_surface_risk' => 'low',
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist', 'offline_genealogy_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Filesystem MCP Server (Official Anthropic)
        |--------------------------------------------------------------------------
        |
        | Secure file operations with configurable access controls.
        | Complements Nextcloud by providing controlled local filesystem access.
        |
        | Tools provided (~8):
        | - read_file: Read file contents
        | - write_file: Create/update files
        | - list_directory: List directory contents
        | - create_directory: Make directories
        | - move_file: Move/rename files
        | - search_files: Search file contents
        | - get_file_info: File metadata
        | - delete_file: Remove files
        |
        | Benefits for workflows:
        | - Process local files before uploading to Nextcloud
        | - Generate reports and save locally
        | - Read configuration files
        | - Monitor log files
        |
        | Security: Configure allowed directories in .env
        |
        | Installation: npm install --save-exact @modelcontextprotocol/server-filesystem@2025.12.18
        |
        */
        'filesystem' => [
            'enabled' => true,
            'command' => base_path('node_modules/.bin/mcp-server-filesystem'),
            'args' => $filesystemMcpAllowedDirs,
            'runtime_pin' => ['type' => 'npm_lock', 'package' => '@modelcontextprotocol/server-filesystem', 'version' => '2025.12.18'],
            'env' => [],
            'tools' => 8,
            'description' => 'Secure local file operations',
            // Filesystem writes are gated separately by path_class / protected_paths
            // inside OfflinePolicyService — the MCP boundary is local_host because
            // the server binary runs on the PLOS host with a configurable scope.
            'trust_boundary' => 'local_host',
            'transport' => 'external_process',
            'network_required' => 'none',
            'write_scope' => 'filesystem_scoped',
            'secret_surface_risk' => 'medium', // can touch .env/.ssh if misconfigured
            'offline_profiles_allowed' => ['offline_review', 'offline_dev_assist'],
            'hybrid_profiles_allowed' => ['hybrid_review', 'hybrid_dev_assist', 'cloud_escalation_only'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Everything MCP Server (Official Anthropic)
        |--------------------------------------------------------------------------
        |
        | Multi-purpose MCP conformance server. Disabled by default because
        | its current catalog includes environment inspection, mutable session
        | state, and an open-world URL fetch/compression utility.
        |
        | Tools provided (13 in 2026.7.4):
        | - Various utility and testing tools
        | - Echo, time, math, and other helpers
        |
        | Benefits for workflows:
        | - Testing MCP integration
        | - Learning tool calling patterns
        | - Utility functions for workflows
        | - Development and debugging
        |
        | This does not enable AI prompt compression; prompt compression and
        | precompaction remain unavailable in PLOS.
        |
        | No API key needed
        |
        | Installation: npm install --save-exact @modelcontextprotocol/server-everything@2026.7.4
        |
        */
        'everything' => [
            'enabled' => (bool) env('MCP_EVERYTHING_ENABLED', false),
            'command' => base_path('node_modules/.bin/mcp-server-everything'),
            'args' => [],
            'runtime_pin' => ['type' => 'npm_lock', 'package' => '@modelcontextprotocol/server-everything', 'version' => '2026.7.4'],
            'env' => [],
            'tools' => 13,
            'description' => 'Operator-enabled MCP conformance and utility fixture',
            'trust_boundary' => 'plos_local',
            'transport' => 'external_process',
            'network_required' => 'internet_optional',
            'write_scope' => 'session_state',
            'secret_surface_risk' => 'high',
            'offline_profiles_allowed' => [],
            'hybrid_profiles_allowed' => [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Router Configuration
    |--------------------------------------------------------------------------
    */

    'router' => [
        // Timeout for MCP tool calls (seconds)
        'timeout' => (int) env('MCP_TIMEOUT', 60),

        // Enable tool call logging
        'log_calls' => env('MCP_LOG_CALLS', true),

        // Cache tool catalog (seconds)
        'cache_catalog_ttl' => (int) env('MCP_CACHE_TTL', 3600),

        // Maximum retries on failure
        'max_retries' => (int) env('MCP_MAX_RETRIES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ollama Tool Calling Configuration
    |--------------------------------------------------------------------------
    */

    'ollama_tool_calling' => [
        // Temperature for tool selection (0.0 for deterministic)
        'temperature' => 0.0,

        // Max tokens for tool selection response
        'max_tokens' => 500,

        // Timeout for Ollama tool calling (seconds)
        'timeout' => (int) env('OLLAMA_TOOL_CALLING_TIMEOUT', 45),
    ],
];
