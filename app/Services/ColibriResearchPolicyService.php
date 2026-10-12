<?php

namespace App\Services;

use App\Engine\MCPRouter;
use Throwable;

class ColibriResearchPolicyService
{
    public const POLICY_KEY = 'ai.colibri_research_canary_policy';

    public const LOCAL_POLICY_KEY = 'ai.colibri_local_research_canary_policy';

    public const SCHEMA = 'plos.colibri_research_policy.v1';

    public const PUBLIC_WEB_PACK = 'public_web_read_only';

    public const LOCAL_OFFLINE_PACK = 'local_offline_read_only';

    private const PACK_CONTRACTS = [
        self::PUBLIC_WEB_PACK => [
            'policy_key' => self::POLICY_KEY,
            'offline_policy_profile' => 'hybrid_review',
            'requires_internet' => true,
            'requires_searxng_endpoint' => true,
            'local_offline_supported' => false,
            'internet_requires_public_input' => true,
            'supported_tools' => [
                'searxng.searxng_search',
                'searxng.searxng_news',
                'searxng.searxng_status',
            ],
            'tool_classes' => ['external-network'],
            'server_write_scopes' => ['none'],
        ],
        self::LOCAL_OFFLINE_PACK => [
            'policy_key' => self::LOCAL_POLICY_KEY,
            'offline_policy_profile' => 'offline_review',
            'requires_internet' => false,
            'requires_searxng_endpoint' => false,
            'local_offline_supported' => true,
            'internet_requires_public_input' => false,
            'supported_tools' => [
                'rag.rag_search',
                'rag.rag_similar',
                'rag.rag_read',
            ],
            'tool_classes' => ['read'],
            // The RAG server also owns rag_index, so its coarse server scope is
            // plos_data. Exact tool admission below still permits only the
            // three read-class operations in supported_tools.
            'server_write_scopes' => ['plos_data'],
        ],
    ];

    private const DEFAULT_POLICY = [
        'version' => 1,
        'status' => 'not_configured',
        'execution_enabled' => false,
        'instance_id' => 'colibri_87',
        'model_role' => 'deep_offline',
        'capability_pack' => self::PUBLIC_WEB_PACK,
        'requires_internet' => true,
        'requires_searxng_endpoint' => true,
        'local_offline_supported' => false,
        'offline_policy_profile' => 'hybrid_review',
        'allowed_tools' => [],
        'max_tools_advertised' => 4,
        'max_iterations' => 4,
        'max_tool_calls' => 4,
        'max_result_bytes' => 12000,
        'max_total_result_bytes' => 32000,
        'max_search_query_chars' => 500,
        'max_search_results' => 10,
        'max_document_chars' => 6000,
        'max_prompt_chars' => 4000,
        'max_output_tokens' => 384,
        'max_sidecar_depth' => 0,
        'internet_requires_public_input' => true,
        'writes_allowed' => false,
        'fallback_allowed' => false,
    ];

    private const LOCAL_DEFAULT_POLICY = [
        'version' => 1,
        'status' => 'not_configured',
        'execution_enabled' => false,
        'instance_id' => 'colibri_87',
        'model_role' => 'deep_offline',
        'capability_pack' => self::LOCAL_OFFLINE_PACK,
        'requires_internet' => false,
        'requires_searxng_endpoint' => false,
        'local_offline_supported' => true,
        'offline_policy_profile' => 'offline_review',
        'allowed_tools' => [],
        'max_tools_advertised' => 3,
        'max_iterations' => 4,
        'max_tool_calls' => 4,
        'max_result_bytes' => 12000,
        'max_total_result_bytes' => 32000,
        'max_search_query_chars' => 500,
        'max_search_results' => 10,
        'max_document_chars' => 6000,
        'max_prompt_chars' => 4000,
        'max_output_tokens' => 384,
        'max_sidecar_depth' => 0,
        'internet_requires_public_input' => false,
        'writes_allowed' => false,
        'fallback_allowed' => false,
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly MCPRouter $mcpRouter,
        private readonly OfflinePolicyService $offlinePolicy,
        private readonly MCPToolInvocationValidatorService $invocationValidator,
        private readonly MCPToolResultValidatorService $schemaValidator,
    ) {}

