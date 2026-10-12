<?php

namespace App\Services\Genealogy;

use App\Services\AIService;
use Illuminate\Support\Facades\Log;

class GenealogySearchPlanService
{
    public function __construct(
        private ?AIService $aiService = null,
        private ?SourceRegistryService $sourceRegistry = null
    ) {
        $this->aiService ??= app(AIService::class);
        $this->sourceRegistry ??= app(SourceRegistryService::class);
    }

    /**
     * Build a bounded, validated search plan for one genealogy target.
     *
     * @param  array<string, mixed>  $person
     * @param  array<string, array<string, mixed>>  $availableTools
     * @param  array<string, mixed>  $context
     * @return array{source: string, calls: list<array<string, mixed>>, rejected: list<array<string, mixed>>, vetted_sources: list<array<string, mixed>>}
     */
    public function buildPlan(array $person, array $availableTools, array $context = []): array
    {
        $vettedSources = $this->vettedSourcesForPerson($person);

        if (! (bool) config('genealogy.search_planner.enabled', true)) {
            return $this->fallbackPlan($person, $availableTools, $vettedSources, 'disabled');
        }

        $prompt = $this->buildPrompt($person, $availableTools, $vettedSources, $context);
        try {
            $response = $this->aiService?->process($prompt, [
                'system' => 'You are a genealogy search strategist. Return only strict JSON.',
                'system_prompt' => 'You are a genealogy search strategist. Return only strict JSON.',
                'temperature' => 0.1,
                'max_tokens' => 1400,
                'model_role' => $context['model_role'] ?? 'fast',
                'ai_timeout' => config('genealogy.search_planner.ai_timeout_seconds', 25),
                'use_cache' => false,
                'sensitive_data' => true,
                'data_class' => 'genealogy_search_plan',
            ]) ?? ['success' => false];
        } catch (\Throwable $e) {
            Log::warning('GenealogySearchPlan: planner AI failed', [
                'person_id' => $person['id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackPlan($person, $availableTools, $vettedSources, 'ai_exception');
        }

        if (empty($response['success'])) {
            return $this->fallbackPlan($person, $availableTools, $vettedSources, 'ai_failed');
        }

        $decoded = $this->decodePlan((string) ($response['content'] ?? $response['response'] ?? ''));
        if (! is_array($decoded)) {
            return $this->fallbackPlan($person, $availableTools, $vettedSources, 'invalid_json');
        }

        $validated = $this->validatePlan($decoded, $person, $availableTools, $vettedSources);
        if ($validated['calls'] === []) {
            return $this->fallbackPlan($person, $availableTools, $vettedSources, 'no_valid_calls', $validated['rejected']);
        }

        $validated['source'] = 'llm';
        $validated['vetted_sources'] = $vettedSources;

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $person
     * @param  array<string, array<string, mixed>>  $availableTools
     * @param  list<array<string, mixed>>  $vettedSources
     * @return array{source: string, calls: list<array<string, mixed>>, rejected: list<array<string, mixed>>, vetted_sources: list<array<string, mixed>>}
     */
    public function validatePlan(array $plan, array $person, array $availableTools, array $vettedSources = []): array
    {
        $maxCalls = max(1, min(10, (int) config('genealogy.search_planner.max_calls', 6)));
        $allowedTools = array_fill_keys((array) config('genealogy.search_planner.allowed_tools', []), true);
        $vettedDomains = array_fill_keys(array_filter(array_map(
            fn (array $source): ?string => $this->normalizedDomain($source['domain'] ?? $source['url'] ?? null),
            $vettedSources
        )), true);

        $items = $plan['queries'] ?? $plan['tool_calls'] ?? $plan['calls'] ?? [];
        if (! is_array($items)) {
            $items = [];
        }

        $calls = [];
        $rejected = [];
        $seenCalls = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (count($calls) >= $maxCalls) {
                $rejected[] = ['reason' => 'max_calls_exceeded', 'item' => $item];

                continue;
            }

            $tool = trim((string) ($item['tool'] ?? $item['name'] ?? ''));
            if ($tool === '' || ! isset($availableTools[$tool]) || ! isset($allowedTools[$tool])) {
                $rejected[] = ['reason' => 'tool_not_allowed', 'tool' => $tool];

                continue;
            }

            $params = is_array($item['params'] ?? null) ? $item['params'] : [];
            $params = $this->sanitizeParams($tool, $params, $availableTools[$tool], $person);
            $params = $this->applyPersonDefaults($tool, $params, $availableTools[$tool], $person);
            $missing = $this->missingRequiredParams($availableTools[$tool], $params);
            if ($missing !== []) {
                $rejected[] = ['reason' => 'missing_required_params', 'tool' => $tool, 'missing' => $missing];

                continue;
            }

            $providerSpecificDomain = $this->providerSpecificDomainInParams($params);
            if ($providerSpecificDomain !== null && $this->shouldEnforceProviderSpecificDomains()) {
                $rejected[] = ['reason' => 'provider_specific_domain_strict', 'tool' => $tool, 'domain' => $providerSpecificDomain];

                continue;
            }

            $sourceDomain = $this->normalizedDomain($item['source_domain'] ?? $item['domain'] ?? null);
            $queryDomain = $this->siteScopedDomain((string) ($params['query'] ?? $params['q'] ?? ''));
            if ($queryDomain !== null) {
                $sourceDomain = $queryDomain;
            }

            if (in_array($tool, ['mcp_searxng_search', 'mcp_genealogy_search'], true)) {
                if ($sourceDomain === null || ! isset($vettedDomains[$sourceDomain])) {
                    $rejected[] = ['reason' => 'web_search_requires_vetted_source_domain', 'tool' => $tool, 'domain' => $sourceDomain];

                    continue;
                }
            }

            $callFingerprint = $tool.':'.hash('sha256', json_encode($params, JSON_UNESCAPED_SLASHES) ?: '[]');
            if (isset($seenCalls[$callFingerprint])) {
                $rejected[] = ['reason' => 'duplicate_call', 'tool' => $tool, 'params' => $params];

                continue;
            }
            $seenCalls[$callFingerprint] = true;

            $calls[] = [
                'tool' => $tool,
                'params' => $params,
                'purpose' => mb_substr(trim((string) ($item['purpose'] ?? 'planned genealogy search')), 0, 180),
                'required_anchors' => $this->sanitizeStringList($item['required_anchors'] ?? ['name', 'date_or_place'], 5),
                'source_domain' => $sourceDomain,
            ];
        }

        return [
            'source' => 'validated',
            'calls' => $calls,
            'rejected' => $rejected,
            'vetted_sources' => $vettedSources,
        ];
    }

    /**
     * @param  array<string, mixed>  $person
     * @param  array<string, array<string, mixed>>  $availableTools
     * @param  list<array<string, mixed>>  $vettedSources
     * @param  list<array<string, mixed>>  $rejected
     * @return array{source: string, calls: list<array<string, mixed>>, rejected: list<array<string, mixed>>, vetted_sources: list<array<string, mixed>>}
     */
    private function fallbackPlan(array $person, array $availableTools, array $vettedSources, string $reason, array $rejected = []): array
    {
        $calls = [];
        foreach (['source_search_all', 'generate_record_hints'] as $tool) {
            if (! isset($availableTools[$tool])) {
                continue;
            }
            $params = $this->applyPersonDefaults($tool, [], $availableTools[$tool], $person);
            if ($this->missingRequiredParams($availableTools[$tool], $params) === []) {
                $calls[] = [
                    'tool' => $tool,
                    'params' => $params,
                    'purpose' => 'fallback bounded genealogy coverage',
                    'required_anchors' => ['name', 'date_or_place'],
                    'source_domain' => null,
                ];
            }
        }

        return [
            'source' => 'fallback:'.$reason,
            'calls' => $calls,
            'rejected' => $rejected,
            'vetted_sources' => $vettedSources,
        ];
    }

    /**
     * @param  array<string, mixed>  $person
     * @return list<array<string, mixed>>
     */
    private function vettedSourcesForPerson(array $person): array
    {
        $sources = [];
        try {
            if (! empty($person['id'])) {
                $routing = $this->sourceRegistry?->getSourcesForPerson((int) $person['id']);
                foreach (($routing['sources'] ?? []) as $source) {
                    $sources[] = [
                        'name' => (string) ($source->archive_name ?? ''),
                        'domain' => $this->normalizedDomain($source->archive_url ?? null),
                        'url' => (string) ($source->archive_url ?? ''),
                        'tool_name' => $source->tool_name ?? null,
                        'access_type' => $source->access_type ?? null,
                        'record_types' => json_decode((string) ($source->record_types ?? '[]'), true) ?: [],
                        'regions' => json_decode((string) ($source->regions ?? '[]'), true) ?: [],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('GenealogySearchPlan: source registry lookup failed', ['error' => $e->getMessage()]);
        }

        foreach ((array) config('genealogy.public_web_sources', []) as $source) {
            if (! is_array($source)) {
                continue;
            }
            $sources[] = $source;
        }

        $seen = [];
        $filtered = [];
        foreach ($sources as $source) {
            $domain = $this->normalizedDomain($source['domain'] ?? $source['url'] ?? null);
            if ($domain === null || $this->isProviderSpecificDomain($domain) || $this->isNonPublicDomain($domain)) {
                continue;
            }
            if (isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;
            $source['domain'] = $domain;
            $filtered[] = $source;
        }

        return array_slice($filtered, 0, 18);
    }

    /**
     * @param  array<string, mixed>  $person
     * @param  array<string, array<string, mixed>>  $availableTools
     * @param  list<array<string, mixed>>  $vettedSources
     */
    private function buildPrompt(array $person, array $availableTools, array $vettedSources, array $context = []): string
    {
        $toolSummaries = [];
        foreach ($availableTools as $name => $def) {
            if (! in_array($name, (array) config('genealogy.search_planner.allowed_tools', []), true)) {
                continue;
            }
            $toolSummaries[] = [
                'tool' => $name,
                'description' => mb_substr((string) ($def['description'] ?? ''), 0, 180),
                'params' => array_keys($this->normalizeParamDefs($def['parameters'] ?? [])),
            ];
        }

        $payload = [
            'person' => $person,
            'task_context' => $this->taskContextForPrompt($context),
            'allowed_tools' => array_slice($toolSummaries, 0, 20),
            'vetted_public_sources' => array_slice($vettedSources, 0, 12),
            'provider_specific_domains' => array_values((array) config('scraping.provider_specific_domains', config('scraping.manual_only_domains', []))),
            'rules' => [
                'Prioritize the task_context research_question over generic person coverage.',
                'Use only allowed_tools.',
                'Use source-scoped web search only for vetted_public_sources domains.',
                'Provider-specific domains may be planned only through configured APIs, private adapters, headed-browser capture, or review-first capture tasks; never plan credential, paywall, CAPTCHA, or prohibited-scraping bypass.',
                'For FT4, plan around one specific missing fact, relationship, source gap, or media gap plus the expected source class.',
                'Prefer query anchors: full name plus date, place, spouse, parent, child, occupation, or religion.',
                'Only plan source_search_all when full name plus date/place/relative anchors are available.',
                'Broad same-name hits are leads only; source_add later requires title/snippet/record text identity bridge.',
            ],
        ];

        return "Build a concise genealogy web/API search plan for this target.\n"
            .'Return strict JSON only with key `queries`, each item shaped as: '
            .'{"tool":"source_search_all","params":{},"purpose":"...","required_anchors":["name","date_or_place"],"source_domain":null}'."\n"
            .'Payload: '.json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function taskContextForPrompt(array $context): array
    {
        $permissions = [];
        if (is_array($context['operator_permissions'] ?? null)) {
            foreach (['person_creation', 'canonical_facts', 'downloads', 'writeback', 'scheduled_enablement'] as $key) {
                $permissions[$key] = (bool) ($context['operator_permissions'][$key] ?? false);
            }
        }

        return array_filter([
            'genealogy_task_id' => isset($context['genealogy_task_id']) ? (int) $context['genealogy_task_id'] : null,
            'question_type' => $this->shortContextString($context['question_type'] ?? null, 80),
            'research_question' => $this->shortContextString($context['research_question'] ?? null, 500),
            'selection_reason' => $this->shortContextString($context['selection_reason'] ?? null, 300),
            'scope_reason' => $this->shortContextString($context['scope_reason'] ?? null, 500),
            'evidence_summary' => $this->shortContextString($context['evidence_summary'] ?? null, 1000),
            'conflicts_found' => $this->shortContextString($context['conflicts_found'] ?? null, 800),
            'related_people_used' => $this->boundedScalarList($context['related_people_used'] ?? null, 20),
            'sources_checked' => $this->boundedScalarList($context['sources_checked'] ?? null, 20),
            'task_parameters' => $this->boundedScalarMap($context['task_parameters'] ?? null, 20),
            'research_playbook' => $this->playbookContextForPrompt($context['research_playbook'] ?? null),
            'operator_permissions' => $permissions ?: null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function playbookContextForPrompt(mixed $value): ?array
    {
        if (! is_array($value) || empty($value['key'])) {
            return null;
        }

        $strings = [];
        foreach (['first_tools', 'required_anchors', 'upstream_sources'] as $key) {
            $strings[$key] = [];
            foreach ((array) ($value[$key] ?? []) as $item) {
                if (is_scalar($item)) {
                    $text = $this->shortContextString($item, 80);
                    if ($text !== null) {
                        $strings[$key][] = $text;
                    }
                }
                if (count($strings[$key]) >= 8) {
                    break;
                }
            }
        }

        return array_filter([
            'key' => $this->shortContextString($value['key'] ?? null, 80),
            'label' => $this->shortContextString($value['label'] ?? null, 120),
            'objective' => $this->shortContextString($value['objective'] ?? null, 300),
            'first_tools' => $strings['first_tools'],
            'required_anchors' => $strings['required_anchors'],
            'stop_rule' => $this->shortContextString($value['stop_rule'] ?? null, 240),
            'report_rule' => $this->shortContextString($value['report_rule'] ?? null, 240),
            'upstream_sources' => $strings['upstream_sources'],
        ], static fn ($item): bool => $item !== null && $item !== [] && $item !== '');
    }

    private function shortContextString(mixed $value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    /** @return list<int|float|string|bool> */
    private function boundedScalarList(mixed $value, int $limit): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_slice(array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_int($item) || is_float($item) || is_string($item) || is_bool($item)
        )), 0, $limit);
    }

    /** @return array<string, int|float|string|bool|null> */
    private function boundedScalarMap(mixed $value, int $limit): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || (! is_scalar($item) && $item !== null)) {
                continue;
            }
            $result[mb_substr($key, 0, 80)] = is_string($item) ? mb_substr($item, 0, 300) : $item;
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    private function decodePlan(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/i', $content, $m) === 1) {
            $content = $m[1];
        } elseif (preg_match('/(\{[\s\S]*\})/', $content, $m) === 1) {
            $content = $m[1];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $toolDef
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    private function sanitizeParams(string $tool, array $params, array $toolDef, array $person): array
    {
        $paramDefs = $this->normalizeParamDefs($toolDef['parameters'] ?? []);
        $allowed = array_fill_keys(array_keys($paramDefs), true);
        $clean = [];
        foreach ($params as $key => $value) {
            $key = is_string($key) ? $key : '';
            if ($key === '' || ! isset($allowed[$key])) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            if (is_bool($value)) {
                $clean[$key] = $value;
            } elseif (is_numeric($value)) {
                $clean[$key] = str_contains((string) $value, '.') ? (float) $value : (int) $value;
            } elseif (is_scalar($value)) {
                $clean[$key] = mb_substr(trim((string) $value), 0, 240);
            }
        }

        if (isset($clean['limit'])) {
            $clean['limit'] = max(1, min(10, (int) $clean['limit']));
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $toolDef
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    private function applyPersonDefaults(string $tool, array $params, array $toolDef, array $person): array
    {
        $paramDefs = $this->normalizeParamDefs($toolDef['parameters'] ?? []);
        $personName = trim((string) ($person['name'] ?? ''));
        $nameParts = preg_split('/\s+/', $personName) ?: [];
        $surname = trim((string) ($person['surname'] ?? end($nameParts) ?: ''));
        $given = trim((string) ($person['given_name'] ?? $nameParts[0] ?? ''));

        foreach (['person_id', 'personId'] as $key) {
            if (isset($paramDefs[$key]) && ! isset($params[$key]) && ! empty($person['id'])) {
                $params[$key] = (int) $person['id'];
            }
        }
        foreach (['query', 'name'] as $key) {
            if (isset($paramDefs[$key]) && ! isset($params[$key]) && $personName !== '') {
                $params[$key] = $personName;
            }
        }
        if (isset($paramDefs['surname']) && ! isset($params['surname']) && $surname !== '') {
            $params['surname'] = $surname;
        }
        if (isset($paramDefs['given_name']) && ! isset($params['given_name']) && $given !== '') {
            $params['given_name'] = $given;
        }
        foreach (['birth_year', 'death_year'] as $key) {
            if (isset($paramDefs[$key]) && ! isset($params[$key]) && ! empty($person[$key])) {
                $params[$key] = (int) $person[$key];
            }
        }
        if (isset($paramDefs['birth_place']) && ! isset($params['birth_place']) && ! empty($person['birth_place'])) {
            $params['birth_place'] = (string) $person['birth_place'];
        }
        if (isset($paramDefs['state']) && ! isset($params['state']) && ! empty($person['birth_place']) && strlen((string) $person['birth_place']) <= 30) {
            $params['state'] = (string) $person['birth_place'];
        }
        if (isset($paramDefs['limit']) && ! isset($params['limit'])) {
            $params['limit'] = 5;
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $toolDef
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function missingRequiredParams(array $toolDef, array $params): array
    {
        $missing = [];
        foreach ($this->normalizeParamDefs($toolDef['parameters'] ?? []) as $name => $def) {
            if (empty($def['required'])) {
                continue;
            }
            if (! array_key_exists($name, $params) || $params[$name] === '' || $params[$name] === null) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function normalizeParamDefs(mixed $paramDefs): array
    {
        if (! is_array($paramDefs)) {
            return [];
        }
        if (($paramDefs['type'] ?? null) === 'object' && isset($paramDefs['properties']) && is_array($paramDefs['properties'])) {
            $required = is_array($paramDefs['required'] ?? null) ? $paramDefs['required'] : [];
            $normalized = [];
            foreach ($paramDefs['properties'] as $name => $def) {
                $normalized[$name] = array_merge(is_array($def) ? $def : [], [
                    'required' => in_array($name, $required, true),
                ]);
            }

            return $normalized;
        }
        if (isset($paramDefs[0]) && is_array($paramDefs[0] ?? null) && isset($paramDefs[0]['name'])) {
            $normalized = [];
            foreach ($paramDefs as $def) {
                if (is_array($def) && isset($def['name'])) {
                    $normalized[(string) $def['name']] = $def;
                }
            }

            return $normalized;
        }

        return array_filter($paramDefs, 'is_array');
    }

    private function providerSpecificDomainInParams(array $params): ?string
    {
        foreach ($params as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $text = (string) $value;
            foreach ((array) config('scraping.provider_specific_domains', config('scraping.manual_only_domains', [])) as $domain) {
                $domain = $this->normalizedDomain($domain);
                if ($domain !== null && preg_match('/(^|[\/.\s:])'.preg_quote($domain, '/').'($|[\/\s?&#:])/i', $text) === 1) {
                    return $domain;
                }
            }
        }

        return null;
    }

    private function siteScopedDomain(string $query): ?string
    {
        if (preg_match('/\bsite:([a-z0-9.-]+\.[a-z]{2,})\b/i', $query, $m) !== 1) {
            return null;
        }

        return $this->normalizedDomain($m[1]);
    }

    private function normalizedDomain(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }
        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            $host = parse_url('https://'.$value, PHP_URL_HOST);
        }
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower(trim($host, "[] \t\n\r\0\x0B."))) ?: null;
    }

    private function isProviderSpecificDomain(string $host): bool
    {
        if (! $this->shouldEnforceProviderSpecificDomains()) {
            return false;
        }

        foreach ((array) config('scraping.provider_specific_domains', config('scraping.manual_only_domains', [])) as $domain) {
            $domain = $this->normalizedDomain($domain);
            if ($domain !== null && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }

    private function shouldEnforceProviderSpecificDomains(): bool
    {
        return (bool) config('scraping.enforce_provider_specific_domains', config('scraping.enforce_manual_only_domains', false));
    }

    private function isNonPublicDomain(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        foreach (['localhost', 'test', 'example', 'invalid'] as $reservedTld) {
            if ($host === $reservedTld || str_ends_with($host, '.'.$reservedTld)) {
                return true;
            }
        }
        foreach (['example.com', 'example.net', 'example.org'] as $reservedDomain) {
            if ($host === $reservedDomain || str_ends_with($host, '.'.$reservedDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function sanitizeStringList(mixed $value, int $limit): array
    {
        if (! is_array($value)) {
            $value = [];
        }

        return array_slice(array_values(array_filter(array_map(
            static fn ($item): string => mb_substr(trim((string) (is_scalar($item) ? $item : '')), 0, 80),
            $value
        ))), 0, $limit);
    }
}
