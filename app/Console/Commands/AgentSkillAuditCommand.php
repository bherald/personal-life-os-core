<?php

namespace App\Console\Commands;

use App\Services\AgentSkillAuditService;
use Illuminate\Console\Command;

class AgentSkillAuditCommand extends Command
{
    protected $signature = 'agents:skill-audit
        {--path= : SKILL.md file, skill directory, or skill root to audit}
        {--skill= : Skill name under the configured PLOS skill root}
        {--format=human : Output format: human or json}
        {--use-llm : Allow SkillSpector LLM semantic analysis}
        {--require-sidecar : Return failure if SkillSpector is disabled, missing, or fails}
        {--enforce-sidecar : Let SkillSpector recommendations affect the top-level recommendation}
        {--allow-outside : Allow scanning outside configured local skill roots}
        {--write-report : Persist a JSON audit report under storage/app}
        {--report-dir= : storage/app-relative directory for --write-report}
        {--previous-report= : Existing agent skill audit report JSON to compare against}
        {--compare-latest : Compare against the newest prior report in the report directory}
        {--fail-on-drift : Exit non-zero when the drift comparison detects changes}
        {--source-url= : Source repository or package URL for a proposed skill}
        {--source-ref= : Source commit, tag, version, or checksum for a proposed skill}
        {--source-license= : Source license signal for a proposed skill}
        {--expected-content-sha256= : Fail closed unless the complete skill bundle matches this SHA-256 pin}
        {--operator-decision=pending_review : Operator decision state to record in the report}
        {--review-notes= : Operator or reviewer notes to include in the report}
        {--stage-review : Preview skill audit review queue materialization without a DB write}
        {--queue-review : Create or reuse a pending skill audit review queue row}
        {--compact : Use compact scheduler-safe JSON output}
        {--fail-on=none : Exit non-zero on none, caution, or do-not-install}';

    protected $description = 'Audit complete Agent Skills bundles, content integrity, PLOS safety policy, and optional SkillSpector findings';