    /** @return array<string,mixed> */
    public function plan(
        bool $publicWebConfirmed = false,
        string $capabilityPack = self::PUBLIC_WEB_PACK,
    ): array {
        $policy = $this->runtimePolicy($capabilityPack);
        $catalog = $this->catalog($publicWebConfirmed, $capabilityPack);

        return [
            'schema' => self::SCHEMA,
            'status' => $policy['status'],
            'execution_enabled' => $policy['execution_enabled'],
            'execution_allowed' => $policy['execution_enabled']
                && ($policy['internet_requires_public_input'] === false || $publicWebConfirmed),
            'instance_id' => $policy['instance_id'],
            'model_role' => $policy['model_role'],
            'capability_pack' => $policy['capability_pack'],
            'requires_internet' => $policy['requires_internet'],
            'requires_searxng_endpoint' => $policy['requires_searxng_endpoint'],
            'local_offline_supported' => $policy['local_offline_supported'],
            'offline_policy_profile' => $policy['offline_policy_profile'],
            'public_web_confirmed' => $publicWebConfirmed,
            'internet_requires_public_input' => $policy['internet_requires_public_input'],
            'writes_allowed' => false,
            'fallback_allowed' => false,
            'tool_count' => count($catalog),
            'tools' => array_map(static fn (array $tool): array => [
                'function_name' => $tool['function_name'],
                'server' => $tool['server'],
                'tool' => $tool['name'],
                'tool_class' => $tool['tool_class'],
                'write_scope' => $tool['write_scope'],
            ], $catalog),
            'limits' => [
                'max_tools_advertised' => $policy['max_tools_advertised'],
                'max_iterations' => $policy['max_iterations'],
                'max_tool_calls' => $policy['max_tool_calls'],
                'max_result_bytes' => $policy['max_result_bytes'],
                'max_total_result_bytes' => $policy['max_total_result_bytes'],
                'max_search_query_chars' => $policy['max_search_query_chars'],
                'max_search_results' => $policy['max_search_results'],
                'max_document_chars' => $policy['max_document_chars'],
                'max_prompt_chars' => $policy['max_prompt_chars'],
                'max_output_tokens' => $policy['max_output_tokens'],
                'max_sidecar_depth' => $policy['max_sidecar_depth'],
            ],
            'policy_source' => 'system_configs.'.$this->policyKey($capabilityPack),
            'tool_contract_source' => 'MCPRouter advertised catalog plus OfflinePolicyService',
            'availability_contract' => [
                'colibri_requires_87_running' => true,
                'home_lan_required' => false,
                'host_only_route_supported' => true,
                'fresh_public_web_requires_internet' => $policy['requires_internet'],
                'fresh_public_web_requires_reachable_searxng' => $policy['requires_searxng_endpoint'],
                'local_sources_require_published_rag_rows' => $capabilityPack === self::LOCAL_OFFLINE_PACK,
                'unavailable_behavior' => 'fail_closed_no_fallback',
            ],
        ];
    }

