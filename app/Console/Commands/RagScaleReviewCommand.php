<?php

namespace App\Console\Commands;

use App\Services\RagBacklogService;
use Illuminate\Console\Command;

class RagScaleReviewCommand extends Command
{
    protected $signature = 'rag:scale-review
        {--retrieval-file= : Optional rag:retrieval-evidence --json file to compare without rerunning retrieval}
        {--net-burn-days=7 : Net-burn window, capped by RagBacklogService}
        {--json : Emit machine-readable JSON}
        {--markdown : Emit Markdown}';

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
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($this->toMarkdown($payload));

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
        $retrieval = $this->loadRetrievalEvidence();
        $scale = $rag->getScaleBaseline();
        $backlog = $rag->getDigestMetrics();
        $netBurn = $rag->getNetBurn((int) $this->option('net-burn-days'));
        $status = $this->overallStatus($scale, $backlog, $netBurn, $retrieval);

        return [
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
                'query_set_hash' => null,
                'query_count' => 0,
                'successful_count' => 0,
                'empty_count' => 0,
                'failed_count' => 0,
                'include_results' => false,
                'result_content_redacted' => true,
                'latency_p95_ms' => null,
                'top_similarity_avg' => null,
                'evidence_contract_version' => null,
            ];
        }

        $path = $this->resolvePath($file);
        if (! is_file($path)) {
            throw new \InvalidArgumentException('Retrieval evidence file not found: '.$file);
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
            'query_set_hash' => $this->nullableString($decoded['query_set_hash'] ?? null),
            'query_count' => (int) ($decoded['query_count'] ?? 0),
            'successful_count' => (int) ($decoded['successful_count'] ?? 0),
            'empty_count' => (int) ($decoded['empty_count'] ?? 0),
            'failed_count' => (int) ($decoded['failed_count'] ?? 0),
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
        ];
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
}