    public function handle(AgentSkillAuditService $audit): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['human', 'json'], true)) {
            $this->error('Invalid --format. Use human or json.');

            return self::FAILURE;
        }

        $failOn = strtolower((string) $this->option('fail-on'));
        if (! in_array($failOn, ['none', 'caution', 'do-not-install'], true)) {
            $this->error('Invalid --fail-on. Use none, caution, or do-not-install.');

            return self::FAILURE;
        }

        $options = [
            'allow_outside' => (bool) $this->option('allow-outside'),
            'use_llm' => (bool) $this->option('use-llm'),
            'require_sidecar' => (bool) $this->option('require-sidecar'),
            'enforce_sidecar' => (bool) $this->option('enforce-sidecar'),
            'expected_content_sha256' => $this->option('expected-content-sha256'),
        ];

        $expectedDigest = strtolower(trim((string) ($this->option('expected-content-sha256') ?? '')));
        if ($expectedDigest !== '' && preg_match('/^[a-f0-9]{64}$/', $expectedDigest) !== 1) {
            $this->error('--expected-content-sha256 must be exactly 64 hexadecimal characters.');

            return self::FAILURE;
        }

        if ($this->option('skill') && $this->option('path')) {
            $this->error('Use either --skill or --path, not both.');

            return self::FAILURE;
        }

        $previousReport = $this->option('previous-report') ? (string) $this->option('previous-report') : null;
        $compareLatest = (bool) $this->option('compare-latest');
        if ($previousReport !== null && $compareLatest) {
            $this->error('Use either --previous-report or --compare-latest, not both.');

            return self::FAILURE;
        }

        if ((bool) $this->option('fail-on-drift') && $previousReport === null && ! $compareLatest) {
            $this->error('Use --fail-on-drift with --previous-report or --compare-latest.');

            return self::FAILURE;
        }

        $result = $this->option('skill')
            ? $audit->auditConfiguredSkill((string) $this->option('skill'), $options)
            : $audit->audit($this->option('path') ? (string) $this->option('path') : null, $options);

        if ($previousReport !== null || $compareLatest) {
            try {
                $result['drift'] = $audit->compareWithPrevious(
                    $result,
                    $previousReport,
                    $this->option('report-dir') ? (string) $this->option('report-dir') : null
                );
            } catch (\Throwable $e) {
                $this->error('Failed to compare skill audit reports: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $needsReport = (bool) $this->option('write-report')
            || (bool) $this->option('stage-review')
            || (bool) $this->option('queue-review');

        if ($needsReport) {
            try {
                $result['report_artifact'] = $audit->writeReport(
                    $result,
                    $this->reportMetadata(),
                    $this->option('report-dir') ? (string) $this->option('report-dir') : null
                );
            } catch (\Throwable $e) {
                $this->error('Failed to write skill audit report: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        if ($this->option('stage-review') || $this->option('queue-review')) {
            try {
                $reviewResult = $audit->materializeReview(
                    $result,
                    $result['report_artifact'] ?? null,
                    $this->reportMetadata(),
                    (bool) $this->option('queue-review')
                );
            } catch (\Throwable $e) {
                $reviewResult = [
                    'success' => false,
                    'execute' => (bool) $this->option('queue-review'),
                    'action' => 'none',
                    'error' => 'review_materialization_failed',
                    'message' => $e->getMessage(),
                ];
            }
            $result['review_materialization'] = $reviewResult;

            if (! ($reviewResult['success'] ?? false)) {
                if ($format === 'json') {
                    $this->line(json_encode($this->jsonPayload($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    $this->renderHuman($result);
                }

                return self::FAILURE;
            }
        }

        if ($format === 'json') {
            $this->line(json_encode($this->jsonPayload($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderHuman($result);
        }

        if ($this->option('require-sidecar') && ! ($result['sidecar']['successful'] ?? false)) {
            return self::FAILURE;
        }

        if ($this->shouldFail($result['recommendation'] ?? AgentSkillAuditService::RECOMMENDATION_SAFE, $failOn)) {
            return self::FAILURE;
        }

        if ((bool) $this->option('fail-on-drift') && ! empty($result['drift']['drift_detected'])) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function jsonPayload(array $result): array
    {
        return (bool) $this->option('compact')
            ? $this->compactResult($result)
            : $result;
    }

    private function compactResult(array $result): array
    {
        $sidecar = is_array($result['sidecar'] ?? null) ? $result['sidecar'] : [];
        $drift = is_array($result['drift'] ?? null) ? $result['drift'] : null;
        $artifact = is_array($result['report_artifact'] ?? null) ? $result['report_artifact'] : null;
        $review = is_array($result['review_materialization'] ?? null) ? $result['review_materialization'] : null;

        return array_filter([
            'schema' => 'agent_skill_audit_compact.v1',
            'compact' => true,
            'target' => $result['target'] ?? null,
            'target_allowed' => $result['target_allowed'] ?? null,
            'mode' => $result['mode'] ?? null,
            'skills_scanned' => $result['skills_scanned'] ?? null,
            'recommendation' => $result['recommendation'] ?? null,
            'risk_score' => $result['risk_score'] ?? null,
            'agent_skills_spec' => $result['agent_skills_spec'] ?? null,
            'integrity' => $this->compactIntegrity(
                is_array($result['integrity'] ?? null) ? $result['integrity'] : []
            ),
            'findings' => $this->compactFindings($result['findings'] ?? []),
            'sidecar' => $this->compactSidecar($sidecar),
            'drift' => $drift !== null ? $this->compactDrift($drift) : null,
            'report_artifact' => $artifact !== null ? $this->compactReportArtifact($artifact) : null,
            'review_materialization' => $review !== null ? $this->compactReviewMaterialization($review) : null,
        ], fn ($value): bool => $value !== null);
    }

    private function compactFindings(mixed $findings): array
    {
        $rows = is_array($findings) ? $findings : [];
        $bySeverity = [];
        foreach ($rows as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $severity = strtolower((string) ($finding['severity'] ?? 'unknown'));
            $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
        }

        return [
            'count' => count($rows),
            'by_severity' => $bySeverity,
        ];
    }

    /** @param array<string,mixed> $integrity @return array<string,mixed> */
    private function compactIntegrity(array $integrity): array
    {
        return [
            'schema' => $integrity['schema'] ?? 'plos.agent_skill_integrity.v1',
            'complete' => (bool) ($integrity['complete'] ?? false),
            'skills_checked' => (int) ($integrity['skills_checked'] ?? 0),
            'file_count' => (int) ($integrity['file_count'] ?? 0),
            'bytes' => (int) ($integrity['bytes'] ?? 0),
            'content_sha256' => $integrity['content_sha256'] ?? null,
            'expected_content_sha256' => $integrity['expected_content_sha256'] ?? null,
            'expected_digest_matched' => $integrity['expected_digest_matched'] ?? null,
            'trust_evidence' => $integrity['trust_evidence'] ?? [],
            'posture' => $integrity['posture'] ?? [],
        ];
    }

    private function compactSidecar(array $sidecar): array
    {
        $reports = is_array($sidecar['reports'] ?? null) ? $sidecar['reports'] : [];
        $byRecommendation = [];
        $attention = [];

        foreach ($reports as $report) {
            if (! is_array($report)) {
                continue;
            }

            $recommendation = (string) ($report['recommendation'] ?? 'UNKNOWN');
            $byRecommendation[$recommendation] = ($byRecommendation[$recommendation] ?? 0) + 1;

            if ($recommendation !== AgentSkillAuditService::RECOMMENDATION_SAFE || (int) ($report['score'] ?? 0) >= 20) {
                $attention[] = [
                    'skill' => $report['skill'] ?? basename((string) ($report['target'] ?? '')),
                    'recommendation' => $recommendation,
                    'score' => $report['score'] ?? null,
                    'severity' => $report['severity'] ?? null,
                    'issues_count' => $report['issues_count'] ?? null,
                ];
            }
        }

        return [
            'enabled' => $sidecar['enabled'] ?? null,
            'available' => $sidecar['available'] ?? null,
            'successful' => $sidecar['successful'] ?? null,
            'status' => $sidecar['status'] ?? null,
            'command' => $sidecar['command'] ?? null,
            'used_llm' => $sidecar['used_llm'] ?? null,
            'enforced' => $sidecar['enforced'] ?? null,
            'exit_code' => $sidecar['exit_code'] ?? null,
            'recommendation' => $sidecar['recommendation'] ?? null,
            'max_score' => $sidecar['max_score'] ?? null,
            'reports_count' => count($reports),
            'reports_by_recommendation' => $byRecommendation,
            'attention' => array_slice($attention, 0, 20),
        ];
    }

    private function compactDrift(array $drift): array
    {
        $changes = is_array($drift['changes'] ?? null) ? $drift['changes'] : [];

        return [
            'status' => $drift['status'] ?? null,
            'drift_detected' => (bool) ($drift['drift_detected'] ?? false),
            'previous_report' => $drift['previous_report']['relative_path'] ?? null,
            'changed' => [
                'target' => (bool) ($changes['target_changed'] ?? false),
                'mode' => (bool) ($changes['mode_changed'] ?? false),
                'recommendation' => (bool) ($changes['recommendation_changed'] ?? false),
                'content_digest' => (bool) ($changes['content_digest_changed'] ?? false),
                'integrity_complete' => (bool) ($changes['integrity_complete_changed'] ?? false),
                'sidecar_status' => (bool) ($changes['sidecar_status_changed'] ?? false),
                'sidecar_recommendation' => (bool) ($changes['sidecar_recommendation_changed'] ?? false),
            ],
            'deltas' => [
                'risk_score' => (int) ($changes['risk_score_delta'] ?? 0),
                'skills_scanned' => (int) ($changes['skills_scanned_delta'] ?? 0),
                'static_findings_count' => (int) ($changes['static_findings_count_delta'] ?? 0),
                'sidecar_max_score' => (int) ($changes['sidecar_max_score_delta'] ?? 0),
            ],
            'sidecar_report_counts' => [
                'added' => count((array) ($changes['sidecar_reports_added'] ?? [])),
                'removed' => count((array) ($changes['sidecar_reports_removed'] ?? [])),
                'changed' => count((array) ($changes['sidecar_reports_changed'] ?? [])),
            ],
            'skill_content_counts' => [
                'added' => count((array) ($changes['skill_contents_added'] ?? [])),
                'removed' => count((array) ($changes['skill_contents_removed'] ?? [])),
                'changed' => count((array) ($changes['skill_contents_changed'] ?? [])),
            ],
        ];
    }

    private function compactReportArtifact(array $artifact): array
    {
        return [
            'written' => $artifact['written'] ?? null,
            'relative_path' => $artifact['relative_path'] ?? null,
            'bytes' => $artifact['bytes'] ?? null,
            'sha256' => $artifact['sha256'] ?? null,
            'review_required' => $artifact['review_required'] ?? null,
            'operator_action' => $artifact['operator_action'] ?? null,
        ];
    }

    private function compactReviewMaterialization(array $review): array
    {
        return [
            'success' => $review['success'] ?? null,
            'execute' => $review['execute'] ?? null,
            'action' => $review['action'] ?? null,
            'created' => $review['created'] ?? null,
            'reused' => $review['reused'] ?? null,
            'review_queue_id' => $review['review_queue_id'] ?? null,
            'review_type' => $review['review_type'] ?? null,
            'finding_type' => $review['finding_type'] ?? null,
        ];
    }

    private function renderHuman(array $result): void
    {
        $this->info('Skill audit');
        $this->line('  Target: '.$result['target']);
        $this->line('  Mode: '.$result['mode']);
        $this->line('  Skills scanned: '.$result['skills_scanned']);
        $this->line('  Recommendation: '.$result['recommendation']);
        $this->line('  Risk score: '.$result['risk_score']);
        $this->line(sprintf(
            '  Integrity: %s / %s files / %s',
            ! empty($result['integrity']['complete']) ? 'complete' : 'incomplete',
            (string) ($result['integrity']['file_count'] ?? 0),
            (string) ($result['integrity']['content_sha256'] ?? 'unavailable')
        ));

        $sidecar = $result['sidecar'] ?? [];
        $this->line(sprintf(
            '  SkillSpector: %s%s%s',
            $sidecar['status'] ?? 'unknown',
            isset($sidecar['recommendation']) && $sidecar['recommendation'] !== null ? ' / '.$sidecar['recommendation'] : '',
            ! empty($sidecar['enforced']) ? ' (enforced)' : ''
        ));

        if (isset($result['report_artifact']['relative_path'])) {
            $this->line('  Report: '.$result['report_artifact']['relative_path']);
        }

        if (isset($result['drift'])) {
            $drift = $result['drift'];
            $this->line(sprintf(
                '  Drift: %s%s',
                $drift['status'] ?? 'unknown',
                ! empty($drift['drift_detected']) ? ' / changed' : ''
            ));
        }

        if (isset($result['review_materialization']['action'])) {
            $review = $result['review_materialization'];
            $suffix = isset($review['review_queue_id']) && $review['review_queue_id'] !== null
                ? ' #'.$review['review_queue_id']
                : '';
            $this->line('  Review: '.$review['action'].$suffix);
        }

        $findings = $result['findings'] ?? [];
        if (empty($findings)) {
            $this->info('No static findings.');

            return;
        }

        $this->warn('Findings:');
        foreach ($findings as $finding) {
            $location = $finding['path'] ?? '-';
            if (isset($finding['line'])) {
                $location .= ':'.$finding['line'];
            }

            $this->line(sprintf(
                '  [%s] %s/%s - %s (%s)',
                strtoupper((string) ($finding['severity'] ?? 'info')),
                $finding['category'] ?? 'unknown',
                $finding['rule'] ?? 'unknown',
                $finding['message'] ?? '',
                $location
            ));
        }
    }

    private function shouldFail(string $recommendation, string $failOn): bool
    {
        if ($failOn === 'none') {
            return false;
        }

        if ($failOn === 'do-not-install') {
            return $recommendation === AgentSkillAuditService::RECOMMENDATION_DO_NOT_INSTALL;
        }

        return in_array($recommendation, [
            AgentSkillAuditService::RECOMMENDATION_CAUTION,
            AgentSkillAuditService::RECOMMENDATION_DO_NOT_INSTALL,
        ], true);
    }

    private function reportMetadata(): array
    {
        return [
            'source_url' => $this->option('source-url'),
            'source_ref' => $this->option('source-ref'),
            'source_license' => $this->option('source-license'),
            'operator_decision' => $this->option('operator-decision'),
            'review_notes' => $this->option('review-notes'),
            'command_name' => 'agents:skill-audit',
            'command_options' => [
                'path' => $this->option('path'),
                'skill' => $this->option('skill'),
                'format' => $this->option('format'),
                'compact' => (bool) $this->option('compact'),
                'use_llm' => (bool) $this->option('use-llm'),
                'require_sidecar' => (bool) $this->option('require-sidecar'),
                'enforce_sidecar' => (bool) $this->option('enforce-sidecar'),
                'expected_content_sha256' => $this->option('expected-content-sha256'),
                'allow_outside' => (bool) $this->option('allow-outside'),
                'write_report' => (bool) $this->option('write-report'),
                'previous_report' => $this->option('previous-report'),
                'compare_latest' => (bool) $this->option('compare-latest'),
                'fail_on_drift' => (bool) $this->option('fail-on-drift'),
                'stage_review' => (bool) $this->option('stage-review'),
                'queue_review' => (bool) $this->option('queue-review'),
                'fail_on' => $this->option('fail-on'),
            ],
        ];
    }
}
