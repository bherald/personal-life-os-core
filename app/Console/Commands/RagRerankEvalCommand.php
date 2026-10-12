<?php

namespace App\Console\Commands;

use App\Services\Ops\RagClaimGroundingService;
use App\Services\Ops\RagRerankEvalService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RagRerankEvalCommand extends Command
{
    protected $signature = 'rag:rerank-eval
        {--use-embeddings : Evaluate the current table-routed embedding provider instead of lexical baseline}
        {--use-reranker-sidecar : Evaluate the authenticated loopback cross-encoder sidecar}
        {--json : Emit machine-readable JSON}
        {--compact : Omit per-case detail}
        {--candidate-file= : JSON claim/citation envelope for deterministic generator grounding evaluation}
        {--fail-on-regression : Return failure when ranking or provider regression thresholds fail}
        {--require-promotion-ready : Return failure unless a reranker candidate meets the stricter promotion thresholds}
        {--require-grounding-ready : Return failure unless the supplied generator candidate passes grounding thresholds}';

    protected $description = 'Run privacy-safe retrieval fixtures against lexical, embedding, or loopback cross-encoder reranking';

    public function handle(RagRerankEvalService $service): int
    {
        $useEmbeddings = (bool) $this->option('use-embeddings');
        $useSidecar = (bool) $this->option('use-reranker-sidecar');
        if ($useEmbeddings && $useSidecar) {
            $this->error('--use-embeddings and --use-reranker-sidecar are mutually exclusive.');

            return self::FAILURE;
        }

        try {
            $claimCandidate = $this->loadCandidate();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $payload = $service->evaluate(
            $useEmbeddings,
            useRerankerSidecar: $useSidecar,
            claimCandidate: $claimCandidate,
        );
        $output = (bool) $this->option('compact') ? $this->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode rerank eval JSON.');

                return self::FAILURE;
            }
            $this->line($json);
        } else {
            $this->line(sprintf(
                'RAG rerank eval: %s  mode=%s  cases=%d  mrr=%.4f  hit@1=%.4f  ndcg@3=%.4f  recall@3=%.4f  precision@3=%.4f  map@3=%.4f  hard-neg@1=%.4f',
                strtoupper((string) ($payload['status'] ?? 'fail')),
                (string) ($payload['mode'] ?? 'unknown'),
                (int) data_get($payload, 'summary.cases', 0),
                (float) data_get($payload, 'metrics.mrr', 0),
                (float) data_get($payload, 'metrics.hit_at_1', 0),
                (float) data_get($payload, 'metrics.ndcg_at_3', 0),
                (float) data_get($payload, 'metrics.recall_at_3', 0),
                (float) data_get($payload, 'metrics.precision_at_3', 0),
                (float) data_get($payload, 'metrics.map_at_3', 0),
                (float) data_get($payload, 'metrics.hard_negative_at_1_rate', 0),
            ));
        }

        if ($this->option('fail-on-regression') && ($payload['status'] ?? 'fail') !== 'pass') {
            return self::FAILURE;
        }

        if ($this->option('require-promotion-ready') && ! data_get($payload, 'summary.promotion_ready', false)) {
            return self::FAILURE;
        }

        return $this->option('require-grounding-ready')
            && ! data_get($payload, 'grounding.generator.generation_ready', false)
                ? self::FAILURE
                : self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function compactPayload(array $payload): array
    {
        return [
            'schema' => $payload['schema'] ?? 'plos.rag_rerank_eval.v3',
            'status' => $payload['status'] ?? 'fail',
            'mode' => $payload['mode'] ?? 'unknown',
            'compact' => true,
            'generated_at' => $payload['generated_at'] ?? null,
            'metrics' => $payload['metrics'] ?? [],
            'thresholds' => $payload['thresholds'] ?? [],
            'promotion_thresholds' => $payload['promotion_thresholds'] ?? [],
            'summary' => $payload['summary'] ?? [],
            'grounding' => $this->compactGrounding((array) ($payload['grounding'] ?? [])),
            'posture' => $payload['posture'] ?? [],
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadCandidate(): ?array
    {
        $path = trim((string) ($this->option('candidate-file') ?? ''));
        if ($path === '') {
            return null;
        }

        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Candidate file must be a readable regular file, not a symlink.');
        }
        $size = filesize($path);
        if ($size === false || $size > 262144) {
            throw new InvalidArgumentException('Candidate file exceeds the 256 KiB limit.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Candidate file must contain a JSON object using '.RagClaimGroundingService::CANDIDATE_SCHEMA.'.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $grounding @return array<string,mixed> */
    private function compactGrounding(array $grounding): array
    {
        return [
            'schema' => $grounding['schema'] ?? RagClaimGroundingService::SCHEMA,
            'candidate_schema' => $grounding['candidate_schema'] ?? RagClaimGroundingService::CANDIDATE_SCHEMA,
            'retriever' => $grounding['retriever'] ?? [],
            'generator' => $grounding['generator'] ?? [],
            'posture' => $grounding['posture'] ?? [],
        ];
    }
}
