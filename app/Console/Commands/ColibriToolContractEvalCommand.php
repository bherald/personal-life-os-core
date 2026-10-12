<?php

namespace App\Console\Commands;

use App\Services\ColibriResearchPolicyService;
use App\Services\ColibriResearchSidecarService;
use Illuminate\Console\Command;

class ColibriToolContractEvalCommand extends Command
{
    protected $signature = 'ai:colibri-tool-contract-eval
        {--pack=public_web_read_only : public_web_read_only or local_offline_read_only}
        {--case= : Run one exact synthetic case ID}
        {--max-cases=1 : Maximum cases to run; use 20 for the complete pack}
        {--execute : Invoke Colibri without dispatching any proposed tool}
        {--json : Emit JSON}
        {--compact : Emit compact JSON}
        {--fail-on-gaps : Fail when an evaluated case does not pass}';

    protected $description = 'Measure Colibri tool-call selection and schema conformance without dispatching tools';

    public function handle(ColibriResearchSidecarService $sidecar): int
    {
        $capabilityPack = trim((string) $this->option('pack'));
        if (! in_array($capabilityPack, [
            ColibriResearchPolicyService::PUBLIC_WEB_PACK,
            ColibriResearchPolicyService::LOCAL_OFFLINE_PACK,
        ], true)) {
            return $this->render($this->blocked('invalid_capability_pack'), self::FAILURE);
        }
        $fixtures = $this->fixtures($capabilityPack);
        $caseId = trim((string) $this->option('case'));
        if ($caseId !== '') {
            $fixtures = array_values(array_filter(
                $fixtures,
                static fn (array $fixture): bool => $fixture['id'] === $caseId,
            ));
            if ($fixtures === []) {
                return $this->render($this->blocked('unknown_case_id'), self::FAILURE);
            }
        } else {
            $maxCases = max(1, min(20, (int) $this->option('max-cases')));
            $fixtures = array_slice($fixtures, 0, $maxCases);
        }

        if (! (bool) $this->option('execute')) {
            return $this->render([
                'schema' => 'plos.colibri_model_tool_contract_eval.v1',
                'status' => 'plan',
                'capability_pack' => $capabilityPack,
                'selected_case_ids' => array_column($fixtures, 'id'),
                'selected_case_count' => count($fixtures),
                'complete_pack_case_count' => 20,
                'model_invoked' => false,
                'network_performed' => false,
                'tool_dispatch_performed' => false,
                'writes_performed' => false,
                'fallback_suppressed' => true,
                'raw_prompts_or_model_outputs_included' => false,
                'promotion_eligible' => false,
            ], self::SUCCESS);
        }

        $results = [];
        foreach ($fixtures as $fixture) {
            $results[] = $sidecar->evaluateModelToolContractCase(
                $fixture['id'],
                $fixture['prompt'],
                $fixture['expected_function'],
                $capabilityPack,
            );
        }
        $passed = count(array_filter(
            $results,
            static fn (array $result): bool => ($result['passed'] ?? false) === true,
        ));
        $allPassed = $passed === count($results);
        $payload = [
            'schema' => 'plos.colibri_model_tool_contract_eval.v1',
            'status' => $allPassed ? 'evaluated_pass' : 'evaluated_gap',
            'capability_pack' => $capabilityPack,
            'evaluated_case_count' => count($results),
            'passed_count' => $passed,
            'failed_count' => count($results) - $passed,
            'pass_rate' => count($results) > 0 ? round($passed / count($results), 4) : 0,
            'minimum_promotion_pass_rate' => 0.95,
            'model_invoked' => true,
            'network_performed' => true,
            'tool_dispatch_performed' => false,
            'writes_performed' => false,
            'fallback_suppressed' => true,
            'raw_prompts_or_model_outputs_included' => false,
            'promotion_eligible' => false,
            'promotion_remaining' => [
                '20_to_30_model_tool_contract_cases_at_or_above_95_percent',
                '10_to_20_research_quality_and_grounding_cases',
                '25_to_50_audited_canary_runs_with_zero_policy_escapes',
                'operator_review',
            ],
            'results' => $results,
        ];
        $exit = (bool) $this->option('fail-on-gaps') && ! $allPassed
            ? self::FAILURE
            : self::SUCCESS;

        return $this->render($payload, $exit);
    }