    /**
     * Return only exact, advertised, non-writing tools that the hybrid review
     * policy permits. Any policy/catalog error produces an empty catalog.
     *
     * @return list<array<string,mixed>>
     */
    public function catalog(
        bool $publicWebConfirmed,
        string $capabilityPack = self::PUBLIC_WEB_PACK,
    ): array {
        $policy = $this->runtimePolicy($capabilityPack);
        $contract = $this->packContract($capabilityPack);
        if ($contract === null || ! $policy['execution_enabled']) {
            return [];
        }
        if ($policy['internet_requires_public_input'] && ! $publicWebConfirmed) {
            return [];
        }

        try {
            $available = $this->mcpRouter->getAvailableTools();
        } catch (Throwable) {
            return [];
        }

        $allowedPairs = array_flip($policy['allowed_tools']);
        $catalog = [];
        foreach ($available as $tool) {
            if (! is_array($tool)) {
                continue;
            }
            $server = $this->token($tool['server'] ?? null);
            $name = $this->token($tool['name'] ?? null);
            if ($server === null || $name === null || ! isset($allowedPairs["{$server}.{$name}"])) {
                continue;
            }

            $serverConfig = config("mcp.servers.{$server}");
            if (! is_array($serverConfig)
                || ($serverConfig['enabled'] ?? false) !== true
                || ! in_array(
                    (string) ($serverConfig['write_scope'] ?? ''),
                    $contract['server_write_scopes'],
                    true,
                )
            ) {
                continue;
            }

            try {
                $decision = $this->offlinePolicy->evaluateMcpTool($server, $name, [
                    '_audit' => false,
                    '_catalog' => true,
                    'agent_id' => 'colibri-research-sidecar',
                ], $policy['offline_policy_profile']);
            } catch (Throwable) {
                continue;
            }
            if (! $decision->allowed
                || ! in_array($decision->toolClass, $contract['tool_classes'], true)
                || $decision->requiresConfirmation
            ) {
                continue;
            }

            $parameters = $tool['parameters'] ?? null;
            if (! is_array($parameters)) {
                continue;
            }
            $schemaValidation = $this->schemaValidator->validateSchema($parameters);
            if (! $schemaValidation['valid']) {
                continue;
            }

            $catalog[] = array_merge($tool, [
                'function_name' => $this->functionName($server, $name),
                'tool_class' => $decision->toolClass,
                'write_scope' => 'none',
                'policy_profile' => $decision->profile,
            ]);
            if (count($catalog) >= $policy['max_tools_advertised']) {
                break;
            }
        }

        return $catalog;
    }

    /**
     * Validate and normalize a model-proposed tool call before dispatch.
     *
     * @return array{allowed:bool,reason:string,server:?string,tool:?string,arguments:array<string,mixed>,tool_class:?string}
     */
    public function authorizeToolCall(
        string $functionName,
        mixed $arguments,
        bool $publicWebConfirmed,
        int $sidecarDepth,
        int $callsAlreadyMade,
        string $capabilityPack = self::PUBLIC_WEB_PACK,
    ): array {
        $policy = $this->runtimePolicy($capabilityPack);
        if (! $policy['execution_enabled']) {
            return $this->denied('canary_execution_disabled');
        }
        if ($policy['writes_allowed']) {
            return $this->denied('invalid_policy_write_access');
        }
        if ($sidecarDepth > $policy['max_sidecar_depth']) {
            return $this->denied('sidecar_depth_exceeded');
        }
        if ($callsAlreadyMade >= $policy['max_tool_calls']) {
            return $this->denied('tool_call_budget_exceeded');
        }
        if ($policy['internet_requires_public_input'] && ! $publicWebConfirmed) {
            return $this->denied('public_web_confirmation_required');
        }
        if (! is_array($arguments)) {
            return $this->denied('tool_arguments_not_object');
        }

        $catalog = $this->catalog($publicWebConfirmed, $capabilityPack);
        $matched = null;
        foreach ($catalog as $tool) {
            if (hash_equals((string) $tool['function_name'], $functionName)) {
                $matched = $tool;
                break;
            }
        }
        if ($matched === null) {
            return $this->denied('tool_not_in_exact_read_only_allowlist');
        }

        $arguments = $this->normalizeArguments((string) $matched['name'], $arguments, $policy);
        $validation = $this->invocationValidator->validate(
            $arguments,
            $matched['parameters'],
            true,
        );
        $argumentSummary = $this->invocationValidator->summarizeArguments($arguments);
        if (! $validation['valid']) {
            return $this->denied('tool_arguments_schema_rejected', [
                'argument_names' => $argumentSummary['names'],
                'validation_codes' => $validation['codes'],
                'validation_fields' => $validation['fields'],
                'schema_sha256' => $validation['schema_sha256'],
                'arguments_sha256' => $validation['arguments_sha256'],
            ]);
        }

        if (isset($arguments['query'])) {
            $queryAllowed = $capabilityPack === self::PUBLIC_WEB_PACK
                ? $this->publicSearchQueryAllowed(
                    (string) $arguments['query'],
                    $policy['max_search_query_chars'],
                )
                : $this->localSearchQueryAllowed(
                    (string) $arguments['query'],
                    $policy['max_search_query_chars'],
                );
            if (! $queryAllowed) {
                return $this->denied($capabilityPack === self::PUBLIC_WEB_PACK
                    ? 'search_query_private_or_unsafe'
                    : 'local_search_query_invalid');
            }
        }

        return [
            'allowed' => true,
            'reason' => 'exact read-only canary tool allowed',
            'server' => (string) $matched['server'],
            'tool' => (string) $matched['name'],
            'arguments' => $arguments,
            'tool_class' => (string) $matched['tool_class'],
            'argument_names' => $argumentSummary['names'],
            'validation_codes' => [],
            'validation_fields' => [],
            'schema_sha256' => $validation['schema_sha256'],
            'arguments_sha256' => $validation['arguments_sha256'],
        ];
    }

