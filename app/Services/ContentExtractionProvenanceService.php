<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContentExtractionProvenanceService
{
    public const METADATA_KEY = '_plos_content_extraction';

    public const SCHEMA = 'plos_content_extraction_provenance.v1';

    public const AUDIT_SCHEMA = 'plos_content_extraction_provenance_audit.v1';

    private const PAGED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'odt', 'ppt', 'pptx', 'tif', 'tiff',
    ];

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function attach(array $result, array $context = []): array
    {
        $result['provenance'] = $this->build($result, $context);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(array $result, array $context = []): array
    {
        $text = is_string($result['text'] ?? null) ? $result['text'] : '';
        $extension = strtolower(ltrim($this->nullableString(
            $context['extension'] ?? pathinfo((string) ($result['filename'] ?? ''), PATHINFO_EXTENSION)
        ) ?? '', '.'));
        $extractor = $this->identifier($result['method'] ?? $result['extractor'] ?? null) ?? 'unknown';
        $pipeline = $this->identifier($result['pipeline'] ?? null) ?? 'unknown';
        $pipelineVersion = $this->identifier($result['extraction_version'] ?? null);
        $provider = $this->identifier($result['provider'] ?? null);
        $inputSha256 = $this->sha256($result['input_source_sha256'] ?? null);
        $attempts = $this->attemptContract($result['attempts'] ?? null);
        $confidence = $this->confidenceContract($result);
        $pageProvenance = $this->pageProvenanceContract($result, $extension);
        $review = $this->reviewContract($result, $text, $confidence, $pageProvenance);

        $contract = [
            'schema' => self::SCHEMA,
            'contract' => 'content-extraction-v1',
            'extractor' => $extractor,
            'pipeline' => $pipeline,
            'pipeline_version' => $pipelineVersion,
            'provider' => $provider,
        ];
        if ($inputSha256 !== null) {
            $contract['input_sha256'] = $inputSha256;
        }

        return [
            ...$contract,
            'contract_fingerprint' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR)),
            'output_sha256' => hash('sha256', $text),
            'text_chars' => mb_strlen($text),
            'non_whitespace_chars' => mb_strlen((string) preg_replace('/\s+/u', '', $text)),
            'duration_ms' => max(0, (int) ($result['duration_ms'] ?? 0)),
            'attempts' => $attempts,
            'confidence' => $confidence,
            'page_provenance' => $pageProvenance,
            'review' => $review,
            'recorded_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decode(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        } elseif (is_object($value)) {
            $value = (array) $value;
        }

        if (! is_array($value)
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($value['contract_fingerprint'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($value['output_sha256'] ?? ''))
            || (array_key_exists('input_sha256', $value) && $value['input_sha256'] !== null
                && ! preg_match('/^[a-f0-9]{64}$/D', (string) $value['input_sha256']))) {
            return null;
        }

        $contract = [
            'schema' => self::SCHEMA,
            'contract' => $value['contract'] ?? null,
            'extractor' => $value['extractor'] ?? null,
            'pipeline' => $value['pipeline'] ?? null,
            'pipeline_version' => $value['pipeline_version'] ?? null,
            'provider' => $value['provider'] ?? null,
        ];
        if (isset($value['input_sha256'])) {
            $contract['input_sha256'] = $value['input_sha256'];
        }
        $expectedFingerprint = hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR));
        if (! hash_equals($expectedFingerprint, (string) $value['contract_fingerprint'])) {
            return null;
        }

        return $value;
    }

    private function sha256(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        try {
            $summary = DB::selectOne("SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN p.metadata IS NOT NULL THEN 1 ELSE 0 END) AS captured,
                    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.schema')) = ? THEN 1 ELSE 0 END) AS current_schema,
                    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.schema')) = ?
                        AND (NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.contract_fingerprint')), '') IS NULL
                            OR NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.output_sha256')), '') IS NULL)
                        THEN 1 ELSE 0 END) AS incomplete,
                    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.schema')) = ?
                        AND JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.review.state')) = 'review_required' THEN 1 ELSE 0 END) AS review_required,
                    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.schema')) = ?
                        AND JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.review.state')) = 'ready' THEN 1 ELSE 0 END) AS ready,
                    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.schema')) = ?
                        AND JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.page_provenance.state')) = 'complete' THEN 1 ELSE 0 END) AS page_complete
                FROM file_registry fr
                LEFT JOIN file_content_extraction_provenance p ON p.file_registry_id = fr.id
                WHERE fr.status = 'active' AND fr.ai_detected_text IS NOT NULL AND fr.ai_detected_text <> ''", [
                self::SCHEMA,
                self::SCHEMA,
                self::SCHEMA,
                self::SCHEMA,
                self::SCHEMA,
            ]);

            $contracts = DB::select("SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.contract_fingerprint')) AS contract_fingerprint,
                    JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.extractor')) AS extractor,
                    JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.pipeline')) AS pipeline,
                    JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.pipeline_version')) AS pipeline_version,
                    JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.provider')) AS provider,
                    JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.review.state')) AS review_state,
                    COUNT(*) AS documents,
                    MIN(fr.ai_analyzed_at) AS first_analyzed_at,
                    MAX(fr.ai_analyzed_at) AS last_analyzed_at
                FROM file_content_extraction_provenance p
                INNER JOIN file_registry fr ON fr.id = p.file_registry_id
                WHERE fr.status = 'active'
                  AND fr.ai_detected_text IS NOT NULL
                  AND fr.ai_detected_text <> ''
                  AND JSON_UNQUOTE(JSON_EXTRACT(p.metadata, '$.schema')) = ?
                GROUP BY 1, 2, 3, 4, 5, 6
                ORDER BY documents DESC, contract_fingerprint
                LIMIT ?", [self::SCHEMA, $limit]);
        } catch (\Throwable $e) {
            Log::warning('Content extraction provenance audit failed', ['exception' => $e::class]);

            return [
                'schema' => self::AUDIT_SCHEMA,
                'mode' => 'observe',
                'read_only' => true,
                'status' => 'unavailable',
                'evidence_error' => 'content_extraction_provenance_query_failed',
                'summary' => null,
                'contracts' => [],
            ];
        }

        $total = max(0, (int) ($summary->total ?? 0));
        $captured = max(0, (int) ($summary->captured ?? 0));
        $current = max(0, (int) ($summary->current_schema ?? 0));
        $incomplete = max(0, (int) ($summary->incomplete ?? 0));
        $missing = max(0, $total - $captured);
        $oldSchema = max(0, $captured - $current);

        return [
            'schema' => self::AUDIT_SCHEMA,
            'mode' => 'observe',
            'read_only' => true,
            'status' => ($missing === 0 && $oldSchema === 0 && $incomplete === 0) ? 'observe_ok' : 'observe_debt',
            'evidence_error' => null,
            'summary' => [
                'documents' => $total,
                'captured' => $captured,
                'current_schema' => $current,
                'legacy_or_missing' => $missing,
                'old_schema' => $oldSchema,
                'incomplete' => $incomplete,
                'review_required' => max(0, (int) ($summary->review_required ?? 0)),
                'ready' => max(0, (int) ($summary->ready ?? 0)),
                'page_complete' => max(0, (int) ($summary->page_complete ?? 0)),
                'coverage_percent' => $total === 0 ? 100.0 : round(($current / $total) * 100, 2),
            ],
            'contracts' => array_map(fn (object $row): array => [
                'contract_fingerprint' => (string) ($row->contract_fingerprint ?? ''),
                'extractor' => $this->nullableString($row->extractor ?? null),
                'pipeline' => $this->nullableString($row->pipeline ?? null),
                'pipeline_version' => $this->nullableString($row->pipeline_version ?? null),
                'provider' => $this->nullableString($row->provider ?? null),
                'review_state' => $this->nullableString($row->review_state ?? null),
                'documents' => max(0, (int) ($row->documents ?? 0)),
                'first_analyzed_at' => $this->nullableString($row->first_analyzed_at ?? null),
                'last_analyzed_at' => $this->nullableString($row->last_analyzed_at ?? null),
            ], $contracts),
        ];
    }

    /** @return array<string, bool> */
    private function attemptContract(mixed $attempts): array
    {
        if (! is_array($attempts)) {
            return [];
        }

        $contract = [];
        foreach ($attempts as $name => $value) {
            $name = $this->identifier($name);
            if ($name === null || (! is_bool($value) && ! in_array($value, [0, 1, '0', '1'], true))) {
                continue;
            }
            $contract[$name] = (bool) $value;
        }
        ksort($contract);

        return array_slice($contract, 0, 16, true);
    }

    /** @return array{mean_grade: ?string, low_grade: ?string, score: ?float, source: ?string} */
    private function confidenceContract(array $result): array
    {
        $raw = is_array($result['confidence_report'] ?? null)
            ? $result['confidence_report']
            : (is_array($result['confidence'] ?? null) ? $result['confidence'] : []);
        $score = $raw['score'] ?? $raw['mean_score'] ?? null;

        return [
            'mean_grade' => $this->grade($raw['mean_grade'] ?? null),
            'low_grade' => $this->grade($raw['low_grade'] ?? null),
            'score' => is_numeric($score) ? round(max(0.0, min(1.0, (float) $score)), 4) : null,
            'source' => $raw === [] ? null : ($this->identifier($raw['source'] ?? null) ?? 'extractor_report'),
        ];
    }

    /** @return array{state: string, page_count: int, covered_pages: int} */
    private function pageProvenanceContract(array $result, string $extension): array
    {
        $raw = is_array($result['page_provenance'] ?? null) ? $result['page_provenance'] : null;
        if ($raw !== null) {
            $pageCount = max(0, (int) ($raw['page_count'] ?? 0));
            $covered = max(0, min($pageCount, (int) ($raw['covered_pages'] ?? 0)));

            return [
                'state' => $pageCount > 0 && $covered === $pageCount ? 'complete' : ($covered > 0 ? 'partial' : 'unavailable'),
                'page_count' => $pageCount,
                'covered_pages' => $covered,
            ];
        }

        return [
            'state' => in_array($extension, self::PAGED_EXTENSIONS, true) ? 'unavailable' : 'not_applicable',
            'page_count' => 0,
            'covered_pages' => 0,
        ];
    }

    /** @return array{state: string, reasons: array<int, string>} */
    private function reviewContract(array $result, string $text, array $confidence, array $pageProvenance): array
    {
        $reasons = [];
        if (! ($result['success'] ?? false) || trim($text) === '') {
            return ['state' => 'extraction_failed', 'reasons' => ['no_extracted_text']];
        }

        if (in_array($confidence['low_grade'], ['poor', 'fair'], true)
            || $confidence['mean_grade'] === 'poor') {
            $reasons[] = 'low_extraction_confidence';
        }
        if ($reasons !== []) {
            return ['state' => 'review_required', 'reasons' => $reasons];
        }
        if (in_array($pageProvenance['state'], ['unavailable', 'partial'], true)) {
            return ['state' => 'provenance_incomplete', 'reasons' => ['page_provenance_incomplete']];
        }

        return ['state' => 'ready', 'reasons' => []];
    }

    private function grade(mixed $value): ?string
    {
        $grade = strtolower(trim(is_scalar($value) ? (string) $value : ''));

        return in_array($grade, ['poor', 'fair', 'good', 'excellent'], true) ? $grade : null;
    }

    private function identifier(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        $value = strtolower((string) preg_replace('/[^a-zA-Z0-9_.:\/-]+/', '_', $value));

        return substr(trim($value, '_'), 0, 120) ?: null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
