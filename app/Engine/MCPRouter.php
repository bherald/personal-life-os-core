<?php

namespace App\Engine;

use App\Services\NextcloudService;
use App\Services\RAGService;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Symfony\Component\Process\Process;

/**
 * MCP Router
 *
 * Routes tool calls to appropriate MCP servers (external or internal).
 * Supports both Ollama and Claude Code as clients.
 *
 * Architecture:
 * - External MCP servers: Communicate via stdio/JSON-RPC
 * - Internal services: Direct PHP method calls (RAG, workflows)
 * - Tool catalog: Cached for performance
 */
class MCPRouter
{
    private array $config;

    private int $timeout;

    private bool $logCalls;

    private int $cacheTtl;

    public function __construct()
    {
        $this->config = config('mcp.servers', []);
        $this->timeout = config('mcp.router.timeout', 30);
        $this->logCalls = config('mcp.router.log_calls', true);
        $this->cacheTtl = config('mcp.router.cache_catalog_ttl', 3600);
    }

    /**
     * Get all available tools from enabled MCP servers
     *
     * @return array Tool catalog with server attribution
     */
    public function getAvailableTools(): array
    {
        // Mode-aware cache key. The catalog shape depends on whether
        // dynamic discovery is enabled, so a static-mode entry cached
        // first must not be returned after the flag flips to true (and
        // vice versa). Both variants are cleared by clearCache().
        $cacheKey = $this->toolCatalogCacheKey();

        return Cache::remember($cacheKey, $this->cacheTtl, function () {
            $tools = [];

            foreach ($this->config as $serverName => $serverConfig) {
                if (! ($serverConfig['enabled'] ?? false)) {
                    continue;
                }

                try {
                    $serverTools = $this->discoverServerTools($serverName, $serverConfig);

                    foreach ($serverTools as $tool) {
                        $tools[] = array_merge($tool, [
                            'server' => $serverName,
                            'server_description' => $serverConfig['description'] ?? '',
                        ]);
                    }
                } catch (Exception $e) {
                    Log::warning("Failed to discover tools from MCP server: {$serverName}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $tools;
        });
    }

    /**
     * Return only the MCP tools that are currently legal for the supplied
     * profile. This keeps operator-facing catalogs aligned with the active
     * policy instead of exposing the full raw list and denying later.
     *
     * Each returned tool is annotated with:
     *   - tool_class
     *   - requires_confirmation
     *   - policy_profile
     *   - policy_reason
     */
    public function getAvailableToolsForProfile(string $profile): array
    {
        $tools = $this->getAvailableTools();

        try {
            $policy = app(\App\Services\OfflinePolicyService::class);
        } catch (\Throwable $e) {
            return $tools;
        }

        $filtered = [];

        foreach ($tools as $tool) {
            $server = (string) ($tool['server'] ?? '');
            $name = (string) ($tool['name'] ?? '');

            if ($server === '' || $name === '') {
                continue;
            }

            try {
                $decision = $policy->evaluateMcpTool($server, $name, ['_audit' => false], $profile);
            } catch (\Throwable $e) {
                Log::warning('MCPRouter: profile tool-catalog evaluation failed — omitting tool from filtered list', [
                    'server' => $server,
                    'tool' => $name,
                    'profile' => $profile,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $decision->allowed) {
                continue;
            }

            $filtered[] = array_merge($tool, [
                'tool_class' => $decision->toolClass,
                'requires_confirmation' => $decision->requiresConfirmation,
                'policy_profile' => $decision->profile,
                'policy_reason' => $decision->reason,
            ]);
        }

        return $filtered;
    }

    /**
     * Discover tools from a specific MCP server
     *
     * @param  string  $serverName  Server identifier
     * @param  array  $serverConfig  Server configuration
     * @return array List of tools
     */
    private function discoverServerTools(string $serverName, array $serverConfig): array
    {
        $type = $serverConfig['type'] ?? 'external';

        if ($type === 'internal') {
            return $this->discoverInternalTools($serverName, $serverConfig);
        }

        return $this->discoverExternalTools($serverName, $serverConfig);
    }

    /**
     * Discover tools from internal Laravel services
     */
    private function discoverInternalTools(string $serverName, array $serverConfig): array
    {
        if ($serverName === 'plos') {
            return [
                [
                    'name' => 'workflow_list',
                    'description' => 'List all workflows in the database',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'active_only' => ['type' => 'boolean', 'description' => 'Only return active workflows'],
                        ],
                    ],
                ],
                [
                    'name' => 'workflow_get',
                    'description' => 'Get details of a specific workflow by name',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'name' => ['type' => 'string', 'description' => 'Workflow name'],
                        ],
                        'required' => ['name'],
                    ],
                ],
                [
                    'name' => 'workflow_run',
                    'description' => 'Execute a workflow by name',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'name' => ['type' => 'string', 'description' => 'Workflow name'],
                            'input' => ['type' => 'object', 'description' => 'Optional workflow input payload'],
                        ],
                        'required' => ['name'],
                    ],
                ],
                [
                    'name' => 'execution_list',
                    'description' => 'Get workflow execution history',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'workflow_id' => ['type' => 'integer', 'description' => 'Filter by workflow ID (optional)'],
                            'limit' => ['type' => 'integer', 'description' => 'Maximum results (default: 50)', 'default' => 50],
                        ],
                    ],
                ],
                [
                    'name' => 'execution_get',
                    'description' => 'Get detailed information about a workflow execution',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'run_id' => ['type' => 'integer', 'description' => 'Execution run ID'],
                        ],
                        'required' => ['run_id'],
                    ],
                ],
                [
                    'name' => 'artisan_execute',
                    'description' => 'Execute whitelisted artisan commands (workflow:list, route:list, migrate:status, about)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'command' => ['type' => 'string', 'description' => 'Artisan command (whitelisted only)'],
                            'arguments' => ['type' => 'array', 'description' => 'Command arguments (optional)'],
                        ],
                        'required' => ['command'],
                    ],
                ],
                [
                    'name' => 'node_create',
                    'description' => 'Generate a new workflow node class from template',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'name' => ['type' => 'string', 'description' => 'Node class name (e.g., "EmailSender")'],
                            'description' => ['type' => 'string', 'description' => 'What the node does'],
                        ],
                        'required' => ['name', 'description'],
                    ],
                ],
                [
                    'name' => 'schedule_list',
                    'description' => 'List all scheduled workflows and their cron schedules',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
                [
                    'name' => 'system_diagnostics',
                    'description' => 'Get system health check (database, queue, AI services)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
            ];
        }

        if ($serverName === 'repo-dev') {
            return [
                [
                    'name' => 'find_repo_files',
                    'description' => 'Find repository files by substring match on repo-relative path',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'pattern' => ['type' => 'string', 'description' => 'Substring to match in repo-relative paths'],
                            'path' => ['type' => 'string', 'description' => 'Optional repo-relative directory scope (default: .)'],
                            'limit' => ['type' => 'integer', 'description' => 'Maximum matches to return (default: 50)'],
                        ],
                        'required' => ['pattern'],
                    ],
                ],
                [
                    'name' => 'search_repo',
                    'description' => 'Search repository file contents for a literal string',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'query' => ['type' => 'string', 'description' => 'Literal string to search for'],
                            'path' => ['type' => 'string', 'description' => 'Optional repo-relative directory scope (default: .)'],
                            'limit' => ['type' => 'integer', 'description' => 'Maximum matches to return (default: 50)'],
                        ],
                        'required' => ['query'],
                    ],
                ],
                [
                    'name' => 'list_repo_directory',
                    'description' => 'List files and directories under a repo-relative path',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'path' => ['type' => 'string', 'description' => 'Repo-relative directory path (default: .)'],
                            'recursive' => ['type' => 'boolean', 'description' => 'Recurse into subdirectories'],
                            'limit' => ['type' => 'integer', 'description' => 'Maximum entries to return (default: 200)'],
                        ],
                    ],
                ],
                [
                    'name' => 'read_repo_file',
                    'description' => 'Read a repository text file with line numbers',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'path' => ['type' => 'string', 'description' => 'Repo-relative file path'],
                            'start_line' => ['type' => 'integer', 'description' => 'Optional starting line number'],
                            'end_line' => ['type' => 'integer', 'description' => 'Optional ending line number'],
                            'max_lines' => ['type' => 'integer', 'description' => 'Maximum lines to return (default: 300)'],
                        ],
                        'required' => ['path'],
                    ],
                ],
                [
                    'name' => 'write_repo_file',
                    'description' => 'Write a text file inside the repository root',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'path' => ['type' => 'string', 'description' => 'Repo-relative file path'],
                            'content' => ['type' => 'string', 'description' => 'Full replacement content'],
                            'create_directories' => ['type' => 'boolean', 'description' => 'Create parent directories when missing'],
                        ],
                        'required' => ['path', 'content'],
                    ],
                ],
                [
                    'name' => 'apply_repo_patch',
                    'description' => 'Apply a unified diff inside the repository after repo path policy checks',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'patch' => ['type' => 'string', 'description' => 'Unified diff to apply'],
                            'check_only' => ['type' => 'boolean', 'description' => 'Validate the patch without applying it'],
                        ],
                        'required' => ['patch'],
                    ],
                ],
                [
                    'name' => 'run_verification',
                    'description' => 'Run allowlisted verification commands such as targeted tests, pint --test, composer analyse, or git diff --check',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'runner' => ['type' => 'string', 'description' => 'unit, feature, stabilization, phpunit-target, pint-test, composer-analyse, or diff-check'],
                            'target' => ['type' => 'string', 'description' => 'Required for phpunit-target; must be under tests/'],
                            'filter' => ['type' => 'string', 'description' => 'Optional phpunit --filter value for phpunit-target'],
                            'timeout_seconds' => ['type' => 'integer', 'description' => 'Timeout from 1 to 600 seconds'],
                        ],
                        'required' => ['runner'],
                    ],
                ],
                [
                    'name' => 'list_routes',
                    'description' => 'List Laravel routes by scope (frontend, api, or all)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'scope' => ['type' => 'string', 'description' => 'frontend, api, or all'],
                            'filter' => ['type' => 'string', 'description' => 'Optional substring filter for uri, name, or action'],
                        ],
                    ],
                ],
            ];
        }

        if ($serverName === 'rag') {
            return [
                [
                    'name' => 'rag_search',
                    'description' => 'Search indexed documents using semantic similarity. Returns most relevant documents.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'query' => ['type' => 'string', 'description' => 'Search query'],
                            'limit' => ['type' => 'integer', 'description' => 'Max results (1-20)', 'default' => 5],
                            'document_type' => ['type' => 'string', 'description' => 'Filter by type (optional)'],
                        ],
                        'required' => ['query'],
                    ],
                ],
                [
                    'name' => 'rag_index',
                    'description' => 'Index a new document for semantic search with embeddings',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'type' => ['type' => 'string', 'description' => 'Document type'],
                            'title' => ['type' => 'string', 'description' => 'Document title'],
                            'content' => ['type' => 'string', 'description' => 'Document content'],
                            'metadata' => ['type' => 'object', 'description' => 'Additional metadata'],
                        ],
                        'required' => ['type', 'title', 'content'],
                    ],
                ],
                [
                    'name' => 'rag_similar',
                    'description' => 'Find documents similar to a given document ID',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'document_id' => ['type' => 'integer', 'description' => 'Document ID'],
                            'limit' => ['type' => 'integer', 'description' => 'Max results', 'default' => 5],
                        ],
                        'required' => ['document_id'],
                    ],
                ],
            ];
        }

        if ($serverName === 'nextcloud-calendar') {
            return [
                [
                    'name' => 'get_calendar_events',
                    'description' => 'Get calendar events for a time range (defaults to first available calendar and current month)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'calendar' => ['type' => 'string', 'description' => 'Calendar name (optional - uses first available if not specified)'],
                            'start' => ['type' => 'string', 'description' => 'Start date (ISO 8601, optional - defaults to start of current month)'],
                            'end' => ['type' => 'string', 'description' => 'End date (ISO 8601, optional - defaults to end of current month)'],
                        ],
                    ],
                ],
                [
                    'name' => 'list_calendars',
                    'description' => 'List all available calendars',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
            ];
        }

        if ($serverName === 'nextcloud-contacts') {
            return [
                [
                    'name' => 'get_address_books',
                    'description' => 'Get list of available address books',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
                [
                    'name' => 'get_contacts',
                    'description' => 'Get contacts from an address book (defaults to first available)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'address_book' => ['type' => 'string', 'description' => 'Address book name (optional - uses first available if not specified)'],
                            'limit' => ['type' => 'integer', 'description' => 'Maximum number of contacts to return (default: 100)', 'default' => 100],
                        ],
                    ],
                ],
                [
                    'name' => 'search_contacts',
                    'description' => 'Search contacts by query string (searches name, email, phone, organization)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'query' => ['type' => 'string', 'description' => 'Search query'],
                            'address_book' => ['type' => 'string', 'description' => 'Address book name (optional)'],
                        ],
                        'required' => ['query'],
                    ],
                ],
                [
                    'name' => 'get_contact_stats',
                    'description' => 'Get contact statistics (total address books and contacts)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
            ];
        }

        if ($serverName === 'joplin-files') {
            return [
                [
                    'name' => 'joplin_search',
                    'description' => 'Search Joplin notes by content or title',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'query' => ['type' => 'string', 'description' => 'Search query'],
                            'limit' => ['type' => 'integer', 'description' => 'Maximum number of results (default: 10)', 'default' => 10],
                        ],
                        'required' => ['query'],
                    ],
                ],
                [
                    'name' => 'joplin_get_note',
                    'description' => 'Get a specific Joplin note by ID',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'note_id' => ['type' => 'string', 'description' => 'Note ID (32-character hex string)'],
                        ],
                        'required' => ['note_id'],
                    ],
                ],
                [
                    'name' => 'joplin_list_notebooks',
                    'description' => 'List all Joplin notebooks/folders',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
                [
                    'name' => 'joplin_get_notebook',
                    'description' => 'Get all notes in a specific Joplin notebook',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'notebook_id' => ['type' => 'string', 'description' => 'Notebook ID'],
                        ],
                        'required' => ['notebook_id'],
                    ],
                ],
                [
                    'name' => 'joplin_get_resource',
                    'description' => 'Get Joplin attachment/resource metadata',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'resource_id' => ['type' => 'string', 'description' => 'Resource ID'],
                        ],
                        'required' => ['resource_id'],
                    ],
                ],
                [
                    'name' => 'joplin_status',
                    'description' => 'Get Joplin service status (total notes, notebooks, resources)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
            ];
        }

        if ($serverName === 'time') {
            return [
                [
                    'name' => 'get_current_time',
                    'description' => 'Get current time in a specified timezone (IANA timezone database)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'timezone' => [
                                'type' => 'string',
                                'description' => 'IANA timezone (e.g., America/New_York, Europe/London, UTC). Defaults to '.config('app.timezone', 'America/New_York'),
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'convert_time',
                    'description' => 'Convert time from one timezone to another',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'time' => ['type' => 'string', 'description' => 'Time in Y-m-d H:i:s format (defaults to current time)'],
                            'from_timezone' => ['type' => 'string', 'description' => 'Source IANA timezone (defaults to UTC)'],
                            'to_timezone' => ['type' => 'string', 'description' => 'Target IANA timezone (defaults to '.config('app.timezone', 'America/New_York').')'],
                        ],
                    ],
                ],
            ];
        }

        if ($serverName === 'web-research') {
            return [
                [
                    'name' => 'web_search',
                    'description' => 'Search the web using privacy-respecting search engines (DuckDuckGo, Startpage, Searx). Returns search results with titles, URLs, and snippets.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'query' => ['type' => 'string', 'description' => 'Search query'],
                            'max_results' => ['type' => 'integer', 'description' => 'Maximum results to return (default: 10)', 'default' => 10],
                        ],
                        'required' => ['query'],
                    ],
                ],
                [
                    'name' => 'web_search_parallel',
                    'description' => 'Search the web using MULTIPLE sources in parallel (SearXNG + Wikipedia + NewsAPI). Faster and more comprehensive than sequential search. Best for chat/general queries.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'query' => ['type' => 'string', 'description' => 'Search query'],
                            'max_results' => ['type' => 'integer', 'description' => 'Maximum results to return (default: 15)', 'default' => 15],
                        ],
                        'required' => ['query'],
                    ],
                ],
                [
                    'name' => 'discover_sources',
                    'description' => 'Discover authoritative sources for a research topic. Finds relevant .edu, .gov, .org domains.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'topic' => ['type' => 'string', 'description' => 'Research topic to find sources for'],
                        ],
                        'required' => ['topic'],
                    ],
                ],
                [
                    'name' => 'get_engine_status',
                    'description' => 'Get health status of all search engines (success/failure counts, availability).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
                [
                    'name' => 'scrape_page',
                    'description' => 'Scrape content from a specific URL using Puppeteer. Use for deeper research on discovered pages.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'url' => ['type' => 'string', 'description' => 'URL to scrape'],
                            'extract_text' => ['type' => 'boolean', 'description' => 'Extract main text content (default: true)', 'default' => true],
                        ],
                        'required' => ['url'],
                    ],
                ],
                [
                    'name' => 'add_source',
                    'description' => 'Add a newly discovered authoritative source to the database for future research.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'name' => ['type' => 'string', 'description' => 'Source name'],
                            'url' => ['type' => 'string', 'description' => 'Base URL'],
                            'categories' => ['type' => 'array', 'description' => 'Categories (e.g., ["medical", "academic"])'],
                            'trust_score' => ['type' => 'integer', 'description' => 'Trust score 1-10 (default: 5)'],
                            'domain_type' => ['type' => 'string', 'description' => 'Domain type (government, academic, commercial, news)'],
                        ],
                        'required' => ['name', 'url'],
                    ],
                ],
            ];
        }

        if ($serverName === 'searxng') {
            return [
                [
                    'name' => 'searxng_search',
                    'description' => 'Search the web using SearXNG privacy-respecting meta search engine. Returns web results with titles, URLs, and snippets.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'query' => ['type' => 'string', 'description' => 'Search query'],
                            'max_results' => ['type' => 'integer', 'description' => 'Maximum results to return (default: 10)', 'default' => 10],
                            'language' => ['type' => 'string', 'description' => 'Language code (default: en)', 'default' => 'en'],
                            'time_range' => ['type' => 'string', 'description' => 'Time filter: day, week, month, year, or empty'],
                        ],
                        'required' => ['query'],
                    ],
                ],
                [
                    'name' => 'searxng_images',
                    'description' => 'Search for images using SearXNG. Returns image URLs with thumbnails.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'query' => ['type' => 'string', 'description' => 'Search query'],
                            'max_results' => ['type' => 'integer', 'description' => 'Maximum results to return (default: 20)', 'default' => 20],
                            'language' => ['type' => 'string', 'description' => 'Language code (default: en)', 'default' => 'en'],
                        ],
                        'required' => ['query'],
                    ],
                ],
                [
                    'name' => 'searxng_news',
                    'description' => 'Search for news articles using SearXNG. Returns recent news with publication dates.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'query' => ['type' => 'string', 'description' => 'Search query'],
                            'max_results' => ['type' => 'integer', 'description' => 'Maximum results to return (default: 10)', 'default' => 10],
                            'language' => ['type' => 'string', 'description' => 'Language code (default: en)', 'default' => 'en'],
                            'time_range' => ['type' => 'string', 'description' => 'Time filter: day, week, month, year (default: week)', 'default' => 'week'],
                        ],
                        'required' => ['query'],
                    ],
                ],
                [
                    'name' => 'searxng_status',
                    'description' => 'Get SearXNG service status including circuit breaker state and health.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
            ];
        }

        if ($serverName === 'genealogy') {
            return [
                [
                    'name' => 'gedcom_parse',
                    'description' => 'Parse a GEDCOM file and return structured genealogy data (persons, families, sources).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'file_path' => ['type' => 'string', 'description' => 'Path to GEDCOM file (absolute or relative to storage/app/genealogy/)'],
                            'preview_only' => ['type' => 'boolean', 'description' => 'If true, return stats only without full person data (default: false)', 'default' => false],
                        ],
                        'required' => ['file_path'],
                    ],
                ],
                [
                    'name' => 'gedcom_export',
                    'description' => 'Export a family tree to GEDCOM 5.5.1 format.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to export'],
                            'include_living' => ['type' => 'boolean', 'description' => 'Include living persons (default: false for privacy)', 'default' => false],
                            'include_media' => ['type' => 'boolean', 'description' => 'Include media object references (default: true)', 'default' => true],
                        ],
                        'required' => ['tree_id'],
                    ],
                ],
                [
                    'name' => 'tree_search',
                    'description' => 'Search the genealogy database for persons, families, or sources by name/title.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'query' => ['type' => 'string', 'description' => 'Search query (name, surname, title)'],
                            'type' => ['type' => 'string', 'description' => 'Type to search: person, family, source, all (default: all)', 'default' => 'all'],
                            'tree_id' => ['type' => 'integer', 'description' => 'Optional: limit search to specific tree'],
                            'limit' => ['type' => 'integer', 'description' => 'Maximum results per type (default: 20)', 'default' => 20],
                        ],
                        'required' => ['query'],
                    ],
                ],
                [
                    'name' => 'person_research',
                    'description' => 'Get AI-powered genealogy research suggestions for a specific person.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'person_id' => ['type' => 'integer', 'description' => 'Person ID to research'],
                            'focus' => ['type' => 'string', 'description' => 'Research focus: ancestry, descendants, siblings, general, brick_wall (default: general)', 'default' => 'general'],
                        ],
                        'required' => ['person_id'],
                    ],
                ],
                [
                    'name' => 'tree_stats',
                    'description' => 'Get comprehensive statistics about a family tree.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'tree_id' => ['type' => 'integer', 'description' => 'Tree ID'],
                        ],
                        'required' => ['tree_id'],
                    ],
                ],
                [
                    'name' => 'source_extract',
                    'description' => 'Extract source citations with URLs for media download.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'tree_id' => ['type' => 'integer', 'description' => 'Tree ID'],
                            'person_id' => ['type' => 'integer', 'description' => 'Optional: limit to specific person'],
                            'limit' => ['type' => 'integer', 'description' => 'Maximum citations to return (default: 50)', 'default' => 50],
                        ],
                        'required' => ['tree_id'],
                    ],
                ],
            ];
        }

        if ($serverName === 'code-review') {
            return [
                [
                    'name' => 'code_review',
                    'description' => 'Review a code snippet for issues (security, performance, bugs, best practices, style).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'code' => ['type' => 'string', 'description' => 'Code to review'],
                            'language' => ['type' => 'string', 'description' => 'Programming language (e.g., php, javascript, python)'],
                            'check_types' => ['type' => 'array', 'description' => 'Types to check: security, performance, bugs, best_practices, style'],
                        ],
                        'required' => ['code', 'language'],
                    ],
                ],
                [
                    'name' => 'code_review_file',
                    'description' => 'Review an entire file for issues.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'file_path' => ['type' => 'string', 'description' => 'Path to the file to review'],
                            'check_types' => ['type' => 'array', 'description' => 'Types to check: security, performance, bugs, best_practices, style'],
                        ],
                        'required' => ['file_path'],
                    ],
                ],
                [
                    'name' => 'code_review_diff',
                    'description' => 'Review a git diff for issues.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'diff' => ['type' => 'string', 'description' => 'Git diff to review'],
                            'context' => ['type' => 'string', 'description' => 'Optional context about the changes'],
                        ],
                        'required' => ['diff'],
                    ],
                ],
                [
                    'name' => 'code_suggest_improvements',
                    'description' => 'Get improvement suggestions for code.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'code' => ['type' => 'string', 'description' => 'Code to analyze'],
                            'language' => ['type' => 'string', 'description' => 'Programming language'],
                            'focus' => ['type' => 'string', 'description' => 'Focus area: readability, performance, maintainability, all'],
                        ],
                        'required' => ['code', 'language'],
                    ],
                ],
            ];
        }

        return [];
    }

    /**
     * Discover tools from external MCP servers via stdio
     *
     * Defaults to predefined static schemas for performance and warm-path
     * reliability. Operators can opt into live discovery by setting
     * `config/mcp.php dynamic_discovery_enabled = true`; dynamic discovery
     * uses the same initialize → initialized → tools/list sequencing as the
     * live callExternalTool path, and falls back to static on any failure
     * so a flaky server can never blackhole the router.
     */
    private function discoverExternalTools(string $serverName, array $serverConfig): array
    {
        $staticTools = $this->getStaticToolDefinitions($serverName);

        if (! (bool) config('mcp.dynamic_discovery_enabled', false)) {
            return $staticTools;
        }

        $dynamic = $this->discoverExternalToolsDynamic($serverName, $serverConfig);
        if (empty($dynamic)) {
            Log::info("Dynamic discovery returned no tools for {$serverName}, falling back to static");

            return $staticTools;
        }

        return $dynamic;
    }

    /**
     * Dynamic-discovery implementation, opt-in via
     * `config('mcp.dynamic_discovery_enabled')`. Spec-compliant sequencing:
     *   1. send initialize
     *   2. wait for the initialize response
     *   3. send notifications/initialized (no response expected)
     *   4. send tools/list, read response
     *   5. close stdin only in the final cleanup
     *
     * Returns [] on any failure so the caller can fall back to static.
     */
    private function discoverExternalToolsDynamic(string $serverName, array $serverConfig): array
    {
        $command = $serverConfig['command'] ?? null;
        $args = $serverConfig['args'] ?? [];
        $env = $serverConfig['env'] ?? [];

        if (! $command) {
            Log::warning("No command specified for external MCP server: {$serverName}");

            return [];
        }

        // Augment env with PATH + npm global bin so node-based MCP servers
        // can resolve the `node` interpreter and any global modules. Same
        // treatment as the live callExternalTool path — without this, empty
        // $env means the subprocess launches with no PATH and silently
        // fails to import its own SDK dependencies, producing a 10-second
        // read timeout with zero tools.
        $npmGlobalBin = $this->resolveRuntimeEnvValue('HOME').'/.npm-global/bin';
        $systemPath = $this->resolveRuntimeEnvValue('PATH') ?: '/usr/bin:/bin:/usr/local/bin';
        $env['PATH'] = $env['PATH'] ?? $systemPath;
        if (! str_contains($env['PATH'], $npmGlobalBin)) {
            $env['PATH'] = $npmGlobalBin.':'.$env['PATH'];
        }
        $env['HOME'] = $env['HOME'] ?? $this->resolveRuntimeEnvValue('HOME');

        // Build command. Use the array form of proc_open so the shell does
        // not sit between us and the subprocess — mirrors callExternalTool
        // and ensures env is applied to the MCP server, not to /bin/sh.
        $cmdParts = array_merge([$command], $args);

        try {
            // Create process descriptors for stdio communication
            $descriptors = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];

            // Start the MCP server process
            $process = proc_open($cmdParts, $descriptors, $pipes, base_path(), $env);

            if (! is_resource($process)) {
                throw new Exception("Failed to start MCP server: {$serverName}");
            }

            // Non-blocking stdout/stderr so stream_select + stream_get_contents
            // can drive the read loop (same pattern as the live callExternalTool
            // path; blocking+fgets hangs when the server's JSON-RPC frame is
            // not newline-terminated promptly).
            stream_set_blocking($pipes[0], true);   // stdin stays blocking for writes
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            // Initial wait for server startup (uvx needs time to download/setup)
            $initialDelay = 500000; // 500ms default
            if (strpos($command, 'uvx') !== false) {
                $initialDelay = 5000000; // 5 seconds for uvx (increased from 3)

                // Additional polling check for uvx readiness
                $maxWait = 10; // 10 seconds max
                $waited = 0;
                while ($waited < $maxWait) {
                    $status = proc_get_status($process);
                    if ($status && $status['running']) {
                        // Check if stderr has any output (indicates uvx is still initializing)
                        $stderr = stream_get_contents($pipes[2]);
                        if (empty($stderr) || strpos($stderr, 'Installed') !== false) {
                            // Server likely ready
                            break;
                        }
                    }
                    usleep(500000); // Wait 0.5s more
                    $waited += 0.5;
                }
            }
            usleep($initialDelay);

            // MCP spec sequencing (mirrors the live callExternalTool path):
            //   1. send initialize
            //   2. wait for the initialize response
            //   3. send notifications/initialized (required before any
            //      further request or the SDK treats the transport as
            //      partially-connected — see callExternalTool note)
            //   4. send tools/list
            //   5. keep stdin open until the final cleanup (closing
            //      mid-request causes SDK-backed servers to tear down
            //      the transport and throw "Not connected")
            $initRequest = [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    // capabilities MUST serialize as a JSON object ({}), not
                    // an array ([]). The MCP SDK zod schema validates against
                    // { type: object } and returns -32603 when given [].
                    'capabilities' => new \stdClass,
                    'clientInfo' => [
                        'name' => 'plos-automation',
                        'version' => '2.1.0',
                    ],
                ],
            ];

            $initializedNotification = [
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ];

            $toolsRequest = [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
                'params' => new \stdClass,
            ];

            // Helper: drain pipe until we see a JSON-RPC frame matching $expectId
            // (or any well-formed JSON-RPC frame when $expectId is null, used for
            // the server's banner-then-response pattern). Accumulates partial
            // reads into a buffer so multi-line stderr-prefix output does not
            // defeat parsing. Returns the raw JSON string or '' on timeout.
            $readJsonFrame = function ($stream, int $timeoutSeconds, ?int $expectId = null): string {
                $deadline = microtime(true) + $timeoutSeconds;
                $buffer = '';

                while (microtime(true) < $deadline) {
                    $read = [$stream];
                    $write = $except = [];
                    $remaining = max(0.1, $deadline - microtime(true));
                    $tvSec = (int) floor($remaining);
                    $tvUsec = (int) (($remaining - $tvSec) * 1_000_000);

                    $ready = @stream_select($read, $write, $except, $tvSec, $tvUsec);
                    if ($ready === false || $ready === 0) {
                        continue;
                    }

                    $chunk = stream_get_contents($stream);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    $buffer .= $chunk;

                    // Parse every complete newline-terminated frame in the buffer.
                    // Some MCP servers print a startup banner to stdout before
                    // the first JSON frame, so skip non-JSON lines.
                    while (($nlPos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $nlPos);
                        $buffer = substr($buffer, $nlPos + 1);
                        $trimmed = trim($line);
                        if ($trimmed === '' || $trimmed[0] !== '{') {
                            continue;
                        }
                        $decoded = json_decode($trimmed, true);
                        if (! is_array($decoded)) {
                            continue;
                        }
                        if ($expectId !== null && ($decoded['id'] ?? null) !== $expectId) {
                            continue;
                        }

                        return $trimmed;
                    }
                }

                return '';
            };

            // Step 1: send initialize and flush
            fwrite($pipes[0], json_encode($initRequest)."\n");
            fflush($pipes[0]);

            // Step 2: read initialize response before anything else is sent
            $initResponse = $readJsonFrame($pipes[1], 10, 1);
            if (empty($initResponse)) {
                $stderr = stream_get_contents($pipes[2]);
                throw new Exception("No init response from MCP server: {$serverName}. Stderr: ".substr((string) $stderr, 0, 200));
            }

            // Step 3: send notifications/initialized (no response expected)
            fwrite($pipes[0], json_encode($initializedNotification)."\n");
            fflush($pipes[0]);

            // Step 4: send tools/list and read its response
            fwrite($pipes[0], json_encode($toolsRequest)."\n");
            fflush($pipes[0]);
            $toolsResponse = $readJsonFrame($pipes[1], 10, 2);

            // Step 5: cleanup. stdin closes here (after the final response)
            // and ONLY here, so mid-request async server notifications do
            // not hit a torn-down transport.
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($process);
            proc_close($process);

            if (empty($toolsResponse)) {
                Log::warning("No tools response from MCP server: {$serverName}");

                return [];
            }

            // Parse JSON response
            $response = json_decode(trim($toolsResponse), true);

            if (! $response || ! isset($response['result']['tools'])) {
                Log::warning("Invalid tools response from MCP server: {$serverName}", [
                    'response' => $toolsResponse,
                ]);

                return [];
            }

            $tools = [];
            foreach ($response['result']['tools'] as $tool) {
                $tools[] = [
                    'name' => $tool['name'] ?? 'unnamed',
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['inputSchema'] ?? ['type' => 'object', 'properties' => []],
                ];
            }

            Log::info("Discovered {count} tools from MCP server: {$serverName}", [
                'count' => count($tools),
                'serverName' => $serverName,
            ]);

            return $tools;

        } catch (Exception $e) {
            Log::error("Failed to discover tools from MCP server: {$serverName}", [
                'error' => $e->getMessage(),
                'command' => isset($cmdParts) ? implode(' ', $cmdParts) : '',
            ]);

            return [];
        }
    }

    /**
     * Get static tool definitions for external MCP servers
     *
     * @param  string  $serverName  Server identifier
     * @return array Tool definitions
     */
    private function getStaticToolDefinitions(string $serverName): array
    {
        // Predefined tool schemas to avoid timeout issues with dynamic discovery
        // IMPORTANT: properties must be objects (stdClass), not arrays, for Ollama compatibility
        $definitions = [
            'nextcloud' => [
                ['name' => 'createNote', 'description' => 'Create a new note in Nextcloud Notes', 'parameters' => ['type' => 'object', 'properties' => (object) ['title' => ['type' => 'string'], 'content' => ['type' => 'string']]]],
                ['name' => 'searchNotes', 'description' => 'Search for notes by keyword', 'parameters' => ['type' => 'object', 'properties' => (object) ['query' => ['type' => 'string']]]],
                ['name' => 'getCalendarEvents', 'description' => 'Get calendar events', 'parameters' => ['type' => 'object', 'properties' => (object) ['calendar' => ['type' => 'string'], 'start' => ['type' => 'string'], 'end' => ['type' => 'string']]]],
                ['name' => 'createCalendarEvent', 'description' => 'Create a calendar event', 'parameters' => ['type' => 'object', 'properties' => (object) ['calendar' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'start' => ['type' => 'string'], 'end' => ['type' => 'string']]]],
            ],
            'thunderbird' => [
                ['name' => 'listFolders', 'description' => 'List all mbox folders', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]],
                ['name' => 'searchMessages', 'description' => 'Search email messages', 'parameters' => ['type' => 'object', 'properties' => (object) ['query' => ['type' => 'string'], 'folder' => ['type' => 'string']]]],
                ['name' => 'sendEmail', 'description' => 'Send email via Thunderbird', 'parameters' => ['type' => 'object', 'properties' => (object) ['to' => ['type' => 'string'], 'subject' => ['type' => 'string'], 'body' => ['type' => 'string']]]],
            ],
            'memory' => [
                ['name' => 'create_entities', 'description' => 'Create entities in knowledge graph', 'parameters' => ['type' => 'object', 'properties' => (object) ['entities' => ['type' => 'array']]]],
                ['name' => 'create_relations', 'description' => 'Create relationships between entities', 'parameters' => ['type' => 'object', 'properties' => (object) ['relations' => ['type' => 'array']]]],
                ['name' => 'read_graph', 'description' => 'Read knowledge graph data', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]],
            ],
            'sequential-thinking' => [
                ['name' => 'sequentialthinking', 'description' => 'Multi-step reasoning and problem-solving', 'parameters' => ['type' => 'object', 'properties' => (object) ['thought' => ['type' => 'string']]]],
            ],
            'time' => [
                ['name' => 'get_current_time', 'description' => 'Get current time in timezone', 'parameters' => ['type' => 'object', 'properties' => (object) ['timezone' => ['type' => 'string']]]],
                ['name' => 'convert_time', 'description' => 'Convert time between timezones', 'parameters' => ['type' => 'object', 'properties' => (object) ['time' => ['type' => 'string'], 'from_tz' => ['type' => 'string'], 'to_tz' => ['type' => 'string']]]],
            ],
            'filesystem' => [
                ['name' => 'read_file', 'description' => 'Read file contents', 'parameters' => ['type' => 'object', 'properties' => (object) ['path' => ['type' => 'string']]]],
                ['name' => 'write_file', 'description' => 'Write file contents', 'parameters' => ['type' => 'object', 'properties' => (object) ['path' => ['type' => 'string'], 'content' => ['type' => 'string']]]],
                ['name' => 'list_directory', 'description' => 'List directory contents', 'parameters' => ['type' => 'object', 'properties' => (object) ['path' => ['type' => 'string']]]],
            ],
            'serena' => [
                ['name' => 'check_onboarding_performed', 'description' => 'Check whether Serena project onboarding has been performed', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]],
                ['name' => 'initial_instructions', 'description' => 'Return Serena usage instructions for the active client', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]],
                ['name' => 'onboarding', 'description' => 'Analyze project structure and write Serena project memory', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]],
                ['name' => 'get_symbols_overview', 'description' => 'Get a symbol overview for a file or directory', 'parameters' => ['type' => 'object', 'properties' => (object) ['relative_path' => ['type' => 'string'], 'depth' => ['type' => 'integer'], 'max_answer_chars' => ['type' => 'integer']], 'required' => ['relative_path']]],
                ['name' => 'find_symbol', 'description' => 'Find symbols by name path using Serena language-server semantics', 'parameters' => ['type' => 'object', 'properties' => (object) ['name_path' => ['type' => 'string'], 'relative_path' => ['type' => 'string'], 'depth' => ['type' => 'integer'], 'include_body' => ['type' => 'boolean'], 'substring_matching' => ['type' => 'boolean'], 'max_answer_chars' => ['type' => 'integer']], 'required' => ['name_path']]],
                ['name' => 'find_referencing_symbols', 'description' => 'Find symbols that reference the requested symbol', 'parameters' => ['type' => 'object', 'properties' => (object) ['name_path' => ['type' => 'string'], 'relative_path' => ['type' => 'string'], 'max_answer_chars' => ['type' => 'integer']], 'required' => ['name_path', 'relative_path']]],
                ['name' => 'insert_after_symbol', 'description' => 'Insert content after a symbol definition', 'parameters' => ['type' => 'object', 'properties' => (object) ['name_path' => ['type' => 'string'], 'relative_path' => ['type' => 'string'], 'body' => ['type' => 'string']], 'required' => ['name_path', 'relative_path', 'body']]],
                ['name' => 'insert_before_symbol', 'description' => 'Insert content before a symbol definition', 'parameters' => ['type' => 'object', 'properties' => (object) ['name_path' => ['type' => 'string'], 'relative_path' => ['type' => 'string'], 'body' => ['type' => 'string']], 'required' => ['name_path', 'relative_path', 'body']]],
                ['name' => 'replace_symbol_body', 'description' => 'Replace the full body of a symbol definition', 'parameters' => ['type' => 'object', 'properties' => (object) ['name_path' => ['type' => 'string'], 'relative_path' => ['type' => 'string'], 'body' => ['type' => 'string']], 'required' => ['name_path', 'relative_path', 'body']]],
                ['name' => 'rename_symbol', 'description' => 'Rename a symbol using language-server refactoring', 'parameters' => ['type' => 'object', 'properties' => (object) ['name_path' => ['type' => 'string'], 'relative_path' => ['type' => 'string'], 'new_name' => ['type' => 'string']], 'required' => ['name_path', 'relative_path', 'new_name']]],
                ['name' => 'safe_delete_symbol', 'description' => 'Safely delete a symbol using semantic analysis', 'parameters' => ['type' => 'object', 'properties' => (object) ['name_path' => ['type' => 'string'], 'relative_path' => ['type' => 'string']], 'required' => ['name_path', 'relative_path']]],
                ['name' => 'list_memories', 'description' => 'List Serena memory files', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]],
                ['name' => 'read_memory', 'description' => 'Read a Serena memory file', 'parameters' => ['type' => 'object', 'properties' => (object) ['memory_name' => ['type' => 'string']], 'required' => ['memory_name']]],
                ['name' => 'write_memory', 'description' => 'Write or replace a Serena project memory file', 'parameters' => ['type' => 'object', 'properties' => (object) ['memory_name' => ['type' => 'string'], 'content' => ['type' => 'string']], 'required' => ['memory_name', 'content']]],
            ],
            'prompt-compressor' => [
                ['name' => 'prompt_token_count', 'description' => 'Estimate token, character, line, and word counts for text or an allowed local file.', 'parameters' => ['type' => 'object', 'properties' => (object) ['text' => ['type' => 'string'], 'path' => ['type' => 'string']]]],
                ['name' => 'compress_prompt', 'description' => 'Compress pasted prompt/context using extractive scoring or local Ollama.', 'parameters' => ['type' => 'object', 'properties' => (object) ['text' => ['type' => 'string'], 'target_tokens' => ['type' => 'number'], 'mode' => ['type' => 'string'], 'query' => ['type' => 'string'], 'method' => ['type' => 'string'], 'preserve_patterns' => ['type' => 'array'], 'include_stats' => ['type' => 'boolean']], 'required' => ['text']]],
                ['name' => 'compress_file', 'description' => 'Read an allowed local file and return compressed context.', 'parameters' => ['type' => 'object', 'properties' => (object) ['path' => ['type' => 'string'], 'max_bytes' => ['type' => 'number'], 'target_tokens' => ['type' => 'number'], 'mode' => ['type' => 'string'], 'query' => ['type' => 'string'], 'method' => ['type' => 'string'], 'preserve_patterns' => ['type' => 'array'], 'include_stats' => ['type' => 'boolean']], 'required' => ['path']]],
                ['name' => 'compress_diff', 'description' => 'Compress a git diff into changed-file stats and relevant hunks.', 'parameters' => ['type' => 'object', 'properties' => (object) ['diff' => ['type' => 'string'], 'target_tokens' => ['type' => 'number'], 'mode' => ['type' => 'string'], 'query' => ['type' => 'string'], 'method' => ['type' => 'string'], 'preserve_patterns' => ['type' => 'array'], 'include_stats' => ['type' => 'boolean']], 'required' => ['diff']]],
                ['name' => 'context_store', 'description' => 'Store large context outside chat and return a small id for later retrieval.', 'parameters' => ['type' => 'object', 'properties' => (object) ['text' => ['type' => 'string'], 'id' => ['type' => 'string'], 'metadata' => ['type' => 'string']], 'required' => ['text']]],
                ['name' => 'context_retrieve', 'description' => 'Retrieve stored context by id and return compressed output.', 'parameters' => ['type' => 'object', 'properties' => (object) ['id' => ['type' => 'string'], 'target_tokens' => ['type' => 'number'], 'mode' => ['type' => 'string'], 'query' => ['type' => 'string'], 'method' => ['type' => 'string'], 'preserve_patterns' => ['type' => 'array'], 'include_stats' => ['type' => 'boolean']], 'required' => ['id']]],
                ['name' => 'context_list', 'description' => 'List stored context ids and token estimates without returning full stored text.', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]],
            ],
            'nextcloud-files' => [
                ['name' => 'test-connection', 'description' => 'Test connection to Nextcloud server', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]],
                ['name' => 'list-files', 'description' => 'List files and directories in Nextcloud', 'parameters' => ['type' => 'object', 'properties' => (object) ['path' => ['type' => 'string', 'description' => 'Path to list (default: /)']]]],
                ['name' => 'create-directory', 'description' => 'Create a new directory in Nextcloud', 'parameters' => ['type' => 'object', 'properties' => (object) ['path' => ['type' => 'string']], 'required' => ['path']]],
                ['name' => 'delete-file', 'description' => 'Delete a file or directory from Nextcloud', 'parameters' => ['type' => 'object', 'properties' => (object) ['path' => ['type' => 'string']], 'required' => ['path']]],
                ['name' => 'upload-file', 'description' => 'Upload a file to Nextcloud', 'parameters' => ['type' => 'object', 'properties' => (object) ['remotePath' => ['type' => 'string'], 'content' => ['type' => 'string', 'description' => 'Base64 encoded file content']], 'required' => ['remotePath', 'content']]],
                ['name' => 'download-file', 'description' => 'Download a file from Nextcloud', 'parameters' => ['type' => 'object', 'properties' => (object) ['path' => ['type' => 'string']], 'required' => ['path']]],
                ['name' => 'create-share', 'description' => 'Create a share link for a file or directory', 'parameters' => ['type' => 'object', 'properties' => (object) ['path' => ['type' => 'string'], 'shareType' => ['type' => 'number', 'description' => 'Share type (0=user, 1=group, 3=public link, 4=email)'], 'permissions' => ['type' => 'number']], 'required' => ['path']]],
                ['name' => 'list-shares', 'description' => 'List existing shares', 'parameters' => ['type' => 'object', 'properties' => (object) ['path' => ['type' => 'string']]]],
                ['name' => 'delete-share', 'description' => 'Delete an existing share', 'parameters' => ['type' => 'object', 'properties' => (object) ['shareId' => ['type' => 'string']], 'required' => ['shareId']]],
                ['name' => 'move-file', 'description' => 'Move or rename a file or directory in Nextcloud', 'parameters' => ['type' => 'object', 'properties' => (object) ['sourcePath' => ['type' => 'string'], 'destinationPath' => ['type' => 'string'], 'overwrite' => ['type' => 'boolean']], 'required' => ['sourcePath', 'destinationPath']]],
                ['name' => 'copy-file', 'description' => 'Copy a file or directory in Nextcloud', 'parameters' => ['type' => 'object', 'properties' => (object) ['sourcePath' => ['type' => 'string'], 'destinationPath' => ['type' => 'string'], 'overwrite' => ['type' => 'boolean']], 'required' => ['sourcePath', 'destinationPath']]],
                ['name' => 'search-files', 'description' => 'Search for files and directories by name or content', 'parameters' => ['type' => 'object', 'properties' => (object) ['query' => ['type' => 'string'], 'path' => ['type' => 'string'], 'limit' => ['type' => 'number'], 'type' => ['type' => 'string', 'enum' => ['file', 'directory', 'all']]], 'required' => ['query']]],
                ['name' => 'get-file-versions', 'description' => 'Get version history of a file', 'parameters' => ['type' => 'object', 'properties' => (object) ['path' => ['type' => 'string']], 'required' => ['path']]],
                ['name' => 'restore-file-version', 'description' => 'Restore a specific version of a file', 'parameters' => ['type' => 'object', 'properties' => (object) ['path' => ['type' => 'string'], 'versionId' => ['type' => 'string']], 'required' => ['path', 'versionId']]],
            ],
            'everything' => [
                ['name' => 'echo', 'description' => 'Echo back the input message', 'parameters' => ['type' => 'object', 'properties' => (object) ['message' => ['type' => 'string']]]],
                ['name' => 'add', 'description' => 'Add two numbers', 'parameters' => ['type' => 'object', 'properties' => (object) ['a' => ['type' => 'number'], 'b' => ['type' => 'number']]]],
                ['name' => 'longRunningOperation', 'description' => 'Simulate a long-running operation', 'parameters' => ['type' => 'object', 'properties' => (object) ['duration' => ['type' => 'number', 'description' => 'Duration in seconds']]]],
                ['name' => 'sampleLLM', 'description' => 'Sample LLM interaction', 'parameters' => ['type' => 'object', 'properties' => (object) ['prompt' => ['type' => 'string']]]],
                ['name' => 'getTinyImage', 'description' => 'Get a tiny test image', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]],
            ],
        ];

        return $definitions[$serverName] ?? [];
    }

    /**
     * Call a tool on a specific MCP server
     *
     * @param  string  $server  Server identifier
     * @param  string  $tool  Tool name
     * @param  array  $params  Tool parameters
     * @return mixed Tool result
     */
    public function callTool(string $server, string $tool, array $params = [], ?int $timeout = null): mixed
    {
        // Use dynamic timeout if provided, otherwise use tool-specific defaults
        $effectiveTimeout = $timeout ?? $this->getToolTimeout($server, $tool);

        if ($this->logCalls) {
            Log::info('MCP Tool Call', [
                'server' => $server,
                'tool' => $tool,
                'params' => $params,
                'timeout' => $effectiveTimeout,
            ]);
        }

        if (! isset($this->config[$server])) {
            throw new Exception("MCP server not found: {$server}");
        }

        $serverConfig = $this->config[$server];

        if (! ($serverConfig['enabled'] ?? false)) {
            throw new Exception("MCP server is disabled: {$server}");
        }

        // 3b P02d + R2 (2026-04-19) policy gate — refuses:
        //   (a) internet-class / protected servers under offline profiles
        //       (per-server allowlist via trust_boundary + hybrid_profiles_allowed),
        //   (b) mutating TOOLS on an admitted server under offline_review
        //       (per-tool classification via config/offline_policy.mcp_tool_class_map).
        $policyDenial = $this->denyIfOfflinePolicyRefuses($server, $tool, $params);
        if ($policyDenial !== null) {
            throw new Exception($policyDenial);
        }

        $type = $serverConfig['type'] ?? 'external';

        if ($type === 'internal') {
            return $this->callInternalTool($server, $tool, $params);
        }

        return $this->callExternalTool($server, $serverConfig, $tool, $params, $effectiveTimeout);
    }

    /**
     * Consult OfflinePolicyService for a 3b profile-aware decision on this
     * MCP tool call. R2 (2026-04-19): now evaluates BOTH server admission
     * AND per-tool class — offline_review must refuse mutating tools even
     * on admitted servers.
     *
     * Returns null when allowed, or the denial reason when refused. Null if
     * the service cannot be resolved (no regression for tests that do not
     * bind the service).
     */
    private function denyIfOfflinePolicyRefuses(string $server, string $tool = '', array $params = []): ?string
    {
        try {
            $policy = app(\App\Services\OfflinePolicyService::class);
        } catch (\Throwable $e) {
            return null;
        }

        try {
            // R2 + Defect B (2026-04-19): surface `path` context for every
            // MCP tool that touches the local filesystem. The authoritative
            // map lives in config/offline_policy.local_fs_path_mcp_tools
            // keyed by `{server}.{tool}` (or wildcard `{server}.*`) with
            // a list of param names. Servers outside this map (nextcloud
            // WebDAV, joplin, thunderbird) use paths in a different
            // namespace and are NOT run through classifyPath().
            $context = [];
            $pathParamNames = $this->resolveLocalFsPathParams($server, $tool);
            foreach ($pathParamNames as $paramName) {
                if (! empty($params[$paramName]) && is_string($params[$paramName])) {
                    $context['path'] = $params[$paramName];
                    break;
                }
            }

            $decision = $tool !== ''
                ? $policy->evaluateMcpTool($server, $tool, $context)
                : $policy->evaluateMcpServer($server);
        } catch (\Throwable $e) {
            Log::warning('MCPRouter: offline policy evaluation failed — passing through', [
                'server' => $server,
                'tool' => $tool,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($decision->allowed) {
            return null;
        }

        Log::warning('MCPRouter: MCP call refused by offline policy', [
            'server' => $server,
            'tool' => $tool,
            'profile' => $decision->profile,
            'reason' => $decision->reason,
            'tool_class' => $decision->toolClass,
            'trust_boundary' => $decision->mcpTrustBoundary,
            'path_class' => $decision->pathClass,
        ]);

        $label = $tool !== '' ? "MCP tool '{$server}.{$tool}'" : "MCP server '{$server}'";

        return "{$label} refused by active profile '{$decision->profile}': {$decision->reason}";
    }

    /**
     * Defect B (2026-04-19): look up which param names carry a local-FS
     * path for this MCP tool. Falls back to the legacy server-level list
     * (`filesystem` with `path`) so old tests continue to work.
     *
     * @return list<string>
     */
    private function resolveLocalFsPathParams(string $server, string $tool): array
    {
        $map = (array) config('offline_policy.local_fs_path_mcp_tools', []);

        $exactKey = $server.'.'.$tool;
        if (array_key_exists($exactKey, $map) && is_array($map[$exactKey])) {
            return array_values($map[$exactKey]);
        }

        $wildcardKey = $server.'.*';
        if (array_key_exists($wildcardKey, $map) && is_array($map[$wildcardKey])) {
            return array_values($map[$wildcardKey]);
        }

        // Legacy server-level fallback for callers that have not migrated
        // to the tool-level map yet.
        $legacyServers = (array) config('offline_policy.local_fs_path_mcp_servers', []);
        if (in_array($server, $legacyServers, true)) {
            return ['path'];
        }

        return [];
    }

    /**
     * Get appropriate timeout for a specific tool
     *
     * Some tools like Puppeteer navigation need longer timeouts
     */
    private function getToolTimeout(string $server, string $tool): int
    {
        // Puppeteer tools need longer timeouts for page rendering
        if ($server === 'puppeteer') {
            return match ($tool) {
                'puppeteer_navigate' => 60,  // Page load can take time
                'puppeteer_evaluate' => 45,  // Script execution
                'puppeteer_screenshot' => 45, // Rendering
                default => 30,
            };
        }

        // Web research tools may need more time for API calls
        if ($server === 'web-research') {
            return 45;
        }

        if ($server === 'prompt-compressor') {
            return match ($tool) {
                'compress_prompt', 'compress_file', 'compress_diff', 'context_retrieve' => 120,
                default => 30,
            };
        }

        return $this->timeout;
    }

    /**
     * Call internal Laravel service tools
     */
    private function callInternalTool(string $server, string $tool, array $params): mixed
    {
        if ($server === 'plos') {
            return $this->callWorkflowTool($tool, $params);
        }

        if ($server === 'rag') {
            return $this->callRAGTool($tool, $params);
        }

        if ($server === 'nextcloud-calendar') {
            return $this->callNextcloudTool($tool, $params);
        }

        if ($server === 'nextcloud-contacts') {
            return $this->callNextcloudContactsTool($tool, $params);
        }

        if ($server === 'joplin-files') {
            return $this->callJoplinFilesTool($tool, $params);
        }

        if ($server === 'time') {
            return $this->callTimeTool($tool, $params);
        }

        if ($server === 'web-research') {
            return $this->callWebResearchTool($tool, $params);
        }

        if ($server === 'code-review') {
            return $this->callCodeReviewTool($tool, $params);
        }

        if ($server === 'repo-dev') {
            return $this->callRepoDevTool($tool, $params);
        }

        if ($server === 'searxng') {
            return $this->callSearXNGTool($tool, $params);
        }

        if ($server === 'genealogy') {
            return $this->callGenealogyTool($tool, $params);
        }

        throw new Exception("Unknown internal server: {$server}");
    }

    /**
     * Call workflow tools
     */
    private function callWorkflowTool(string $tool, array $params): array
    {
        $workflowService = app(\App\Services\WorkflowService::class);

        return match ($tool) {
            'workflow_list' => [
                'workflows' => $workflowService->getAllWorkflows($params['active_only'] ?? false),
            ],
            'workflow_get' => [
                'workflow' => $workflowService->getWorkflowByName($params['name']),
            ],
            'workflow_run' => [
                'execution' => $workflowService->executeWorkflow($params['name'], $params['input'] ?? []),
            ],
            'execution_list' => [
                'executions' => $workflowService->getExecutionHistory(
                    $params['workflow_id'] ?? null,
                    $params['limit'] ?? 50
                ),
            ],
            'execution_get' => [
                'execution' => $workflowService->getExecutionDetails($params['run_id']),
            ],
            'artisan_execute' => [
                'result' => $workflowService->executeArtisanCommand(
                    $params['command'],
                    $params['arguments'] ?? []
                ),
            ],
            'node_create' => [
                'node' => $workflowService->createNodeClass(
                    $params['name'],
                    $params['description']
                ),
            ],
            'schedule_list' => [
                'schedules' => $workflowService->getScheduledWorkflows(),
            ],
            'system_diagnostics' => [
                'diagnostics' => $workflowService->getSystemDiagnostics(),
            ],
            default => throw new Exception("Unknown workflow tool: {$tool}"),
        };
    }

    /**
     * Call RAG tools
     */
    private function callRAGTool(string $tool, array $params): array
    {
        $ragService = app(RAGService::class);

        return match ($tool) {
            'rag_search' => $this->formatRAGSearchResults(
                $ragService->search(
                    $params['query'],
                    $params['limit'] ?? 5,
                    $params['document_type'] ?? null
                )
            ),
            'rag_index' => [
                'document' => $ragService->indexDocument(
                    $params['type'],
                    $params['title'],
                    $params['content'],
                    $params['metadata'] ?? [],
                ),
            ],
            'rag_similar' => [
                'similar' => $ragService->findSimilar(
                    $params['document_id'],
                    $params['limit'] ?? 5
                ),
            ],
            default => throw new Exception("Unknown RAG tool: {$tool}"),
        };
    }

    /**
     * Format RAG search results for AI consumption
     */
    private function formatRAGSearchResults(array $results): array
    {
        if (empty($results)) {
            return ['documents' => [], 'message' => 'No documents found in knowledge base'];
        }

        $formatted = [];
        foreach ($results as $index => $result) {
            $doc = $result['document'];
            $similarity = round($result['similarity'] * 100, 1);

            // Truncate content for context
            $content = $doc->content ?? '';
            if (strlen($content) > 1500) {
                $content = substr($content, 0, 1500).'... [truncated]';
            }

            $formatted[] = [
                'ref' => 'RAG-'.($index + 1),
                'title' => $doc->title ?? 'Untitled',
                'type' => $doc->document_type ?? 'unknown',
                'relevance' => "{$similarity}%",
                'content' => $content,
            ];
        }

        return [
            'documents' => $formatted,
            'count' => count($formatted),
        ];
    }

    /**
     * Call Nextcloud calendar tools
     */
    private function callNextcloudTool(string $tool, array $params): array
    {
        $nextcloudService = app(NextcloudService::class);

        return match ($tool) {
            'get_calendar_events' => [
                'events' => $nextcloudService->getCalendarEvents(
                    $params['calendar'] ?? 'personal',
                    $params['start'] ?? null,
                    $params['end'] ?? null
                ),
            ],
            'list_calendars' => [
                'calendars' => $nextcloudService->getCalendars(),
            ],
            default => throw new Exception("Unknown Nextcloud tool: {$tool}"),
        };
    }

    /**
     * Call Nextcloud contacts tools
     */
    private function callNextcloudContactsTool(string $tool, array $params): array
    {
        $contactsService = app(\App\Services\NextcloudContactsService::class);

        return match ($tool) {
            'get_address_books' => [
                'addressBooks' => $contactsService->getAddressBooks(),
            ],
            'get_contacts' => [
                'contacts' => $contactsService->getContacts(
                    $params['address_book'] ?? null,
                    $params['limit'] ?? 100
                ),
            ],
            'search_contacts' => [
                'results' => $contactsService->searchContacts(
                    $params['query'],
                    $params['address_book'] ?? null
                ),
            ],
            'get_contact_stats' => [
                'stats' => $contactsService->getStats(),
            ],
            default => throw new Exception("Unknown Nextcloud contacts tool: {$tool}"),
        };
    }

    /**
     * Call Joplin Files tools
     */
    private function callJoplinFilesTool(string $tool, array $params): array
    {
        $joplinService = app(\App\Services\JoplinFilesService::class);

        return match ($tool) {
            'joplin_search' => [
                'results' => $joplinService->searchNotes(
                    $params['query'],
                    $params['limit'] ?? 10
                ),
            ],
            'joplin_get_note' => [
                'note' => $joplinService->getNote($params['note_id']),
            ],
            'joplin_list_notebooks' => [
                'notebooks' => $joplinService->getNotebooks(),
            ],
            'joplin_get_notebook' => [
                'notes' => $joplinService->getNotesInNotebook($params['notebook_id']),
            ],
            'joplin_get_resource' => [
                'resource' => $joplinService->getResource($params['resource_id']),
            ],
            'joplin_status' => [
                'status' => [
                    'total_files' => count($joplinService->listNotes()),
                    'service' => 'active',
                    'source' => 'Nextcloud sync (WebDAV)',
                ],
            ],
            default => throw new Exception("Unknown Joplin Files tool: {$tool}"),
        };
    }

    /**
     * Call time tools
     */
    private function callTimeTool(string $tool, array $params): array
    {
        $timeService = app(\App\Services\TimeService::class);

        return match ($tool) {
            'get_current_time' => $timeService->get_current_time($params),
            'convert_time' => $timeService->convert_time($params),
            default => throw new Exception("Unknown time tool: {$tool}"),
        };
    }

    /**
     * Call web research tools
     */
    private function callWebResearchTool(string $tool, array $params): array
    {
        $webResearchService = app(\App\Services\WebResearchService::class);

        return match ($tool) {
            'web_search' => $this->formatWebSearchResults(
                $webResearchService->research(
                    $params['query'],
                    ['max_sources' => $params['max_results'] ?? 10]
                )
            ),
            'web_search_parallel' => $this->formatWebSearchResults(
                $webResearchService->parallelSearch(
                    $params['query'],
                    $params['max_results'] ?? 15
                )
            ),
            'discover_sources' => [
                'sources' => $webResearchService->discoverSourcesForTopic($params['topic']),
            ],
            'get_engine_status' => [
                'engines' => $webResearchService->getEngineStatus(),
            ],
            'scrape_page' => [
                'content' => $this->scrapePage(
                    $params['url'],
                    $params['extract_text'] ?? true
                ),
            ],
            'add_source' => [
                'source_id' => $webResearchService->addSource($params),
            ],
            default => throw new Exception("Unknown web-research tool: {$tool}"),
        };
    }

    /**
     * Call code review tools
     */
    private function callCodeReviewTool(string $tool, array $params): array
    {
        $codeReviewService = app(\App\Services\CodeReviewService::class);

        return match ($tool) {
            'code_review' => $codeReviewService->reviewCode(
                $params['code'],
                $params['language'],
                $params['check_types'] ?? ['security', 'bugs', 'best_practices']
            ),
            'code_review_file' => $codeReviewService->reviewFile(
                $params['file_path'],
                $params['check_types'] ?? ['security', 'bugs', 'best_practices']
            ),
            'code_review_diff' => $codeReviewService->reviewDiff(
                $params['diff'],
                $params['context'] ?? null
            ),
            'code_suggest_improvements' => $codeReviewService->suggestImprovements(
                $params['code'],
                $params['language'],
                $params['focus'] ?? 'all'
            ),
            default => throw new Exception("Unknown code-review tool: {$tool}"),
        };
    }

    /**
     * Call repo-local development tools
     */
    private function callRepoDevTool(string $tool, array $params): array
    {
        $repoDevService = app(\App\Services\RepoDevMCPService::class);

        return match ($tool) {
            'find_repo_files' => $repoDevService->findRepoFiles(
                $params['pattern'],
                $params['path'] ?? '.',
                $params['limit'] ?? 50,
            ),
            'search_repo' => $repoDevService->searchRepo(
                $params['query'],
                $params['path'] ?? '.',
                $params['limit'] ?? 50,
            ),
            'list_repo_directory' => $repoDevService->listRepoDirectory(
                $params['path'] ?? '.',
                (bool) ($params['recursive'] ?? false),
                $params['limit'] ?? 200,
            ),
            'read_repo_file' => $repoDevService->readRepoFile(
                $params['path'],
                $params['start_line'] ?? null,
                $params['end_line'] ?? null,
                $params['max_lines'] ?? 300,
            ),
            'write_repo_file' => $repoDevService->writeRepoFile(
                $params['path'],
                $params['content'],
                (bool) ($params['create_directories'] ?? false),
            ),
            'apply_repo_patch' => $repoDevService->applyRepoPatch(
                $params['patch'],
                (bool) ($params['check_only'] ?? false),
            ),
            'run_verification' => $repoDevService->runVerification(
                $params['runner'],
                $params['target'] ?? null,
                $params['filter'] ?? null,
                (int) ($params['timeout_seconds'] ?? 120),
            ),
            'list_routes' => $repoDevService->listRoutes(
                $params['scope'] ?? 'frontend',
                $params['filter'] ?? null,
            ),
            default => throw new Exception("Unknown repo-dev tool: {$tool}"),
        };
    }

    /**
     * Call SearXNG tools
     */
    private function callSearXNGTool(string $tool, array $params): array
    {
        $searxngService = app(\App\Services\SearXNGMCPService::class);

        return match ($tool) {
            'searxng_search' => $searxngService->searxng_search(
                $params['query'],
                $params['max_results'] ?? 10,
                $params['language'] ?? 'en',
                $params['time_range'] ?? ''
            ),
            'searxng_images' => $searxngService->searxng_images(
                $params['query'],
                $params['max_results'] ?? 20,
                $params['language'] ?? 'en'
            ),
            'searxng_news' => $searxngService->searxng_news(
                $params['query'],
                $params['max_results'] ?? 10,
                $params['language'] ?? 'en',
                $params['time_range'] ?? 'week'
            ),
            'searxng_status' => $searxngService->searxng_status(),
            default => throw new Exception("Unknown SearXNG tool: {$tool}"),
        };
    }

    /**
     * Call genealogy tools
     */
    private function callGenealogyTool(string $tool, array $params): array
    {
        $service = app(\App\Services\Genealogy\GenealogyMCPService::class);

        return match ($tool) {
            'gedcom_parse' => $service->gedcom_parse(
                $params['file_path'],
                $params['preview_only'] ?? false
            ),
            'gedcom_export' => $service->gedcom_export(
                $params['tree_id'],
                $params['include_living'] ?? false,
                $params['include_media'] ?? true
            ),
            'tree_search' => $service->tree_search(
                $params['query'],
                $params['type'] ?? 'all',
                $params['tree_id'] ?? null,
                $params['limit'] ?? 20
            ),
            'person_research' => $service->person_research(
                $params['person_id'],
                $params['focus'] ?? 'general'
            ),
            'tree_stats' => $service->tree_stats($params['tree_id']),
            'source_extract' => $service->source_extract(
                $params['tree_id'],
                $params['person_id'] ?? null,
                $params['limit'] ?? 50
            ),
            default => throw new Exception("Unknown genealogy tool: {$tool}"),
        };
    }

    /**
     * Format web search results for AI consumption
     */
    private function formatWebSearchResults(array $searchResults): array
    {
        $results = $searchResults['results'] ?? [];

        if (empty($results)) {
            return [
                'pages' => [],
                'message' => 'No web results found. Web search may have failed.',
                'success' => false,
            ];
        }

        $formatted = [];
        foreach ($results as $index => $result) {
            $formatted[] = [
                'ref' => 'WEB-'.($index + 1),
                'title' => $result['title'] ?? 'Untitled',
                'url' => $result['url'] ?? '',
                'snippet' => $result['snippet'] ?? '',
            ];
        }

        return [
            'pages' => $formatted,
            'count' => count($formatted),
            'success' => true,
        ];
    }

    /**
     * Scrape a page using Puppeteer and extract content
     */
    private function scrapePage(string $url, bool $extractText = true): array
    {
        try {
            // Navigate to the URL
            $this->callTool('puppeteer', 'puppeteer_navigate', [
                'url' => $url,
                'allowDangerous' => true,
                'launchOptions' => [
                    'headless' => true,
                    'args' => ['--no-sandbox', '--disable-setuid-sandbox'],
                ],
            ]);

            // Wait for page load
            usleep(2000000); // 2 seconds

            // Extract content
            $script = $extractText ? <<<'JS'
                (() => {
                    // Remove scripts, styles, nav, footer
                    const removeSelectors = ['script', 'style', 'nav', 'footer', 'header', 'aside', '.sidebar', '.menu', '.advertisement', '.ad'];
                    removeSelectors.forEach(sel => {
                        document.querySelectorAll(sel).forEach(el => el.remove());
                    });

                    // Get main content
                    const main = document.querySelector('main, article, .content, .main-content, #content, #main') || document.body;
                    const title = document.title || '';
                    const text = main.innerText.trim();

                    // Get meta description
                    const metaDesc = document.querySelector('meta[name="description"]')?.content || '';

                    // Get publish date if available
                    const dateEl = document.querySelector('time, .date, .published, [datetime]');
                    const publishDate = dateEl?.getAttribute('datetime') || dateEl?.textContent || '';

                    return JSON.stringify({
                        title: title,
                        description: metaDesc,
                        publish_date: publishDate,
                        content: text.substring(0, 10000), // Limit content size
                        word_count: text.split(/\s+/).length
                    });
                })()
            JS : <<<'JS'
                (() => {
                    return JSON.stringify({
                        title: document.title,
                        html: document.body.innerHTML.substring(0, 50000)
                    });
                })()
            JS;

            $result = $this->callTool('puppeteer', 'puppeteer_evaluate', ['script' => $script]);

            // Parse result
            if (isset($result['content']) && is_array($result['content'])) {
                foreach ($result['content'] as $item) {
                    if (isset($item['text'])) {
                        return json_decode($item['text'], true) ?? ['error' => 'Failed to parse page content'];
                    }
                }
            }

            return ['error' => 'No content extracted'];

        } catch (Exception $e) {
            Log::error('MCPRouter: Page scrape failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => $e->getMessage(),
                'url' => $url,
            ];
        }
    }

    /**
     * Call external MCP server tools via stdio
     *
     * Implements MCP protocol:
     * 1. Start process with stdio pipes
     * 2. Send initialize request
     * 3. Send tool call request
     * 4. Parse and return result
     */
    private function callExternalTool(string $serverName, array $serverConfig, string $tool, array $params, int $timeout = 30): mixed
    {
        $command = $serverConfig['command'];
        $args = $serverConfig['args'] ?? [];

        // Ensure PATH includes npm global bin directory for npx-based servers
        $npmGlobalBin = $this->resolveRuntimeEnvValue('HOME').'/.npm-global/bin';
        $systemPath = $this->resolveRuntimeEnvValue('PATH') ?: '/usr/bin:/bin:/usr/local/bin';

        // Start with server-specific env (from config), then add system PATH
        $env = $serverConfig['env'] ?? [];

        // Always set PATH from system PATH (don't use $_ENV as it contains .env vars, not system env)
        $env['PATH'] = $systemPath;

        // Add npm global bin to PATH if not already there
        if (! str_contains($env['PATH'], $npmGlobalBin)) {
            $env['PATH'] = $npmGlobalBin.':'.$env['PATH'];
        }

        // Build command
        $cmdParts = array_merge([$command], $args);
        $cmdString = implode(' ', array_map('escapeshellarg', $cmdParts));

        // Debug: log the command being executed
        if ($this->logCalls) {
            Log::info("MCP Command: {$cmdString}", [
                'server' => $serverName,
                'PATH' => $env['PATH'] ?? 'not set',
            ]);
        }

        // Setup process pipes for stdio communication
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        // Start MCP server process
        $process = proc_open($cmdParts, $descriptors, $pipes, base_path(), $env);

        if (! is_resource($process)) {
            throw new Exception("Failed to start MCP server: {$serverName}");
        }

        try {
            stream_set_blocking($pipes[1], false); // Non-blocking stdout
            stream_set_blocking($pipes[2], false); // Non-blocking stderr
            $startupStdout = '';
            $startupStderr = '';

            // Initial wait for server startup (uvx/npx need time to download/setup)
            // Check if command is uvx or npx and give it more time
            $initialDelay = 500000; // 500ms default
            $command = $serverConfig['command'] ?? '';

            if (strpos($command, 'uvx') !== false) {
                $initialDelay = 5000000; // 5 seconds for uvx

                // Additional polling check for uvx readiness
                $maxWait = 10; // 10 seconds max
                $waited = 0;
                while ($waited < $maxWait) {
                    $status = proc_get_status($process);
                    if ($status && $status['running']) {
                        // Check if stderr has any output (indicates uvx is still initializing)
                        $stderr = stream_get_contents($pipes[2]);
                        if ($stderr !== false && $stderr !== '') {
                            $startupStderr .= $stderr;
                        }
                        if (empty($stderr) || strpos($stderr, 'Installed') !== false) {
                            // Server likely ready
                            break;
                        }
                    }
                    $read = [$pipes[1], $pipes[2]];
                    $write = $except = [];
                    $changed = @stream_select($read, $write, $except, 0, 500000);
                    if ($changed > 0) {
                        $stdout = stream_get_contents($pipes[1]);
                        if ($stdout !== false && $stdout !== '') {
                            $startupStdout .= $stdout;
                        }

                        $stderr = stream_get_contents($pipes[2]);
                        if ($stderr !== false && $stderr !== '') {
                            $startupStderr .= $stderr;
                        }
                    }
                    $waited += 0.5;
                }
            } elseif (strpos($command, 'npx') !== false) {
                // npx also needs time to download/prepare packages on first run
                $initialDelay = 5000000; // 5 seconds for npx (increased from 3s)

                // Poll for npx readiness with detailed error capture
                $maxWait = 15; // 15 seconds max
                $waited = 0;
                while ($waited < $maxWait) {
                    $status = proc_get_status($process);

                    if (! $status || ! $status['running']) {
                        // Process died - capture stderr for debugging
                        $stderr = $startupStderr.(stream_get_contents($pipes[2]) ?: '');
                        $stdout = $startupStdout.(stream_get_contents($pipes[1]) ?: '');

                        $errorMsg = "MCP server process died during startup: {$serverName}\n";
                        if (! empty($stderr)) {
                            $errorMsg .= 'STDERR: '.$stderr."\n";
                        }
                        if (! empty($stdout)) {
                            $errorMsg .= 'STDOUT: '.$stdout."\n";
                        }
                        $errorMsg .= 'Exit code: '.($status['exitcode'] ?? 'unknown');

                        throw new Exception($errorMsg);
                    }

                    // Check if we've received any stdout (indicates server is responding)
                    $stdout = stream_get_contents($pipes[1]);
                    if (! empty($stdout) && strlen($stdout) > 10) {
                        // Preserve already-emitted protocol output so the init/tool read loop sees it.
                        $startupStdout .= $stdout;
                        break;
                    }

                    $read = [$pipes[1], $pipes[2]];
                    $write = $except = [];
                    $changed = @stream_select($read, $write, $except, 0, 500000);
                    if ($changed > 0) {
                        $stdout = stream_get_contents($pipes[1]);
                        if ($stdout !== false && $stdout !== '') {
                            $startupStdout .= $stdout;
                        }

                        $stderr = stream_get_contents($pipes[2]);
                        if ($stderr !== false && $stderr !== '') {
                            $startupStderr .= $stderr;
                        }
                    }
                    $waited += 0.5;
                }
            } else {
                $read = [$pipes[1], $pipes[2]];
                $write = $except = [];
                $changed = @stream_select($read, $write, $except, 0, $initialDelay);
                if ($changed > 0) {
                    $stdout = stream_get_contents($pipes[1]);
                    if ($stdout !== false && $stdout !== '') {
                        $startupStdout .= $stdout;
                    }

                    $stderr = stream_get_contents($pipes[2]);
                    if ($stderr !== false && $stderr !== '') {
                        $startupStderr .= $stderr;
                    }
                }
            }

            // Send initialize request
            $initRequest = [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => [],
                    'clientInfo' => [
                        'name' => 'plos-automation',
                        'version' => '2.1.0',
                    ],
                ],
            ];

            // Attempt to write with error handling for broken pipe
            $written = @fwrite($pipes[0], json_encode($initRequest)."\n");
            if ($written === false) {
                throw new Exception('Failed to write to MCP server stdin (broken pipe)');
            }
            fflush($pipes[0]);

            // Wait briefly for the init response without sleeping blindly.
            $read = [$pipes[1]];
            $write = $except = [];
            $changed = @stream_select($read, $write, $except, 0, 500000);
            $initResponse = $startupStdout;
            if ($changed > 0) {
                $initResponse .= stream_get_contents($pipes[1]) ?: '';
            }
            $startupStdout = '';

            // MCP spec: after receiving the initialize response the client
            // MUST send a notifications/initialized notification before any
            // other request. Without it, the server treats its transport as
            // partially-connected — server → client notifications then throw
            // "Not connected" from SDK protocol.js and kill the subprocess
            // mid-request (reproduced 2026-04-18 against
            // @modelcontextprotocol/server-puppeteer 2025.5.12 / SDK 1.0.1).
            $initializedNotification = [
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ];
            fwrite($pipes[0], json_encode($initializedNotification)."\n");
            fflush($pipes[0]);

            // Send tool call request
            // Convert empty array to empty object for JSON encoding
            $arguments = empty($params) ? new \stdClass : $params;

            $toolRequest = [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => $tool,
                    'arguments' => $arguments,
                ],
            ];

            fwrite($pipes[0], json_encode($toolRequest)."\n");
            fflush($pipes[0]);
            // Do NOT close stdin here. Closing it signals EOF to the MCP
            // server's stdio transport; server-puppeteer (and other SDK-
            // backed servers) treat that as a disconnect and tear down
            // the outgoing side of the transport. Any async handler that
            // then calls server.notification — e.g. server-puppeteer's
            // page.on("console", ...) firing when chrome emits early
            // warnings — throws "Not connected" from SDK protocol.js,
            // which is an uncaught exception that kills the server
            // mid-request and produces the "MCP server exited before
            // tool response" symptom. Stdin is closed in the finally
            // cleanup block after we have the response.

            // Read tool response with a wall-clock deadline so idle pipes do not busy-poll.
            $deadline = microtime(true) + $timeout;
            $toolResponse = $initResponse;
            $toolStderr = $startupStderr;

            while (microtime(true) < $deadline) {
                $remaining = max(0.0, $deadline - microtime(true));
                $seconds = (int) floor($remaining);
                $microseconds = (int) (($remaining - $seconds) * 1_000_000);
                $read = [$pipes[1], $pipes[2]];
                $write = $except = [];
                $changed = @stream_select($read, $write, $except, $seconds, $microseconds);

                if ($changed === false) {
                    break;
                }

                if ($changed > 0) {
                    foreach ($read as $stream) {
                        $chunk = stream_get_contents($stream);
                        if ($chunk === false || $chunk === '') {
                            continue;
                        }

                        if ($stream === $pipes[1]) {
                            $toolResponse .= $chunk;

                            // Check if we have complete JSON response
                            $lines = explode("\n", $toolResponse);
                            foreach ($lines as $line) {
                                $trimmed = trim($line);
                                if (! empty($trimmed) && $trimmed[0] === '{') {
                                    $decoded = json_decode($trimmed, true);
                                    if ($decoded && isset($decoded['id']) && $decoded['id'] == 2) {
                                        // Found our tool response
                                        fclose($pipes[1]);
                                        fclose($pipes[2]);
                                        proc_terminate($process);
                                        proc_close($process);

                                        if (isset($decoded['error'])) {
                                            throw new Exception('MCP tool error: '.($decoded['error']['message'] ?? 'Unknown error'));
                                        }

                                        return $decoded['result'] ?? [];
                                    }
                                }
                            }
                        } else {
                            $toolStderr .= $chunk;
                        }
                    }
                }

                $status = proc_get_status($process);
                if (! (($status['running'] ?? false) === true) && feof($pipes[1]) && feof($pipes[2])) {
                    $stderrMessage = trim($toolStderr);
                    if ($stderrMessage !== '') {
                        throw new Exception('MCP server exited before tool response: '.mb_substr($stderrMessage, 0, 500));
                    }
                    break;
                }
            }

            throw new Exception("Timeout waiting for MCP tool response from: {$serverName}");
        } finally {
            // Cleanup
            if (is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    /**
     * Clear tool catalog cache (both static and dynamic mode variants).
     */
    public function clearCache(): void
    {
        Cache::forget('mcp:tool_catalog');          // legacy fixed key
        Cache::forget('mcp:tool_catalog:static');   // dynamic_discovery_enabled=false
        Cache::forget('mcp:tool_catalog:dynamic');  // dynamic_discovery_enabled=true
        Cache::forget($this->toolCatalogCacheKey(false));
        Cache::forget($this->toolCatalogCacheKey(true));
    }

    /**
     * Cache key for getAvailableTools(). The dynamic-discovery flag changes
     * the catalog shape (static schemas vs. live-discovered schemas), so
     * each mode gets its own slot and cannot shadow the other.
     */
    private function toolCatalogCacheKey(?bool $dynamicDiscoveryEnabled = null): string
    {
        $mode = ($dynamicDiscoveryEnabled ?? (bool) config('mcp.dynamic_discovery_enabled', false)) ? 'dynamic' : 'static';
        $fingerprint = md5(json_encode($this->config, JSON_UNESCAPED_SLASHES) ?: '[]');

        return "mcp:tool_catalog:{$mode}:{$fingerprint}";
    }

    /**
     * Get MCP server status
     */
    public function getServerStatus(string $serverName): array
    {
        if (! isset($this->config[$serverName])) {
            return [
                'available' => false,
                'enabled' => false,
                'error' => 'Server not found',
                'description' => 'Unknown server',
                'tools' => 0,
            ];
        }

        $serverConfig = $this->config[$serverName];
        $enabled = $serverConfig['enabled'] ?? false;
        $description = $serverConfig['description'] ?? '';
        $tools = $serverConfig['tools'] ?? 0;
        $type = $serverConfig['type'] ?? 'external';

        $status = [
            'enabled' => $enabled,
            'description' => $description,
            'tools' => $tools,
            'type' => $type,
        ];

        if (! $enabled) {
            $status['available'] = false;
            $status['error'] = 'Server disabled';

            return $status;
        }

        if ($type === 'internal') {
            $status['available'] = true;

            return $status;
        }

        // For external servers, check if executable exists
        $command = $serverConfig['command'];
        $available = ProcessFacade::run(['which', $command])->successful();

        $status['available'] = $available;
        $status['command'] = $command;

        if (! $available) {
            $status['error'] = "Command '{$command}' not found";
        }

        return $status;
    }

    /**
     * Get all servers status
     */
    public function getAllServersStatus(): array
    {
        $status = [];

        foreach ($this->config as $serverName => $serverConfig) {
            $status[$serverName] = $this->getServerStatus($serverName);
        }

        return $status;
    }

    private function resolveRuntimeEnvValue(?string $key): string
    {
        if (! $key) {
            return '';
        }

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        $envValue = $_ENV[$key] ?? null;
        if (is_string($envValue) && $envValue !== '') {
            return $envValue;
        }

        $serverValue = $_SERVER[$key] ?? null;
        if (is_string($serverValue) && $serverValue !== '') {
            return $serverValue;
        }

        return '';
    }
}