    /** @return array<string,mixed> */
    public function runtimePolicy(string $capabilityPack = self::PUBLIC_WEB_PACK): array
    {
        $contract = $this->packContract($capabilityPack);
        $default = $this->defaultPolicy($capabilityPack);
        if ($contract === null) {
            $default['status'] = 'unsupported_policy_contract';

            return $default;
        }
        try {
            $configured = $this->systemConfig->get($contract['policy_key']);
        } catch (Throwable) {
            $configured = null;
        }
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($configured) || (int) ($configured['version'] ?? 0) !== 1) {
            return $default;
        }

        $policy = array_replace($default, $configured);
        $policy['status'] = $this->token($policy['status']) ?? 'invalid';
        $policy['execution_enabled'] = $policy['execution_enabled'] === true;
        $policy['internet_requires_public_input'] = $contract['internet_requires_public_input'];
        $policy['writes_allowed'] = false;
        $policy['fallback_allowed'] = false;
        $policy['instance_id'] = $this->token($policy['instance_id']) ?? 'colibri_87';
        $policy['model_role'] = $this->token($policy['model_role']) ?? 'deep_offline';
        $policy['capability_pack'] = $this->token($policy['capability_pack']) ?? $capabilityPack;
        $policy['requires_internet'] = $contract['requires_internet'];
        $policy['requires_searxng_endpoint'] = $contract['requires_searxng_endpoint'];
        $policy['local_offline_supported'] = $contract['local_offline_supported'];
        $policy['offline_policy_profile'] = $this->token($policy['offline_policy_profile'])
            ?? $contract['offline_policy_profile'];
        if ($policy['capability_pack'] !== $capabilityPack
            || $policy['offline_policy_profile'] !== $contract['offline_policy_profile']
        ) {
            $policy['status'] = 'unsupported_policy_contract';
            $policy['execution_enabled'] = false;
        }
        $policy['allowed_tools'] = array_values(array_unique(array_filter(
            is_array($policy['allowed_tools']) ? $policy['allowed_tools'] : [],
            fn (mixed $value): bool => $this->validToolPair($value)
                && in_array($value, $contract['supported_tools'], true)
        )));
        foreach ([
            'max_tools_advertised' => [1, 6],
            'max_iterations' => [1, 4],
            'max_tool_calls' => [1, 4],
            'max_result_bytes' => [1000, 16000],
            'max_total_result_bytes' => [1000, 48000],
            'max_search_query_chars' => [32, 500],
            'max_search_results' => [1, 10],
            'max_document_chars' => [500, 8000],
            'max_prompt_chars' => [64, 8000],
            'max_output_tokens' => [16, 512],
            'max_sidecar_depth' => [0, 0],
        ] as $key => [$minimum, $maximum]) {
            $policy[$key] = max($minimum, min($maximum, (int) $policy[$key]));
        }