    /** @return list<array{id:string,prompt:string,expected_function:?string}> */
    private function fixtures(string $capabilityPack): array
    {
        if ($capabilityPack === ColibriResearchPolicyService::LOCAL_OFFLINE_PACK) {
            return $this->fixtureRows([
                ['rag_search', 'Use local RAG search for project architecture.', 'rag__rag_search'],
                ['rag_search-private', 'Use local RAG search for private project history.', 'rag__rag_search'],
                ['rag_search-decisions', 'Search local RAG for recorded design decisions.', 'rag__rag_search'],
                ['rag_search-operations', 'Search local RAG for operational notes.', 'rag__rag_search'],
                ['rag_search-testing', 'Search local RAG for test evidence.', 'rag__rag_search'],
                ['rag_search-deploy', 'Search local RAG for deployment guidance.', 'rag__rag_search'],
                ['rag_search-provenance', 'Search local RAG for provenance records.', 'rag__rag_search'],
                ['rag_search-performance', 'Search local RAG for performance evidence.', 'rag__rag_search'],
                ['rag_search-security', 'Search local RAG for security design notes.', 'rag__rag_search'],
                ['rag_search-offline', 'Search local RAG for offline operation details.', 'rag__rag_search'],
                ['rag_similar-42', 'Use the similar-document tool for local document 42.', 'rag__rag_similar'],
                ['rag_similar-77', 'Find documents similar to local document 77.', 'rag__rag_similar'],
                ['rag_similar-101', 'Use local similarity lookup for document 101.', 'rag__rag_similar'],
                ['rag_similar-205', 'Find local records similar to document 205.', 'rag__rag_similar'],
                ['rag_read-42', 'Read the first bounded window of local RAG document 42.', 'rag__rag_read'],
                ['rag_read-77', 'Read a bounded window of local RAG document 77 starting at offset 100.', 'rag__rag_read'],
                ['rag_read-101', 'Read local RAG document 101 with a bounded character count.', 'rag__rag_read'],
                ['no_tool-ready', 'Do not call a tool. Respond exactly READY.', null],
                ['no_tool-confirm', 'This is a transport check. Do not call a tool; respond READY.', null],
                ['no_tool-idle', 'No lookup is requested. Respond exactly READY without a tool.', null],
            ]);
        }

        return $this->fixtureRows([
            ['search-boston-history', 'Use public web search for Boston history.', 'searxng__searxng_search'],
            ['search-boston-trends', 'Use public web search for current Boston trends.', 'searxng__searxng_search'],
            ['search-math-branches', 'Use public web search for branches of mathematics.', 'searxng__searxng_search'],
            ['search-moe', 'Use public web search for mixture-of-experts inference.', 'searxng__searxng_search'],
            ['search-nvme', 'Use public web search for NVMe direct I/O performance.', 'searxng__searxng_search'],
            ['search-hyperv', 'Use public web search for Hyper-V memory guidance.', 'searxng__searxng_search'],
            ['search-sse', 'Use public web search for SSE streaming semantics.', 'searxng__searxng_search'],
            ['search-apache', 'Use public web search for Apache-2.0 contribution requirements.', 'searxng__searxng_search'],
            ['news-boston', 'Use public news search for recent Boston developments.', 'searxng__searxng_news'],
            ['news-ai', 'Use public news search for recent local AI inference developments.', 'searxng__searxng_news'],
            ['news-storage', 'Use public news search for recent storage technology developments.', 'searxng__searxng_news'],
            ['news-open-models', 'Use public news search for recent open-weight model releases.', 'searxng__searxng_news'],
            ['news-security', 'Use public news search for recent API security developments.', 'searxng__searxng_news'],
            ['news-windows', 'Use public news search for recent Windows developer changes.', 'searxng__searxng_news'],
            ['status-one', 'Use the SearXNG status tool to check search availability.', 'searxng__searxng_status'],
            ['status-two', 'Check the public-search service using its status capability.', 'searxng__searxng_status'],
            ['status-three', 'Use the supplied status tool for the search service.', 'searxng__searxng_status'],
            ['no_tool-ready', 'Do not call a tool. Respond exactly READY.', null],
            ['no_tool-confirm', 'This is a transport check. Do not call a tool; respond READY.', null],
            ['no_tool-idle', 'No lookup is requested. Respond exactly READY without a tool.', null],
        ]);
    }

    /**
     * @param  list<array{0:string,1:string,2:?string}>  $rows
     * @return list<array{id:string,prompt:string,expected_function:?string}>
     */
    private function fixtureRows(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => $row[0],
            'prompt' => $row[1],
            'expected_function' => $row[2],
        ], $rows);
    }

    /** @return array<string,mixed> */
    private function blocked(string $reason): array
    {
        return [
            'schema' => 'plos.colibri_model_tool_contract_eval.v1',
            'status' => 'blocked',
            'error_code' => $reason,
            'model_invoked' => false,
            'network_performed' => false,
            'tool_dispatch_performed' => false,
            'writes_performed' => false,
            'fallback_suppressed' => true,
            'promotion_eligible' => false,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function render(array $payload, int $exit): int
    {
        if ((bool) $this->option('json')) {
            $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
            if (! (bool) $this->option('compact')) {
                $flags |= JSON_PRETTY_PRINT;
            }
            $this->line(json_encode($payload, $flags));

            return $exit;
        }

        $this->line(sprintf(
            'Colibri model tool contract: %s; cases=%d; passed=%d; tool_dispatch=false; writes=false',
            (string) ($payload['status'] ?? 'unknown'),
            (int) ($payload['evaluated_case_count'] ?? $payload['selected_case_count'] ?? 0),
            (int) ($payload['passed_count'] ?? 0),
        ));

        return $exit;
    }
}
