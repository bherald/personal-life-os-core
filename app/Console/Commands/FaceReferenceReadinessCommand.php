<?php

namespace App\Console\Commands;

use App\Services\Review\GlobalFaceIdentityReviewService;
use Illuminate\Console\Command;
use JsonException;

class FaceReferenceReadinessCommand extends Command
{
    private const MAX_SELECTORS = 5;

    private const MAX_SAFE_SELECTOR = 9007199254740991;

    private const MAX_REPORT_BYTES = 245760;

    protected $signature = 'files:face-reference-readiness
        {--face-id=* : Explicit unique file_registry_faces IDs (1–5)}
        {--json : Emit the private read-only readiness report as JSON}';

    protected $description = 'Read-only benchmark eligibility evidence for explicitly selected face references';

    public function handle(GlobalFaceIdentityReviewService $service): int
    {
        $ids = $this->faceIds();
        if ($ids === null) {
            return $this->finish($this->incomplete('invalid_face_ids'), self::FAILURE);
        }
        if (! (bool) $this->option('json')) {
            return $this->finish($this->incomplete('json_required'), self::FAILURE);
        }

        try {
            $report = $service->legacyReferenceCandidates($ids);
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($json) > self::MAX_REPORT_BYTES || ! $this->validReport($report, $ids)) {
                return $this->finish($this->incomplete('report_incomplete_or_over_limit'), self::FAILURE);
            }

            $this->line($json);

            return self::SUCCESS;
        } catch (JsonException) {
            return $this->finish($this->incomplete('report_encoding_failed'), self::FAILURE);
        } catch (\Throwable) {
            return $this->finish($this->incomplete('reference_read_failed'), self::FAILURE);
        }
    }

    /** @param array<string,mixed> $report @param list<int> $ids */
    private function validReport(array $report, array $ids): bool
    {
        if (($report['read_only'] ?? null) !== true
            || ($report['identity_assignments'] ?? null) !== false
            || ! is_array($report['items'] ?? null)
            || ! array_is_list($report['items'])
            || count($report['items']) !== count($ids)) {
            return false;
        }

        foreach ($report['items'] as $index => $item) {
            if (! is_array($item)
                || ($item['face_id'] ?? null) !== $ids[$index]
                || ! is_array($item['holds'] ?? null)
                || ! array_is_list($item['holds'])
                || ! $this->isStringList($item['holds'])
                || ! is_array($item['benchmark_holds'] ?? null)
                || ! array_is_list($item['benchmark_holds'])
                || ! $this->isStringList($item['benchmark_holds'])
                || ! is_bool($item['benchmark_eligible'] ?? null)) {
                return false;
            }

            if ($item['benchmark_eligible'] && ($item['holds'] !== [] || $item['benchmark_holds'] !== [])) {
                return false;
            }
        }

        return true;
    }

    /** @param list<mixed> $values */
    private function isStringList(array $values): bool
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<int>|null */
    private function faceIds(): ?array
    {
        $values = $this->option('face-id');
        if (! is_array($values) || $values === [] || count($values) > self::MAX_SELECTORS) {
            return null;
        }

        $ids = [];
        foreach ($values as $value) {
            if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
                return null;
            }
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id) || $id > self::MAX_SAFE_SELECTOR || in_array($id, $ids, true)) {
                return null;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /** @return array{schema:string,status:string,complete:bool,read_only:bool,identity_assignments:bool,items:array,error_code:string} */
    private function incomplete(string $errorCode): array
    {
        return [
            'schema' => 'plos.face_reference_readiness_cli.v1',
            'status' => 'incomplete',
            'complete' => false,
            'read_only' => true,
            'identity_assignments' => false,
            'items' => [],
            'error_code' => $errorCode,
        ];
    }

    /** @param array<string,mixed> $report */
    private function finish(array $report, int $exitCode): int
    {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $exitCode;
    }
}