        return $policy;
    }

    private function normalizeArguments(string $tool, array $arguments, array $policy): array
    {
        if (array_key_exists('max_results', $arguments)) {
            $arguments['max_results'] = max(1, min(
                $policy['max_search_results'],
                (int) $arguments['max_results'],
            ));
        }
        if (array_key_exists('limit', $arguments)) {
            $arguments['limit'] = max(1, min(
                $policy['max_search_results'],
                (int) $arguments['limit'],
            ));
        }
        if (array_key_exists('max_chars', $arguments)) {
            $arguments['max_chars'] = max(1, min(
                $policy['max_document_chars'],
                (int) $arguments['max_chars'],
            ));
        }
        if (array_key_exists('language', $arguments)) {
            $language = strtolower(trim((string) $arguments['language']));
            $arguments['language'] = preg_match('/\A[a-z]{2}(?:-[a-z]{2})?\z/', $language) === 1
                ? $language
                : 'en';
        }
        if (array_key_exists('time_range', $arguments)) {
            $timeRange = strtolower(trim((string) $arguments['time_range']));
            $arguments['time_range'] = in_array($timeRange, ['', 'day', 'week', 'month', 'year'], true)
                ? $timeRange
                : '';
        }
        if (isset($arguments['query'])) {
            $arguments['query'] = trim((string) $arguments['query']);
        }

        return $arguments;
    }

    private function publicSearchQueryAllowed(string $query, int $maxChars): bool
    {
        if ($query === '' || mb_strlen($query) > $maxChars || str_contains($query, "\n")) {
            return false;
        }

        return preg_match(
            '/(?:\b(?:localhost|password|passwd|api[_ -]?key|bearer|private[_ -]?key|secret)\b|'
            .'\b127(?:\.\d{1,3}){3}\b|\b10(?:\.\d{1,3}){3}\b|'
            .'\b192\.168(?:\.\d{1,3}){2}\b|\b169\.254(?:\.\d{1,3}){2}\b|'
            .'\b172\.(?:1[6-9]|2\d|3[01])(?:\.\d{1,3}){2}\b|'
            .'(?:^|[\/\\\\])\.env(?:$|\s)|-----BEGIN)/i',
            $query
        ) !== 1;
    }

    private function localSearchQueryAllowed(string $query, int $maxChars): bool
    {
        return $query !== ''
            && mb_strlen($query) <= $maxChars
            && ! str_contains($query, "\n")
            && ! str_contains($query, "\0");
    }

    /** @return array<string,mixed>|null */
    private function packContract(string $capabilityPack): ?array
    {
        return self::PACK_CONTRACTS[$capabilityPack] ?? null;
    }

    /** @return array<string,mixed> */
    private function defaultPolicy(string $capabilityPack): array
    {
        return $capabilityPack === self::LOCAL_OFFLINE_PACK
            ? self::LOCAL_DEFAULT_POLICY
            : self::DEFAULT_POLICY;
    }

    private function policyKey(string $capabilityPack): string
    {
        return (string) ($this->packContract($capabilityPack)['policy_key'] ?? self::POLICY_KEY);
    }

    private function functionName(string $server, string $tool): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $server)
            .'__'
            .preg_replace('/[^a-zA-Z0-9_-]/', '_', $tool);
    }

    private function validToolPair(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-z0-9_-]+\.[a-z0-9_-]+\z/i', $value) === 1;
    }

    private function token(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string,mixed>  $diagnostics
     * @return array<string,mixed>
     */
    private function denied(string $reason, array $diagnostics = []): array
    {
        $receipt = [
            'allowed' => false,
            'reason' => $reason,
            'server' => null,
            'tool' => null,
            'arguments' => [],
            'tool_class' => null,
        ];
        foreach ([
            'argument_names',
            'validation_codes',
            'validation_fields',
            'schema_sha256',
            'arguments_sha256',
        ] as $key) {
            if (array_key_exists($key, $diagnostics)) {
                $receipt[$key] = $diagnostics[$key];
            }
        }

        return $receipt;
    }
}
