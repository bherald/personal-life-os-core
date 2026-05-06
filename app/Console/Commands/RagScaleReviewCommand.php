<?php

namespace App\Console\Commands;

use App\Services\RagBacklogService;
use Illuminate\Console\Command;

class RagScaleReviewCommand extends Command
{
    protected $signature = 'rag:scale-review
        {--retrieval-file= : Optional rag:retrieval-evidence --json file to compare without rerunning retrieval}
        {--previous-file= : Optional previous rag:scale-review --json/--compact JSON file for local delta evidence}
        {--net-burn-days=7 : Net-burn window, capped by RagBacklogService}
        {--json : Emit machine-readable JSON}
        {--markdown : Emit Markdown}
        {--compact : Emit compact operator/MCP evidence}';

    protected $description = 'Observe-only TODO-018 review combining RAG scale, backlog, net-burn, and redacted retrieval evidence';

    public function handle(RagBacklogService $rag): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        try {
            $payload = $this->buildPayload($rag);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $output = $this->option('compact') ? $this->compactPayload($payload) : $payload;
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($this->option('compact') ? $this->toCompactMarkdown($payload) : $this->toMarkdown($payload));

            return self::SUCCESS;
        }

        if ($this->option('compact')) {
            $this->line($this->toCompactText($payload));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'RAG scale review: %s docs=%s kg_pending=%s net_burn=%s retrieval=%s query_set=%s latency_p95=%s captured=%s',
            $payload['status'],
            $payload['scale']['documents'],
            $payload['backlog']['kg_pending'],
            $payload['net_burn']['kg_trend'] ?? 'unknown',
            $payload['retrieval']['status'],
            $payload['retrieval']['query_set_hash'] ?? 'n/a',
            $payload['retrieval']['latency_p95_ms'] ?? 'n/a',
            $payload['captured_at']
        ));

        foreach ($payload['recommendations'] as $recommendation) {
            $this->warn('scale-review: '.$recommendation);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(RagBacklogService $rag): array
    {
        $previous = $this->loadPreviousReviewArtifact();
        $retrieval = $this->loadRetrievalEvidence();
        $scale = $rag->getScaleBaseline();
        $backlog = $rag->getDigestMetrics();
        $netBurn = $rag->getNetBurn((int) $this->option('net-burn-days'));
        $status = $this->overallStatus($scale, $backlog, $netBurn, $retrieval);

        $payload = [
            'version' => 1,
            'mode' => 'observe',
            'status' => $status,
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'scale' => $this->scaleSummary($scale),
            'backlog' => $this->backlogSummary($backlog),
            'net_burn' => $this->netBurnSummary($netBurn),
            'retrieval' => $retrieval,
            'recommendations' => $this->recommendations($scale, $backlog, $netBurn, $retrieval),
            'note' => 'Scale review is read-only; it does not run retrieval, change indexing, tune jobs, or expose query/result text.',
        ];

        if ($previous !== null) {
            $payload['comparison'] = $this->comparisonSummary(
                $previous,
                $this->normalizeReviewArtifact($payload)
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        $scale = is_array($payload['scale'] ?? null) ? $payload['scale'] : [];
        $backlog = is_array($payload['backlog'] ?? null) ? $payload['backlog'] : [];
        $netBurn = is_array($payload['net_burn'] ?? null) ? $payload['net_burn'] : [];
        $retrieval = is_array($payload['retrieval'] ?? null) ? $payload['retrieval'] : [];

        $compact = [
            'version' => (int) ($payload['version'] ?? 1),
            'mode' => (string) ($payload['mode'] ?? 'observe'),
            'compact' => true,
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'captured_at' => $payload['captured_at'] ?? null,
            'scale' => [
                'status' => (string) ($scale['status'] ?? 'unknown'),
                'documents' => (int) ($scale['documents'] ?? 0),
                'evidence_error_count' => (int) ($scale['evidence_error_count'] ?? 0),
            ],
            'backlog' => [
                'documents' => (int) ($backlog['documents'] ?? 0),
                'kg_pending' => (int) ($backlog['kg_pending'] ?? 0),
                'kg_fresh_pending' => (int) ($backlog['kg_fresh_pending'] ?? 0),
                'kg_stale_pending' => (int) ($backlog['kg_stale_pending'] ?? 0),
                'kg_eta_days' => $backlog['kg_eta_days'] ?? null,
                'raptor_pending' => (int) ($backlog['raptor_pending'] ?? 0),
                'sentence_pending' => (int) ($backlog['sentence_pending'] ?? 0),
                'evidence_error_count' => (int) ($backlog['evidence_error_count'] ?? 0),
            ],
            'net_burn' => [
                'window_days' => (int) ($netBurn['window_days'] ?? 0),
                'kg_net_burn_per_day' => $netBurn['kg_net_burn_per_day'] ?? null,
                'kg_trend' => $netBurn['kg_trend'] ?? null,
                'kg_samples' => (int) ($netBurn['kg_samples'] ?? 0),
                'evidence_error_count' => (int) ($netBurn['evidence_error_count'] ?? 0),
            ],
            'retrieval' => [
                'provided' => (bool) ($retrieval['provided'] ?? false),
                'status' => (string) ($retrieval['status'] ?? 'unknown'),
                'query_set_hash' => $retrieval['query_set_hash'] ?? null,
                'query_set_hash_basis' => $retrieval['query_set_hash_basis'] ?? null,
                'query_count' => (int) ($retrieval['query_count'] ?? 0),
                'successful_count' => (int) ($retrieval['successful_count'] ?? 0),
                'empty_count' => (int) ($retrieval['empty_count'] ?? 0),
                'failed_count' => (int) ($retrieval['failed_count'] ?? 0),
                'limit' => $retrieval['limit'] ?? null,
                'default_type' => $retrieval['default_type'] ?? null,
                'latency_p95_ms' => $retrieval['latency_p95_ms'] ?? null,
                'top_similarity_avg' => $retrieval['top_similarity_avg'] ?? null,
                'result_content_redacted' => (bool) ($retrieval['result_content_redacted'] ?? true),
                'evidence_contract_mode' => $retrieval['evidence_contract_mode'] ?? null,
                'query_text_policy' => $retrieval['query_text_policy'] ?? null,
                'results_policy' => $retrieval['results_policy'] ?? null,
            ],
            'evidence_error_count' => (int) ($scale['evidence_error_count'] ?? 0)
                + (int) ($backlog['evidence_error_count'] ?? 0)
                + (int) ($netBurn['evidence_error_count'] ?? 0),
            'recommendation_count' => count(is_array($payload['recommendations'] ?? null) ? $payload['recommendations'] : []),
        ];

        if (is_array($payload['comparison'] ?? null)) {
            $compact['comparison'] = $payload['comparison'];
        }

        return $compact;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toCompactText(array $payload): string
    {
        $compact = $this->compactPayload($payload);
        $scale = $compact['scale'];
        $backlog = $compact['backlog'];
        $netBurn = $compact['net_burn'];
        $retrieval = $compact['retrieval'];

        return implode("\n", [
            sprintf(
                'RAG scale review compact: %s captured=%s docs=%s kg_pending=%s kg_eta_days=%s net_burn=%s trend=%s retrieval=%s query_set=%s query_count=%s p95_ms=%s errors=%s recommendations=%s',
                $compact['status'],
                $compact['captured_at'] ?? '-',
                $scale['documents'],
                $backlog['kg_pending'],
                $backlog['kg_eta_days'] ?? 'n/a',
                $netBurn['kg_net_burn_per_day'] ?? 'n/a',
                $netBurn['kg_trend'] ?? 'n/a',
                $retrieval['status'],
                $retrieval['query_set_hash'] ?? 'n/a',
                $retrieval['query_count'],
                $retrieval['latency_p95_ms'] ?? 'n/a',
                $compact['evidence_error_count'],
                $compact['recommendation_count'],
            ),
            sprintf(
                'rag_scale compact: backlog_fresh=%s backlog_stale=%s raptor_pending=%s sentence_pending=%s retrieval_redacted=%s query_policy=%s results_policy=%s',
                $backlog['kg_fresh_pending'],
                $backlog['kg_stale_pending'],
                $backlog['raptor_pending'],
                $backlog['sentence_pending'],
                $retrieval['result_content_redacted'] ? 'true' : 'false',
                $retrieval['query_text_policy'] ?? 'n/a',
                $retrieval['results_policy'] ?? 'n/a',
            ),
        ])."\n";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toCompactMarkdown(array $payload): string
    {
        $compact = $this->compactPayload($payload);
        $scale = $compact['scale'];
        $backlog = $compact['backlog'];
        $netBurn = $compact['net_burn'];
        $retrieval = $compact['retrieval'];

        return implode("\n", [
            '# RAG Scale Review Compact',
            '',
            '- Status: `'.$compact['status'].'`',
            '- Captured: `'.($compact['captured_at'] ?? 'unknown').'`',
            '- Documents: `'.$scale['documents'].'`',
            '- KG pending: `'.$backlog['kg_pending'].'`',
            '- KG ETA days: `'.($backlog['kg_eta_days'] ?? 'n/a').'`',
            '- KG net-burn/day: `'.($netBurn['kg_net_burn_per_day'] ?? 'n/a').'`',
            '- KG trend: `'.($netBurn['kg_trend'] ?? 'n/a').'`',
            '- Retrieval status: `'.$retrieval['status'].'`',
            '- Query set hash: `'.($retrieval['query_set_hash'] ?? 'n/a').'`',
            '- Query set hash basis: `'.($retrieval['query_set_hash_basis'] ?? 'n/a').'`',
            '- Query count: `'.$retrieval['query_count'].'`',
            '- Result limit: `'.($retrieval['limit'] ?? 'n/a').'`',
            '- Default type: `'.($retrieval['default_type'] ?? 'n/a').'`',
            '- Evidence contract mode: `'.($retrieval['evidence_contract_mode'] ?? 'n/a').'`',
            '- Query text policy: `'.($retrieval['query_text_policy'] ?? 'n/a').'`',
            '- Results policy: `'.($retrieval['results_policy'] ?? 'n/a').'`',
            '- Result content redacted: `'.($retrieval['result_content_redacted'] ? 'true' : 'false').'`',
            '- Retrieval p95 ms: `'.($retrieval['latency_p95_ms'] ?? 'n/a').'`',
            '- Evidence errors: `'.$compact['evidence_error_count'].'`',
            '- Recommendations: `'.$compact['recommendation_count'].'`',
            '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function scaleSummary(array $payload): array
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $storage = is_array($payload['storage'] ?? null) ? $payload['storage'] : [];
        $postgres = is_array($payload['postgres'] ?? null) ? $payload['postgres'] : [];
        $tableHealth = is_array($postgres['table_health'] ?? null) ? $postgres['table_health'] : [];
        $indexSummary = is_array($postgres['index_summary'] ?? null) ? $postgres['index_summary'] : [];

        return [
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'documents' => (int) ($summary['documents'] ?? 0),
            'content_chars' => (int) ($summary['content_chars'] ?? 0),
            'avg_content_chars' => (int) ($summary['avg_content_chars'] ?? 0),
            'max_content_chars' => (int) ($summary['max_content_chars'] ?? 0),
            'compressed_ratio' => $summary['compressed_ratio'] ?? null,
            'contextualized_ratio' => $summary['contextualized_ratio'] ?? null,
            'total_relation_mb' => $storage['total_relation_mb'] ?? null,
            'total_bytes_per_document' => $storage['total_bytes_per_document'] ?? null,
            'total_bytes_per_content_char' => $storage['total_bytes_per_content_char'] ?? null,
            'dead_tuples' => (int) ($tableHealth['dead_tuples'] ?? 0),
            'dead_tuple_ratio' => $tableHealth['dead_tuple_ratio'] ?? null,
            'index_count' => (int) ($indexSummary['index_count'] ?? 0),
            'zero_scan_indexes' => (int) ($indexSummary['zero_scan_indexes'] ?? 0),
            'invalid_indexes' => (int) ($indexSummary['invalid_indexes'] ?? 0),
            'largest_index_mb' => $indexSummary['largest_index_mb'] ?? null,
            'evidence_error_count' => count(is_array($payload['evidence_errors'] ?? null) ? $payload['evidence_errors'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function backlogSummary(array $payload): array
    {
        $kg = is_array($payload['kg'] ?? null) ? $payload['kg'] : [];
        $raptor = is_array($payload['raptor'] ?? null) ? $payload['raptor'] : [];
        $sentence = is_array($payload['sentence'] ?? null) ? $payload['sentence'] : [];

        return [
            'documents' => (int) ($payload['documents'] ?? 0),
            'kg_pending' => (int) ($kg['pending'] ?? 0),
            'kg_fresh_pending' => (int) ($kg['fresh'] ?? 0),
            'kg_stale_pending' => (int) ($kg['stale'] ?? 0),
            'kg_throughput_per_day' => (int) ($kg['throughput_per_day'] ?? 0),
            'kg_eta_days' => $kg['eta_days'] ?? null,
            'raptor_pending' => (int) ($raptor['pending'] ?? 0),
            'sentence_pending' => (int) ($sentence['pending'] ?? 0),
            'evidence_error_count' => count(is_array($payload['evidence_errors'] ?? null) ? $payload['evidence_errors'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function netBurnSummary(array $payload): array
    {
        $lanes = is_array($payload['lanes'] ?? null) ? $payload['lanes'] : [];
        $kg = is_array($lanes['kg'] ?? null) ? $lanes['kg'] : [];

        return [
            'window_days' => (int) ($payload['window_days'] ?? 0),
            'kg_net_burn_per_day' => $kg['net_burn_per_day'] ?? null,
            'kg_trend' => $kg['trend'] ?? null,
            'kg_samples' => (int) ($kg['samples'] ?? 0),
            'evidence_error_count' => count(is_array($payload['evidence_errors'] ?? null) ? $payload['evidence_errors'] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadRetrievalEvidence(): array
    {
        $file = trim((string) $this->option('retrieval-file'));
        if ($file === '') {
            return [
                'status' => 'not_provided',
                'provided' => false,
                'duration_ms' => null,
                'query_set_hash' => null,
                'query_set_hash_basis' => null,
                'query_count' => 0,
                'successful_count' => 0,
                'empty_count' => 0,
                'failed_count' => 0,
                'limit' => null,
                'default_type' => null,
                'include_results' => false,
                'result_content_redacted' => true,
                'latency_p95_ms' => null,
                'top_similarity_avg' => null,
                'evidence_contract_version' => null,
                'evidence_contract_mode' => null,
                'query_text_policy' => null,
                'results_policy' => null,
            ];
        }

        $path = $this->resolvePath($file);
        if (! is_file($path)) {
            throw new \InvalidArgumentException('Retrieval evidence file not found. Check --retrieval-file path.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Retrieval evidence file must contain a JSON object.');
        }

        $latency = is_array($decoded['latency_summary'] ?? null) ? $decoded['latency_summary'] : [];
        $score = is_array($decoded['score_summary'] ?? null) ? $decoded['score_summary'] : [];
        $contract = is_array($decoded['evidence_contract'] ?? null) ? $decoded['evidence_contract'] : [];

        return [
            'status' => (string) ($decoded['status'] ?? 'unknown'),
            'provided' => true,
            'duration_ms' => $this->nullableInt($decoded['duration_ms'] ?? null),
            'query_set_hash' => $this->nullableString($decoded['query_set_hash'] ?? null),
            'query_set_hash_basis' => $this->nullableString($contract['query_set_hash_basis'] ?? null),
            'query_count' => (int) ($decoded['query_count'] ?? 0),
            'successful_count' => (int) ($decoded['successful_count'] ?? 0),
            'empty_count' => (int) ($decoded['empty_count'] ?? 0),
            'failed_count' => (int) ($decoded['failed_count'] ?? 0),
            'limit' => $this->nullableInt($decoded['limit'] ?? null),
            'default_type' => $this->nullableString($decoded['default_type'] ?? null),
            'include_results' => (bool) ($decoded['include_results'] ?? false),
            'result_content_redacted' => ! (bool) ($decoded['include_results'] ?? false),
            'latency_min_ms' => $latency['query_duration_min_ms'] ?? $latency['min_ms'] ?? null,
            'latency_max_ms' => $latency['query_duration_max_ms'] ?? $latency['max_ms'] ?? null,
            'latency_avg_ms' => $latency['query_duration_avg_ms'] ?? $latency['avg_ms'] ?? null,
            'latency_p95_ms' => $latency['query_duration_p95_ms'] ?? $latency['p95_ms'] ?? null,
            'top_similarity_min' => $score['top_similarity_min'] ?? null,
            'top_similarity_max' => $score['top_similarity_max'] ?? null,
            'top_similarity_avg' => $score['top_similarity_avg'] ?? null,
            'avg_result_count' => $score['avg_result_count'] ?? null,
            'evidence_contract_version' => $contract['version'] ?? null,
            'evidence_contract_mode' => $this->nullableString($contract['mode'] ?? null),
            'query_text_policy' => $this->nullableString($contract['query_text'] ?? null),
            'results_policy' => $this->nullableString($contract['results'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPreviousReviewArtifact(): ?array
    {
        $file = trim((string) $this->option('previous-file'));
        if ($file === '') {
            return null;
        }

        $path = $this->resolvePath($file);
        if (! is_file($path)) {
            throw new \InvalidArgumentException('Previous scale review file not found. Check --previous-file path.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Previous scale review file must contain a JSON object.');
        }

        return $this->normalizeReviewArtifact($decoded);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeReviewArtifact(array $payload): array
    {
        $scale = is_array($payload['scale'] ?? null) ? $payload['scale'] : [];
        $backlog = is_array($payload['backlog'] ?? null) ? $payload['backlog'] : [];
        $netBurn = is_array($payload['net_burn'] ?? null) ? $payload['net_burn'] : [];
        $retrieval = is_array($payload['retrieval'] ?? null) ? $payload['retrieval'] : [];

        return [
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'captured_at' => $this->nullableString($payload['captured_at'] ?? null),
            'documents' => $this->nullableNumber($scale['documents'] ?? $backlog['documents'] ?? null),
            'kg_pending' => $this->nullableNumber($backlog['kg_pending'] ?? null),
            'kg_fresh_pending' => $this->nullableNumber($backlog['kg_fresh_pending'] ?? null),
            'kg_stale_pending' => $this->nullableNumber($backlog['kg_stale_pending'] ?? null),
            'raptor_pending' => $this->nullableNumber($backlog['raptor_pending'] ?? null),
            'sentence_pending' => $this->nullableNumber($backlog['sentence_pending'] ?? null),
            'kg_net_burn_per_day' => $this->nullableNumber($netBurn['kg_net_burn_per_day'] ?? null),
            'query_set_hash' => $this->nullableString($retrieval['query_set_hash'] ?? null),
            'retrieval_p95_ms' => $this->nullableNumber($retrieval['latency_p95_ms'] ?? null),
            'top_similarity_avg' => $this->nullableNumber($retrieval['top_similarity_avg'] ?? null),
            'evidence_error_count' => $this->artifactEvidenceErrorCount($payload, $scale, $backlog, $netBurn),
        ];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function comparisonSummary(array $previous, array $current): array
    {
        return [
            'previous_captured_at' => $previous['captured_at'] ?? null,
            'current_captured_at' => $current['captured_at'] ?? null,
            'same_query_set' => $this->sameQuerySet($previous['query_set_hash'] ?? null, $current['query_set_hash'] ?? null),
            'status_changed' => ($previous['status'] ?? null) !== ($current['status'] ?? null),
            'documents_delta' => $this->deltaNumber($current['documents'] ?? null, $previous['documents'] ?? null),
            'kg_pending_delta' => $this->deltaNumber($current['kg_pending'] ?? null, $previous['kg_pending'] ?? null),
            'kg_fresh_pending_delta' => $this->deltaNumber($current['kg_fresh_pending'] ?? null, $previous['kg_fresh_pending'] ?? null),
            'kg_stale_pending_delta' => $this->deltaNumber($current['kg_stale_pending'] ?? null, $previous['kg_stale_pending'] ?? null),
            'raptor_pending_delta' => $this->deltaNumber($current['raptor_pending'] ?? null, $previous['raptor_pending'] ?? null),
            'sentence_pending_delta' => $this->deltaNumber($current['sentence_pending'] ?? null, $previous['sentence_pending'] ?? null),
            'kg_net_burn_per_day_delta' => $this->deltaNumber($current['kg_net_burn_per_day'] ?? null, $previous['kg_net_burn_per_day'] ?? null),
            'retrieval_p95_ms_delta' => $this->deltaNumber($current['retrieval_p95_ms'] ?? null, $previous['retrieval_p95_ms'] ?? null),
            'top_similarity_avg_delta' => $this->deltaNumber($current['top_similarity_avg'] ?? null, $previous['top_similarity_avg'] ?? null),
            'evidence_error_count_delta' => $this->deltaNumber($current['evidence_error_count'] ?? null, $previous['evidence_error_count'] ?? null),
        ];
    }

    private function sameQuerySet(?string $previous, ?string $current): ?bool
    {
        if ($previous === null && $current === null) {
            return null;
        }

        return $previous === $current;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $scale
     * @param  array<string, mixed>  $backlog
     * @param  array<string, mixed>  $netBurn
     */
    private function artifactEvidenceErrorCount(array $payload, array $scale, array $backlog, array $netBurn): ?int
    {
        $root = $this->nullableInt($payload['evidence_error_count'] ?? null);
        if ($root !== null) {
            return $root;
        }

        return (int) ($scale['evidence_error_count'] ?? 0)
            + (int) ($backlog['evidence_error_count'] ?? 0)
            + (int) ($netBurn['evidence_error_count'] ?? 0);
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return rtrim((string) getenv('HOME'), '/').substr($path, 1);
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /**
     * @param  array<string, mixed>  $scale
     * @param  array<string, mixed>  $backlog
     * @param  array<string, mixed>  $netBurn
     * @param  array<string, mixed>  $retrieval
     */
    private function overallStatus(array $scale, array $backlog, array $netBurn, array $retrieval): string
    {
        if (($scale['status'] ?? null) !== 'observe_ok') {
            return 'observe_warning';
        }

        if ((is_array($backlog['evidence_errors'] ?? null) ? $backlog['evidence_errors'] : []) !== []) {
            return 'observe_warning';
        }

        if ((is_array($netBurn['evidence_errors'] ?? null) ? $netBurn['evidence_errors'] : []) !== []) {
            return 'observe_warning';
        }

        if (($retrieval['provided'] ?? false) && ($retrieval['failed_count'] ?? 0) > 0) {
            return 'observe_warning';
        }

        return 'observe_ok';
    }

    /**
     * @param  array<string, mixed>  $scale
     * @param  array<string, mixed>  $backlog
     * @param  array<string, mixed>  $netBurn
     * @param  array<string, mixed>  $retrieval
     * @return list<string>
     */
    private function recommendations(array $scale, array $backlog, array $netBurn, array $retrieval): array
    {
        $recommendations = [
            'Keep TODO-018 in observe/planning mode until repeated scale, backlog, net-burn, and retrieval evidence agree.',
        ];

        if (! ($retrieval['provided'] ?? false)) {
            $recommendations[] = 'Run rag:retrieval-evidence --json for an approved redacted query set and pass it with --retrieval-file before structural RAG changes.';
        }

        if ((int) ($backlog['kg']['pending'] ?? 0) > 0) {
            $recommendations[] = 'Keep KG backlog/net-burn separate from storage or index tuning decisions.';
        }

        $postgres = is_array($scale['postgres'] ?? null) ? $scale['postgres'] : [];
        $indexSummary = is_array($postgres['index_summary'] ?? null) ? $postgres['index_summary'] : [];
        if ((int) ($indexSummary['zero_scan_indexes'] ?? 0) > 0) {
            $recommendations[] = 'Treat zero-scan index evidence as a review signal only until stats-reset timing and retrieval query evidence are compared.';
        }

        if (($retrieval['provided'] ?? false) && ! ($retrieval['result_content_redacted'] ?? true)) {
            $recommendations[] = 'Retrieval input included result content; keep public or shared notes to aggregate fields only.';
        }

        return $recommendations;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toMarkdown(array $payload): string
    {
        return implode("\n", [
            '# RAG Scale Review',
            '',
            '- Mode: `'.$payload['mode'].'`',
            '- Status: `'.$payload['status'].'`',
            '- Captured: `'.$payload['captured_at'].'`',
            '- Documents: `'.$payload['scale']['documents'].'`',
            '- KG pending: `'.$payload['backlog']['kg_pending'].'`',
            '- KG net-burn/day: `'.($payload['net_burn']['kg_net_burn_per_day'] ?? 'n/a').'`',
            '- KG trend: `'.($payload['net_burn']['kg_trend'] ?? 'n/a').'`',
            '- Retrieval status: `'.$payload['retrieval']['status'].'`',
            '- Query set hash: `'.($payload['retrieval']['query_set_hash'] ?? 'n/a').'`',
            '- Query set hash basis: `'.($payload['retrieval']['query_set_hash_basis'] ?? 'n/a').'`',
            '- Query count: `'.($payload['retrieval']['query_count'] ?? 0).'`',
            '- Result limit: `'.($payload['retrieval']['limit'] ?? 'n/a').'`',
            '- Query text policy: `'.($payload['retrieval']['query_text_policy'] ?? 'n/a').'`',
            '- Results policy: `'.($payload['retrieval']['results_policy'] ?? 'n/a').'`',
            '- Retrieval p95 ms: `'.($payload['retrieval']['latency_p95_ms'] ?? 'n/a').'`',
            '',
            '## Recommendations',
            '',
            ...array_map(fn (string $line): string => '- '.$line, $payload['recommendations']),
            '',
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }

    private function deltaNumber(mixed $current, mixed $previous): int|float|null
    {
        $current = $this->nullableNumber($current);
        $previous = $this->nullableNumber($previous);
        if ($current === null || $previous === null) {
            return null;
        }

        $delta = round((float) $current - (float) $previous, 4);

        return floor($delta) === $delta ? (int) $delta : $delta;
    }
}
