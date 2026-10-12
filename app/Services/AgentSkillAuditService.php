<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class AgentSkillAuditService
{
    public const RECOMMENDATION_SAFE = 'SAFE';

    public const RECOMMENDATION_CAUTION = 'CAUTION';

    public const RECOMMENDATION_DO_NOT_INSTALL = 'DO_NOT_INSTALL';

    public const REVIEW_AGENT_ID = 'skill-audit';

    public const REVIEW_TYPE = 'skill_optimization';

    public const FINDING_TYPE = 'skill_audit';

    private const MAX_SKILL_FILE_BYTES = 2_000_000;

    private const MAX_INTEGRITY_FILE_BYTES = 8_000_000;

    private const MAX_INTEGRITY_TOTAL_BYTES = 64_000_000;

    private const MAX_INTEGRITY_FILES = 5_000;

    private const DEFAULT_REPORT_DIR = 'agent-skill-audits';

    private const MAX_SIDECAR_ISSUES = 50;

    private const RECOMMENDED_INSTRUCTION_LINES = 500;

    private const RECOMMENDED_INSTRUCTION_TOKENS = 5_000;

    private const ESTIMATED_CHARS_PER_TOKEN = 4;

    private const EXCLUDED_DIRECTORIES = [
        '.git',
        'node_modules',
        'vendor',
        'bootstrap/cache',
        'storage/framework/cache',
    ];

    private const BLOCK_PATTERNS = [
        [
            'category' => 'prompt_injection',
            'rule' => 'override_system_instructions',
            'pattern' => '/ignore\s+(all\s+)?(previous|system|developer)\s+instructions/i',
            'message' => 'Skill text attempts to override higher-priority instructions.',
        ],
        [
            'category' => 'data_exfiltration',
            'rule' => 'secret_exfiltration',
            'pattern' => '/\b(exfiltrate|send|upload|post)\b.{0,80}\b(secret|token|password|credential|api[_-]?key|\.env|id_rsa)\b/i',
            'message' => 'Skill text appears to direct secrets or credentials outside the local trust boundary.',
        ],
        [
            'category' => 'malicious_code',
            'rule' => 'pipe_to_shell',
            'pattern' => '/\b(curl|wget)\b[^\n|;]*\|\s*(bash|sh)\b/i',
            'message' => 'Skill text includes a remote download piped into a shell.',
        ],
        [
            'category' => 'malicious_code',
            'rule' => 'destructive_shell',
            'pattern' => '/\brm\s+-[a-zA-Z]*r[a-zA-Z]*f\b|\bsudo\s+rm\b/i',
            'message' => 'Skill text includes destructive shell removal commands.',
        ],
        [
            'category' => 'supply_chain',
            'rule' => 'container_image_trust_disabled',
            'pattern' => '/\bdocker\b[^\n]{0,160}--disable-content-trust(?:=true\b|\s|$)|\bDOCKER_CONTENT_TRUST\s*=\s*0\b[^\n]{0,160}\bdocker\b|\bdockerd\b[^\n]{0,160}--insecure-registry(?:=|\s|$)/i',
            'message' => 'Skill text includes a command that disables container-image trust or configures an insecure container registry.',
        ],
    ];

    private const CAUTION_PATTERNS = [
        [
            'category' => 'secret_access',
            'rule' => 'sensitive_paths',
            'pattern' => '/(?<![a-zA-Z0-9_])(\.ssh|id_rsa|id_ed25519|known_hosts)(?![a-zA-Z0-9_])|\b(read|open|cat|print|dump|copy)\b.{0,60}(?<![a-zA-Z0-9_])\.env(?![a-zA-Z0-9_])|(?<![a-zA-Z0-9_])\.env(?![a-zA-Z0-9_]).{0,60}\b(read|open|cat|print|dump|copy)\b/i',
            'message' => 'Skill text references direct access to sensitive local credential paths or files.',
        ],
        [
            'category' => 'dynamic_execution',
            'rule' => 'inline_eval',
            'pattern' => '/\b(eval|exec)\s*\(|\bpython(?:3)?\s+-c\b|\bnode\s+-e\b/i',
            'message' => 'Skill text references dynamic code execution.',
        ],
        [
            'category' => 'privilege_escalation',
            'rule' => 'sudo_or_chmod',
            'pattern' => '/\bsudo\b|\bchmod\s+\+x\b/i',
            'message' => 'Skill text references privilege escalation or executable permission changes.',
        ],
        [
            'category' => 'hidden_behavior',
            'rule' => 'concealment',
            'pattern' => '/\b(do not tell|hide this|without (the )?user knowing|silently exfiltrate)\b/i',
            'message' => 'Skill text appears to instruct hidden behavior.',
        ],
    ];

    public function __construct(
        private readonly AgentSkillAuditRuntimePolicyService $runtimePolicy,
    ) {}

    public function audit(?string $path = null, array $options = []): array
    {
        $target = $this->resolveTargetPath($path ?: SkillLoaderService::configuredSkillsBasePath());
        $allowOutside = (bool) ($options['allow_outside'] ?? false);

        $rootViolation = $this->targetRootViolation($target, $allowOutside);
        if ($rootViolation !== null) {
            return [
                'target' => $target,
                'target_allowed' => false,
                'mode' => 'blocked',
                'skills_scanned' => 0,
                'recommendation' => self::RECOMMENDATION_DO_NOT_INSTALL,
                'risk_score' => 100,
                'integrity' => $this->emptyIntegritySummary(),
                'findings' => [$rootViolation],
                'sidecar' => $this->emptySidecarResult('not_run'),
            ];
        }

        $skillFiles = $this->collectSkillFiles($target);
        $findings = [];

        foreach ($skillFiles as $file) {
            array_push($findings, ...$this->scanSkillFile($file));
        }

        $integrity = $this->buildIntegrityManifest($skillFiles, $options);
        array_push($findings, ...$integrity['findings']);

        if (empty($skillFiles)) {
            $findings[] = $this->finding(
                'caution',
                'skill_shape',
                'no_skill_files',
                'No SKILL.md files were found under the target.',
                $target
            );
        }

        $sidecar = $this->runSkillSpector($target, $skillFiles, $options);

        $recommendation = $this->recommendationForFindings($findings);
        if ($sidecar['enforced'] ?? false) {
            $recommendation = $this->stricterRecommendation(
                $recommendation,
                $this->normalizeRecommendation($sidecar['recommendation'] ?? null)
            );
        }

        return [
            'target' => $target,
            'target_allowed' => true,
            'mode' => $sidecar['available'] && $sidecar['successful'] ? 'skillspector-sidecar' : 'static-only',
            'skills_scanned' => count($skillFiles),
            'recommendation' => $recommendation,
            'risk_score' => $this->riskScore($recommendation, $findings),
            'agent_skills_spec' => $this->agentSkillsSpecSummary($skillFiles, $findings),
            'integrity' => $integrity['summary'],
            'findings' => $findings,
            'sidecar' => $sidecar,
        ];
    }

    public function auditConfiguredSkill(string $skillName, array $options = []): array
    {
        $safeName = trim($skillName, "/\\ \t\n\r\0\x0B");

        return $this->audit(SkillLoaderService::configuredSkillsBasePath().DIRECTORY_SEPARATOR.$safeName, $options);
    }

    public function writeReport(array $auditResult, array $metadata = [], ?string $directory = null): array
    {
        $payload = $this->buildReportPayload($auditResult, $metadata);
        $targetDirectory = $this->resolveReportDirectory($directory);

        if (! File::isDirectory($targetDirectory)) {
            File::makeDirectory($targetDirectory, 0755, true);
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode skill audit report JSON.');
        }

        $path = $targetDirectory.DIRECTORY_SEPARATOR.sprintf(
            '%s-%s-%s.json',
            now()->format('Ymd-His'),
            $this->slugForReport((string) ($auditResult['target'] ?? 'skill-audit')),
            substr(hash('sha256', $json), 0, 12)
        );

        File::put($path, $json.PHP_EOL);

        return [
            'written' => true,
            'path' => $path,
            'relative_path' => $this->relativePath($path),
            'bytes' => File::size($path),
            'sha256' => hash_file('sha256', $path),
            'review_required' => (bool) ($payload['review_packet']['required'] ?? false),
            'operator_action' => $payload['review_packet']['operator_action'] ?? null,
        ];
    }

    public function materializeReview(array $auditResult, ?array $reportArtifact, array $metadata = [], bool $execute = false): array
    {
        $reviewPacket = $this->reviewPacketForResult($auditResult);
        if (! ($reviewPacket['required'] ?? false)) {
            return [
                'success' => true,
                'execute' => $execute,
                'action' => 'review_not_required',
                'created' => false,
                'reused' => false,
                'review_queue_id' => null,
                'token' => null,
                'review_type' => self::REVIEW_TYPE,
                'finding_type' => self::FINDING_TYPE,
            ];
        }

        try {
            $queueAvailable = Schema::hasTable('agent_review_queue');
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'execute' => $execute,
                'action' => 'none',
                'error' => 'review_queue_unavailable',
                'message' => $e->getMessage(),
            ];
        }

        if (! $queueAvailable) {
            return [
                'success' => false,
                'execute' => $execute,
                'action' => 'none',
                'error' => 'review_queue_unavailable',
                'message' => 'agent_review_queue table is not available.',
            ];
        }

        $payload = $this->reviewQueuePayload($auditResult, $reviewPacket, $reportArtifact, $metadata);
        $existing = $this->existingPendingReview($payload['agent_id'], $payload['review_type'], $payload['title']);

        if (! $execute) {
            return [
                'success' => true,
                'execute' => false,
                'action' => $existing === null ? 'would_create_review' : 'would_reuse_existing_review',
                'created' => false,
                'reused' => $existing !== null,
                'review_queue_id' => $existing !== null ? (int) $existing->id : null,
                'token' => $existing !== null ? (string) $existing->token : null,
                'review_type' => self::REVIEW_TYPE,
                'finding_type' => self::FINDING_TYPE,
                'title' => $payload['title'],
                'payload' => $payload,
            ];
        }

        if ($existing !== null) {
            return [
                'success' => true,
                'execute' => true,
                'action' => 'reused_existing_review',
                'created' => false,
                'reused' => true,
                'review_queue_id' => (int) $existing->id,
                'token' => (string) $existing->token,
                'review_type' => self::REVIEW_TYPE,
                'finding_type' => self::FINDING_TYPE,
                'title' => $payload['title'],
            ];
        }

        $row = [
            'agent_id' => $payload['agent_id'],
            'review_type' => $payload['review_type'],
            'title' => $payload['title'],
            'summary' => $payload['summary'],
            'details' => json_encode($payload['details'], JSON_UNESCAPED_SLASHES),
            'confidence' => $payload['confidence'],
            'priority' => $payload['priority'],
            'status' => 'pending',
            'token' => Str::random(40),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('agent_review_queue', 'finding_type')) {
            $row['finding_type'] = self::FINDING_TYPE;
        }

        try {
            $id = DB::table('agent_review_queue')->insertGetId($row);
        } catch (\Throwable $e) {
            $existing = $this->existingPendingReview($payload['agent_id'], $payload['review_type'], $payload['title']);
            if ($existing !== null) {
                return [
                    'success' => true,
                    'execute' => true,
                    'action' => 'reused_existing_review',
                    'created' => false,
                    'reused' => true,
                    'review_queue_id' => (int) $existing->id,
                    'token' => (string) $existing->token,
                    'review_type' => self::REVIEW_TYPE,
                    'finding_type' => self::FINDING_TYPE,
                    'title' => $payload['title'],
                ];
            }

            return [
                'success' => false,
                'execute' => true,
                'action' => 'none',
                'error' => 'review_queue_insert_failed',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'execute' => true,
            'action' => 'created_review',
            'created' => true,
            'reused' => false,
            'review_queue_id' => (int) $id,
            'token' => $row['token'],
            'review_type' => self::REVIEW_TYPE,
            'finding_type' => self::FINDING_TYPE,
            'title' => $payload['title'],
        ];
    }

    public function compareWithPrevious(array $currentAudit, ?string $previousReportPath = null, ?string $directory = null): array
    {
        $path = $previousReportPath !== null && trim($previousReportPath) !== ''
            ? $this->resolveExistingReportPath($previousReportPath)
            : $this->latestReportPath($directory);

        if ($path === null) {
            return [
                'status' => 'no_previous_report',
                'drift_detected' => false,
                'previous_report' => null,
                'changes' => [],
            ];
        }

        $previousPayload = $this->loadReportPayload($path);
        $previousAudit = is_array($previousPayload['audit'] ?? null) ? $previousPayload['audit'] : [];
        $previous = $this->auditComparisonSummary($previousAudit);
        $current = $this->auditComparisonSummary($currentAudit);
        $changes = $this->auditComparisonChanges($previous, $current);

        return [
            'status' => 'compared',
            'drift_detected' => $this->comparisonHasDrift($changes),
            'previous_report' => [
                'path' => $path,
                'relative_path' => $this->relativePath($path),
                'sha256' => hash_file('sha256', $path),
                'created_at' => $previousPayload['created_at'] ?? null,
            ],
            'previous' => $previous,
            'current' => $current,
            'changes' => $changes,
        ];
    }

    private function resolveTargetPath(string $path): string
    {
        $path = trim($path);
        $absolute = Str::startsWith($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        $real = realpath($absolute);

        return $real !== false ? $real : $absolute;
    }

    private function buildReportPayload(array $auditResult, array $metadata): array
    {
        $reviewPacket = $this->reviewPacketForResult($auditResult);
        $reviewScope = $this->reviewScope($auditResult, $metadata);

        return [
            'schema' => 'agent_skill_audit_report.v2',
            'created_at' => now()->toIso8601String(),
            'source' => [
                'url' => $this->nullableText($metadata['source_url'] ?? null),
                'ref' => $this->nullableText($metadata['source_ref'] ?? null),
                'license' => $this->nullableText($metadata['source_license'] ?? $metadata['license'] ?? null),
            ],
            'operator_decision' => [
                'state' => $this->nullableText($metadata['operator_decision'] ?? null) ?? 'pending_review',
                'notes' => $this->nullableText($metadata['review_notes'] ?? null),
            ],
            'review_scope' => $reviewScope,
            'command' => [
                'name' => $this->nullableText($metadata['command_name'] ?? null) ?? 'agents:skill-audit',
                'options' => is_array($metadata['command_options'] ?? null) ? $metadata['command_options'] : [],
            ],
            'review_packet' => $reviewPacket,
            'audit' => $auditResult,
        ];
    }

    private function auditComparisonSummary(array $audit): array
    {
        return [
            'target' => $audit['target'] ?? null,
            'mode' => $audit['mode'] ?? null,
            'recommendation' => $this->normalizeRecommendation($audit['recommendation'] ?? null),
            'risk_score' => (int) ($audit['risk_score'] ?? 0),
            'skills_scanned' => (int) ($audit['skills_scanned'] ?? 0),
            'integrity' => $this->integrityComparisonSummary(
                is_array($audit['integrity'] ?? null) ? $audit['integrity'] : []
            ),
            'static_findings_count' => count(is_array($audit['findings'] ?? null) ? $audit['findings'] : []),
            'static_finding_signatures' => $this->staticFindingSignatures(
                is_array($audit['findings'] ?? null) ? $audit['findings'] : []
            ),
            'sidecar' => [
                'status' => $audit['sidecar']['status'] ?? null,
                'recommendation' => $this->normalizeRecommendation($audit['sidecar']['recommendation'] ?? null),
                'max_score' => isset($audit['sidecar']['max_score']) ? (int) $audit['sidecar']['max_score'] : null,
                'enforced' => (bool) ($audit['sidecar']['enforced'] ?? false),
                'reports' => $this->sidecarReportSummaries(
                    is_array($audit['sidecar']['reports'] ?? null) ? $audit['sidecar']['reports'] : []
                ),
            ],
        ];
    }

    private function auditComparisonChanges(array $previous, array $current): array
    {
        $previousStatic = $previous['static_finding_signatures'] ?? [];
        $currentStatic = $current['static_finding_signatures'] ?? [];
        $previousReports = is_array($previous['sidecar']['reports'] ?? null) ? $previous['sidecar']['reports'] : [];
        $currentReports = is_array($current['sidecar']['reports'] ?? null) ? $current['sidecar']['reports'] : [];
        $previousIntegrity = is_array($previous['integrity'] ?? null) ? $previous['integrity'] : [];
        $currentIntegrity = is_array($current['integrity'] ?? null) ? $current['integrity'] : [];
        $hasComparableIntegrity = is_string($previousIntegrity['content_sha256'] ?? null)
            && is_string($currentIntegrity['content_sha256'] ?? null);
        $hasComparableIntegrityState = is_bool($previousIntegrity['complete'] ?? null)
            && is_bool($currentIntegrity['complete'] ?? null);
        $previousSkillDigests = is_array($previousIntegrity['skill_digests'] ?? null)
            ? $previousIntegrity['skill_digests']
            : [];
        $currentSkillDigests = is_array($currentIntegrity['skill_digests'] ?? null)
            ? $currentIntegrity['skill_digests']
            : [];

        return [
            'target_changed' => ($previous['target'] ?? null) !== ($current['target'] ?? null),
            'mode_changed' => ($previous['mode'] ?? null) !== ($current['mode'] ?? null),
            'recommendation_changed' => ($previous['recommendation'] ?? null) !== ($current['recommendation'] ?? null),
            'risk_score_delta' => (int) ($current['risk_score'] ?? 0) - (int) ($previous['risk_score'] ?? 0),
            'skills_scanned_delta' => (int) ($current['skills_scanned'] ?? 0) - (int) ($previous['skills_scanned'] ?? 0),
            'content_digest_changed' => $hasComparableIntegrity
                && $previousIntegrity['content_sha256'] !== $currentIntegrity['content_sha256'],
            'integrity_complete_changed' => $hasComparableIntegrityState
                && ($previousIntegrity['complete'] ?? null) !== ($currentIntegrity['complete'] ?? null),
            'skill_contents_added' => $hasComparableIntegrity
                ? array_values(array_diff(array_keys($currentSkillDigests), array_keys($previousSkillDigests)))
                : [],
            'skill_contents_removed' => $hasComparableIntegrity
                ? array_values(array_diff(array_keys($previousSkillDigests), array_keys($currentSkillDigests)))
                : [],
            'skill_contents_changed' => $hasComparableIntegrity
                ? $this->changedSkillDigests($previousSkillDigests, $currentSkillDigests)
                : [],
            'static_findings_count_delta' => (int) ($current['static_findings_count'] ?? 0) - (int) ($previous['static_findings_count'] ?? 0),
            'static_findings_added' => array_values(array_diff($currentStatic, $previousStatic)),
            'static_findings_resolved' => array_values(array_diff($previousStatic, $currentStatic)),
            'sidecar_status_changed' => ($previous['sidecar']['status'] ?? null) !== ($current['sidecar']['status'] ?? null),
            'sidecar_recommendation_changed' => ($previous['sidecar']['recommendation'] ?? null) !== ($current['sidecar']['recommendation'] ?? null),
            'sidecar_max_score_delta' => $this->nullableIntDelta($previous['sidecar']['max_score'] ?? null, $current['sidecar']['max_score'] ?? null),
            'sidecar_reports_added' => array_values(array_diff(array_keys($currentReports), array_keys($previousReports))),
            'sidecar_reports_removed' => array_values(array_diff(array_keys($previousReports), array_keys($currentReports))),
            'sidecar_reports_changed' => $this->changedSidecarReports($previousReports, $currentReports),
        ];
    }

    private function comparisonHasDrift(array $changes): bool
    {
        foreach ($changes as $key => $value) {
            if (is_bool($value) && $value) {
                return true;
            }

            if (is_int($value) && $value !== 0) {
                return true;
            }

            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return false;
    }

    private function nullableIntDelta(mixed $previous, mixed $current): ?int
    {
        if ($previous === null && $current === null) {
            return null;
        }

        return (int) ($current ?? 0) - (int) ($previous ?? 0);
    }

    private function staticFindingSignatures(array $findings): array
    {
        $signatures = [];

        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $signatures[] = implode('|', [
                $finding['severity'] ?? '',
                $finding['category'] ?? '',
                $finding['rule'] ?? '',
                $finding['path'] ?? '',
                $finding['line'] ?? '',
            ]);
        }

        sort($signatures);

        return array_values(array_unique($signatures));
    }

    /** @param array<string,mixed> $integrity @return array<string,mixed> */
    private function integrityComparisonSummary(array $integrity): array
    {
        $skillDigests = [];
        foreach ((array) ($integrity['skills'] ?? []) as $skill) {
            if (! is_array($skill)) {
                continue;
            }

            $name = $skill['skill'] ?? null;
            $digest = $skill['content_sha256'] ?? null;
            if (is_string($name) && $name !== '' && is_string($digest) && $digest !== '') {
                $skillDigests[$name] = $digest;
            }
        }
        ksort($skillDigests);

        return [
            'complete' => isset($integrity['complete']) ? (bool) $integrity['complete'] : null,
            'content_sha256' => is_string($integrity['content_sha256'] ?? null)
                ? $integrity['content_sha256']
                : null,
            'skill_digests' => $skillDigests,
        ];
    }

    /** @param array<string,string> $previous @param array<string,string> $current @return list<string> */
    private function changedSkillDigests(array $previous, array $current): array
    {
        $changed = [];
        foreach (array_intersect(array_keys($previous), array_keys($current)) as $skill) {
            if ($previous[$skill] !== $current[$skill]) {
                $changed[] = $skill;
            }
        }

        sort($changed);

        return $changed;
    }

    private function sidecarReportSummaries(array $reports): array
    {
        $summaries = [];

        foreach ($reports as $report) {
            if (! is_array($report)) {
                continue;
            }

            $skill = (string) ($report['skill'] ?? basename((string) ($report['target'] ?? 'unknown')));
            $summaries[$skill] = [
                'recommendation' => $this->normalizeRecommendation($report['recommendation'] ?? null),
                'score' => isset($report['score']) ? (int) $report['score'] : null,
                'severity' => $report['severity'] ?? null,
                'issues_count' => isset($report['issues_count']) ? (int) $report['issues_count'] : null,
                'issues_sha256' => $this->sidecarIssuesDigest($report['issues'] ?? []),
            ];
        }

        ksort($summaries);

        return $summaries;
    }

    private function changedSidecarReports(array $previousReports, array $currentReports): array
    {
        $changed = [];

        foreach ($currentReports as $skill => $current) {
            if (! array_key_exists($skill, $previousReports)) {
                continue;
            }

            if ($previousReports[$skill] !== $current) {
                $changed[] = [
                    'skill' => $skill,
                    'previous' => $previousReports[$skill],
                    'current' => $current,
                ];
            }
        }

        return $changed;
    }

    private function latestReportPath(?string $directory): ?string
    {
        $dir = $this->resolveReportDirectory($directory);
        if (! File::isDirectory($dir)) {
            return null;
        }

        $files = File::glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [];
        rsort($files);

        foreach ($files as $file) {
            try {
                $payload = $this->loadReportPayload($file);
            } catch (\Throwable) {
                continue;
            }

            if (in_array(($payload['schema'] ?? null), [
                'agent_skill_audit_report.v1',
                'agent_skill_audit_report.v2',
            ], true)) {
                return $file;
            }
        }

        return null;
    }

    private function resolveExistingReportPath(string $path): string
    {
        $raw = trim($path);
        $absolute = Str::startsWith($raw, DIRECTORY_SEPARATOR) ? $raw : base_path($raw);
        $real = realpath($absolute);

        if ($real === false || ! is_file($real) || ! is_readable($real)) {
            throw new \InvalidArgumentException('Previous skill audit report file not found or unreadable.');
        }

        return $real;
    }

    private function loadReportPayload(string $path): array
    {
        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Skill audit report JSON is invalid.');
        }

        return $decoded;
    }

    private function reviewQueuePayload(array $auditResult, array $reviewPacket, ?array $reportArtifact, array $metadata): array
    {
        $source = [
            'url' => $this->nullableText($metadata['source_url'] ?? null),
            'ref' => $this->nullableText($metadata['source_ref'] ?? null),
            'license' => $this->nullableText($metadata['source_license'] ?? $metadata['license'] ?? null),
        ];
        $target = (string) ($auditResult['target'] ?? 'unknown');
        $reviewScope = $this->reviewScope($auditResult, $metadata);
        $sourceLabel = $source['ref'] ?? $source['url'] ?? substr(hash('sha1', $target), 0, 10);
        $scopeLabel = substr((string) $reviewScope['scope_sha256'], 0, 12);
        $title = Str::limit(sprintf(
            'Skill audit: %s (%s; %s)',
            $this->slugForReport($target),
            $sourceLabel,
            $scopeLabel
        ), 180, '');
        $sidecarRecommendation = $this->normalizeRecommendation($auditResult['sidecar']['recommendation'] ?? null);
        $topRecommendation = $this->normalizeRecommendation($auditResult['recommendation'] ?? null) ?? self::RECOMMENDATION_CAUTION;

        return [
            'agent_id' => self::REVIEW_AGENT_ID,
            'review_type' => self::REVIEW_TYPE,
            'title' => $title,
            'summary' => sprintf(
                'Skill audit for %s: PLOS=%s, SkillSpector=%s, skills=%d, report=%s',
                basename(rtrim($target, DIRECTORY_SEPARATOR)) ?: 'skill target',
                $topRecommendation,
                $sidecarRecommendation ?? 'not_available',
                (int) ($auditResult['skills_scanned'] ?? 0),
                (string) ($reportArtifact['relative_path'] ?? $reportArtifact['path'] ?? 'not_written')
            ),
            'confidence' => 0.85,
            'priority' => $topRecommendation === self::RECOMMENDATION_DO_NOT_INSTALL
                || $sidecarRecommendation === self::RECOMMENDATION_DO_NOT_INSTALL ? 2 : 1,
            'details' => [
                'schema' => 'agent_skill_audit_review_queue.v2',
                'dedup_key' => 'skill-audit:'.$reviewScope['scope_sha256'],
                'finding_type' => self::FINDING_TYPE,
                'source' => $source,
                'review_scope' => $reviewScope,
                'operator_decision' => [
                    'state' => $this->nullableText($metadata['operator_decision'] ?? null) ?? 'pending_review',
                    'notes' => $this->nullableText($metadata['review_notes'] ?? null),
                ],
                'report_artifact' => $reportArtifact,
                'review_packet' => $reviewPacket,
                'audit_summary' => [
                    'target' => $target,
                    'top_recommendation' => $topRecommendation,
                    'risk_score' => (int) ($auditResult['risk_score'] ?? 0),
                    'skills_scanned' => (int) ($auditResult['skills_scanned'] ?? 0),
                    'static_findings' => count(is_array($auditResult['findings'] ?? null) ? $auditResult['findings'] : []),
                    'sidecar_status' => $auditResult['sidecar']['status'] ?? null,
                    'sidecar_recommendation' => $sidecarRecommendation,
                    'sidecar_enforced' => (bool) ($auditResult['sidecar']['enforced'] ?? false),
                    'sidecar_max_score' => $auditResult['sidecar']['max_score'] ?? null,
                ],
                'safety' => [
                    'canonical_skill_write_allowed' => false,
                    'skill_enablement_allowed' => false,
                    'operator_review_required' => true,
                    'queue_materialization_only' => true,
                    'approval_effect' => 'acknowledge_report_only_no_skill_write',
                    'rejection_effect' => 'dismiss_report_only_no_skill_write',
                ],
            ],
        ];
    }

    /**
     * Bind review reuse to the exact service-observed bundle and source claim.
     *
     * @param  array<string, mixed>  $auditResult
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function reviewScope(array $auditResult, array $metadata): array
    {
        $integrity = is_array($auditResult['integrity'] ?? null) ? $auditResult['integrity'] : [];
        $scopeMaterial = [
            'target' => (string) ($auditResult['target'] ?? 'unknown'),
            'source_url' => $this->nullableText($metadata['source_url'] ?? null),
            'source_ref' => $this->nullableText($metadata['source_ref'] ?? null),
            'source_license' => $this->nullableText($metadata['source_license'] ?? $metadata['license'] ?? null),
            'content_sha256' => $this->nullableText($integrity['content_sha256'] ?? null),
            'expected_content_sha256' => $this->nullableText($integrity['expected_content_sha256'] ?? null),
            'integrity_complete' => (bool) ($integrity['complete'] ?? false),
            'skills_scanned' => max(0, (int) ($auditResult['skills_scanned'] ?? 0)),
        ];

        return [
            'schema' => 'agent_skill_review_scope.v1',
            'scope_sha256' => hash('sha256', json_encode($scopeMaterial, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'target_sha256' => hash('sha256', $scopeMaterial['target']),
            'content_sha256' => $scopeMaterial['content_sha256'],
            'expected_content_sha256' => $scopeMaterial['expected_content_sha256'],
            'integrity_complete' => $scopeMaterial['integrity_complete'],
            'skills_scanned' => $scopeMaterial['skills_scanned'],
            'source_url_present' => $scopeMaterial['source_url'] !== null,
            'source_ref_present' => $scopeMaterial['source_ref'] !== null,
            'source_license_present' => $scopeMaterial['source_license'] !== null,
            'service_computed' => true,
            'exact_bundle_scope' => true,
            'reuse_requires_same_scope' => true,
        ];
    }

    private function existingPendingReview(string $agentId, string $reviewType, string $title): ?object
    {
        return DB::table('agent_review_queue')
            ->select(['id', 'token'])
            ->where('agent_id', $agentId)
            ->where('review_type', $reviewType)
            ->where('status', 'pending')
            ->where('title', $title)
            ->orderBy('id')
            ->first();
    }

    private function reviewPacketForResult(array $auditResult): array
    {
        $topRecommendation = $this->normalizeRecommendation($auditResult['recommendation'] ?? null)
            ?? self::RECOMMENDATION_CAUTION;
        $sidecarRecommendation = $this->normalizeRecommendation($auditResult['sidecar']['recommendation'] ?? null);
        $sidecarAdvisories = $this->sidecarAdvisories($auditResult);
        $staticFindings = is_array($auditResult['findings'] ?? null) ? $auditResult['findings'] : [];

        $required = $topRecommendation !== self::RECOMMENDATION_SAFE
            || ($sidecarRecommendation !== null && $sidecarRecommendation !== self::RECOMMENDATION_SAFE)
            || ! empty($sidecarAdvisories);

        return [
            'schema' => 'agent_skill_audit_review_packet.v1',
            'required' => $required,
            'status' => $required ? 'operator_review_required' : 'not_required',
            'operator_action' => $required
                ? 'review_skill_audit_report_before_import_or_sidecar_enforcement'
                : 'none',
            'blocked_if_enforced' => $topRecommendation === self::RECOMMENDATION_DO_NOT_INSTALL
                || $sidecarRecommendation === self::RECOMMENDATION_DO_NOT_INSTALL,
            'summary' => [
                'top_recommendation' => $topRecommendation,
                'risk_score' => (int) ($auditResult['risk_score'] ?? 0),
                'skills_scanned' => (int) ($auditResult['skills_scanned'] ?? 0),
                'static_findings' => count($staticFindings),
                'sidecar_recommendation' => $sidecarRecommendation,
                'sidecar_enforced' => (bool) ($auditResult['sidecar']['enforced'] ?? false),
                'sidecar_max_score' => $auditResult['sidecar']['max_score'] ?? null,
                'sidecar_advisories' => count($sidecarAdvisories),
            ],
            'sidecar_advisories' => $sidecarAdvisories,
        ];
    }

    private function sidecarAdvisories(array $auditResult): array
    {
        $reports = is_array($auditResult['sidecar']['reports'] ?? null)
            ? $auditResult['sidecar']['reports']
            : [];

        $advisories = [];
        foreach ($reports as $report) {
            if (! is_array($report)) {
                continue;
            }

            $recommendation = $this->normalizeRecommendation($report['recommendation'] ?? null);
            if ($recommendation === null || $recommendation === self::RECOMMENDATION_SAFE) {
                continue;
            }

            $advisories[] = [
                'skill' => $report['skill'] ?? basename((string) ($report['target'] ?? 'unknown')),
                'target' => $report['target'] ?? null,
                'recommendation' => $recommendation,
                'score' => $report['score'] ?? null,
                'severity' => $report['severity'] ?? null,
                'issues_count' => $report['issues_count'] ?? null,
                'issues' => is_array($report['issues'] ?? null) ? $report['issues'] : [],
                'issues_truncated' => (bool) ($report['issues_truncated'] ?? false),
            ];
        }

        return $advisories;
    }

    private function resolveReportDirectory(?string $directory): string
    {
        $base = storage_path('app');
        $raw = trim((string) ($directory ?: self::DEFAULT_REPORT_DIR));
        $target = Str::startsWith($raw, DIRECTORY_SEPARATOR)
            ? $raw
            : $base.DIRECTORY_SEPARATOR.trim($raw, '/\\');

        $target = rtrim($target, DIRECTORY_SEPARATOR);
        $parent = dirname($target);
        $realParent = realpath($parent) ?: $parent;

        if (! $this->pathIsWithin($realParent, $base)) {
            throw new \InvalidArgumentException('Skill audit reports must be written under storage/app.');
        }

        return $target;
    }

    private function slugForReport(string $target): string
    {
        $name = basename(rtrim($target, DIRECTORY_SEPARATOR)) ?: 'skill-audit';
        $slug = Str::slug($name);

        return $slug !== '' ? $slug : 'skill-audit';
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function targetRootViolation(string $target, bool $allowOutside): ?array
    {
        if ($allowOutside) {
            return null;
        }

        $allowedRoots = $this->allowedRoots();

        foreach ($allowedRoots as $root) {
            if ($this->pathIsWithin($target, $root)) {
                return null;
            }
        }

        return $this->finding(
            'block',
            'target_scope',
            'outside_allowed_roots',
            'Target is outside configured local skill roots. Re-run with --allow-outside after operator review.',
            $target
        );
    }

    private function allowedRoots(): array
    {
        $configured = config('agents.skill_audit.allowed_roots', []);
        $roots = [
            SkillLoaderService::configuredSkillsBasePath(),
            storage_path('agents/skills'),
            base_path('.codex/skills'),
        ];

        if (is_array($configured)) {
            foreach ($configured as $root) {
                if (is_string($root) && trim($root) !== '') {
                    $roots[] = $this->resolveTargetPath($root);
                }
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $root): ?string => realpath($root) ?: (is_dir($root) ? $root : null),
            $roots
        ))));
    }

    private function pathIsWithin(string $target, string $root): bool
    {
        $target = rtrim($target, DIRECTORY_SEPARATOR);
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $target === $root || str_starts_with($target, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<int, string>
     */
    private function collectSkillFiles(string $target): array
    {
        if (is_file($target)) {
            return basename($target) === 'SKILL.md' ? [$target] : [];
        }

        if (! is_dir($target)) {
            return [];
        }

        $directSkill = $target.DIRECTORY_SEPARATOR.'SKILL.md';
        if (is_file($directSkill)) {
            return [$directSkill];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current): bool {
                    if (! $current->isDir()) {
                        return true;
                    }

                    return ! in_array($current->getFilename(), self::EXCLUDED_DIRECTORIES, true)
                        && SkillLoaderService::isDiscoverableSkillName($current->getFilename());
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getFilename() === 'SKILL.md') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $skillFiles
     * @param  array<string,mixed>  $options
     * @return array{summary:array<string,mixed>,findings:list<array<string,mixed>>}
     */
    private function buildIntegrityManifest(array $skillFiles, array $options): array
    {
        if ($skillFiles === []) {
            return ['summary' => $this->emptyIntegritySummary(), 'findings' => []];
        }

        $skillDirectories = array_values(array_unique(array_map('dirname', $skillFiles)));
        sort($skillDirectories);

        $findings = [];
        $skills = [];
        $aggregateLines = [];
        $totalFiles = 0;
        $totalBytes = 0;
        $complete = true;

        foreach ($skillDirectories as $skillDirectory) {
            $skillName = basename($skillDirectory);
            $entries = [];
            $skillBytes = 0;
            $skillComplete = true;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($skillDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo) {
                    continue;
                }

                $path = $file->getPathname();
                $relativePath = str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    substr($path, strlen(rtrim($skillDirectory, DIRECTORY_SEPARATOR)) + 1)
                );

                if ($file->isLink()) {
                    $findings[] = $this->finding(
                        'block',
                        'skill_integrity',
                        'skill_bundle_symlink',
                        'Skill bundles may not contain symlinks because their target is outside the content manifest.',
                        $path
                    );
                    $skillComplete = false;

                    continue;
                }

                if (! $file->isFile()) {
                    continue;
                }

                if ($relativePath === '' || preg_match('/[\x00-\x1F\x7F]/', $relativePath) === 1) {
                    $findings[] = $this->finding(
                        'block',
                        'skill_integrity',
                        'invalid_skill_bundle_path',
                        'Skill bundle paths must be non-empty and contain no control characters.',
                        $path
                    );
                    $skillComplete = false;

                    continue;
                }

                if (! $file->isReadable()) {
                    $findings[] = $this->finding(
                        'block',
                        'skill_integrity',
                        'unreadable_skill_bundle_file',
                        'Every regular skill bundle file must be readable for integrity verification.',
                        $path
                    );
                    $skillComplete = false;

                    continue;
                }

                $size = $file->getSize();
                if ($size > self::MAX_INTEGRITY_FILE_BYTES) {
                    $findings[] = $this->finding(
                        'block',
                        'skill_integrity',
                        'oversized_skill_bundle_file',
                        'Skill bundle file exceeds the 8 MB integrity limit.',
                        $path
                    );
                    $skillComplete = false;

                    continue;
                }

                if ($totalFiles >= self::MAX_INTEGRITY_FILES
                    || ($totalBytes + $size) > self::MAX_INTEGRITY_TOTAL_BYTES) {
                    $findings[] = $this->finding(
                        'block',
                        'skill_integrity',
                        'skill_bundle_manifest_limit',
                        'Skill bundle manifest exceeds the 5,000-file or 64 MB aggregate integrity limit.',
                        $skillDirectory
                    );
                    $skillComplete = false;
                    $complete = false;

                    break;
                }

                $sha256 = hash_file('sha256', $path);
                if (! is_string($sha256)) {
                    $findings[] = $this->finding(
                        'block',
                        'skill_integrity',
                        'skill_bundle_hash_failed',
                        'Skill bundle file could not be hashed.',
                        $path
                    );
                    $skillComplete = false;

                    continue;
                }

                $entries[] = [
                    'path' => $relativePath,
                    'bytes' => $size,
                    'sha256' => $sha256,
                ];
                $skillBytes += $size;
                $totalBytes += $size;
                $totalFiles++;
            }

            usort($entries, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
            $skillDigest = $skillComplete && $entries !== []
                ? hash('sha256', implode("\n", array_map(
                    static fn (array $entry): string => implode("\0", [
                        $entry['path'],
                        (string) $entry['bytes'],
                        $entry['sha256'],
                    ]),
                    $entries
                )))
                : null;
            $trustEvidence = $this->skillTrustEvidence($entries);

            $skills[] = [
                'skill' => $skillName,
                'complete' => $skillComplete,
                'file_count' => count($entries),
                'bytes' => $skillBytes,
                'content_sha256' => $skillDigest,
                'trust_evidence' => $trustEvidence,
                'files' => $entries,
            ];

            if ($skillDigest !== null) {
                $aggregateLines[] = implode("\0", [$skillName, $skillDigest]);
            }
            $complete = $complete && $skillComplete;
        }

        usort($skills, static fn (array $left, array $right): int => $left['skill'] <=> $right['skill']);
        sort($aggregateLines);
        $aggregateDigest = $complete && count($aggregateLines) === count($skills)
            ? hash('sha256', implode("\n", $aggregateLines))
            : null;
        $expectedDigest = strtolower(trim((string) ($options['expected_content_sha256'] ?? '')));
        $expectedMatched = null;

        if ($expectedDigest !== '') {
            if (preg_match('/^[a-f0-9]{64}$/', $expectedDigest) !== 1) {
                $findings[] = $this->finding(
                    'block',
                    'skill_integrity',
                    'invalid_expected_content_sha256',
                    'Expected skill content SHA-256 must be exactly 64 lowercase hexadecimal characters.',
                    $skillDirectories[0]
                );
                $expectedMatched = false;
            } else {
                $expectedMatched = is_string($aggregateDigest) && hash_equals($expectedDigest, $aggregateDigest);
                if (! $expectedMatched) {
                    $findings[] = $this->finding(
                        'block',
                        'skill_integrity',
                        'skill_content_sha256_mismatch',
                        'Skill bundle content does not match the operator-supplied SHA-256 pin.',
                        $skillDirectories[0]
                    );
                }
            }
        }

        return [
            'summary' => [
                'schema' => 'plos.agent_skill_integrity.v1',
                'complete' => $complete,
                'skills_checked' => count($skills),
                'file_count' => $totalFiles,
                'bytes' => $totalBytes,
                'content_sha256' => $aggregateDigest,
                'expected_content_sha256' => $expectedDigest !== '' ? $expectedDigest : null,
                'expected_digest_matched' => $expectedMatched,
                'trust_evidence' => $this->aggregateTrustEvidence($skills),
                'skills' => $skills,
                'posture' => [
                    'content_included' => false,
                    'symlinks_allowed' => false,
                    'signature_verification' => 'presence_only_not_cryptographically_verified',
                    'network_used' => false,
                ],
            ],
            'findings' => $findings,
        ];
    }

    /** @param list<array{path:string,bytes:int,sha256:string}> $entries @return array<string,mixed> */
    private function skillTrustEvidence(array $entries): array
    {
        $paths = array_column($entries, 'path');
        $hasEvaluation = count(array_filter(
            $paths,
            static fn (string $path): bool => preg_match('#^(?:evals?|benchmark)/.*\.json$#i', $path) === 1
        )) > 0;

        return [
            'skill_card' => in_array('skill-card.md', $paths, true),
            'detached_signature' => in_array('skill.oms.sig', $paths, true)
                ? 'present_unverified'
                : 'not_present',
            'evaluation_data' => $hasEvaluation,
            'benchmark_report' => in_array('BENCHMARK.md', $paths, true),
        ];
    }

    /** @param list<array<string,mixed>> $skills @return array<string,int> */
    private function aggregateTrustEvidence(array $skills): array
    {
        $counts = [
            'skill_cards_present' => 0,
            'detached_signatures_present_unverified' => 0,
            'evaluation_data_present' => 0,
            'benchmark_reports_present' => 0,
        ];

        foreach ($skills as $skill) {
            $evidence = is_array($skill['trust_evidence'] ?? null) ? $skill['trust_evidence'] : [];
            $counts['skill_cards_present'] += (int) ($evidence['skill_card'] ?? false);
            $counts['detached_signatures_present_unverified'] += (int) (($evidence['detached_signature'] ?? null) === 'present_unverified');
            $counts['evaluation_data_present'] += (int) ($evidence['evaluation_data'] ?? false);
            $counts['benchmark_reports_present'] += (int) ($evidence['benchmark_report'] ?? false);
        }

        return $counts;
    }

    /** @return array<string,mixed> */
    private function emptyIntegritySummary(): array
    {
        return [
            'schema' => 'plos.agent_skill_integrity.v1',
            'complete' => false,
            'skills_checked' => 0,
            'file_count' => 0,
            'bytes' => 0,
            'content_sha256' => null,
            'expected_content_sha256' => null,
            'expected_digest_matched' => null,
            'trust_evidence' => [
                'skill_cards_present' => 0,
                'detached_signatures_present_unverified' => 0,
                'evaluation_data_present' => 0,
                'benchmark_reports_present' => 0,
            ],
            'skills' => [],
            'posture' => [
                'content_included' => false,
                'symlinks_allowed' => false,
                'signature_verification' => 'presence_only_not_cryptographically_verified',
                'network_used' => false,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scanSkillFile(string $file): array
    {
        if (! is_readable($file)) {
            return [
                $this->finding('block', 'filesystem', 'unreadable_skill', 'Skill file is not readable.', $file),
            ];
        }

        $size = filesize($file) ?: 0;
        if ($size > self::MAX_SKILL_FILE_BYTES) {
            return [
                $this->finding('caution', 'skill_shape', 'oversized_skill', 'Skill file is larger than the static audit read limit.', $file),
            ];
        }

        $content = (string) file_get_contents($file);
        $findings = $this->agentSkillsSpecFindings($content, $file);
        array_push($findings, ...$this->skillBundleReferenceFindings($content, $file));

        if (preg_match('/^write_scope\s*:\s*["\']?([^"\'\n#]+)["\']?/mi', $content, $match)) {
            $writeScope = trim($match[1]);
            if (in_array($writeScope, SkillLoaderService::PROTECTED_WRITE_SCOPES, true)) {
                $findings[] = $this->finding(
                    'caution',
                    'protected_scope',
                    'protected_write_scope',
                    "Skill declares protected write_scope '{$writeScope}' and requires explicit review.",
                    $file,
                    $this->lineForOffset($content, strpos($content, $match[0]) ?: 0)
                );
            }
        }

        foreach (self::BLOCK_PATTERNS as $rule) {
            array_push($findings, ...$this->patternFindings('block', $rule, $content, $file));
        }

        foreach (self::CAUTION_PATTERNS as $rule) {
            array_push($findings, ...$this->patternFindings('caution', $rule, $content, $file));
        }

        return $this->deduplicateFindings($findings);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function agentSkillsSpecFindings(string $content, string $file): array
    {
        $parts = preg_split('/^---\s*$/m', ltrim($content), 3);
        if (! is_array($parts) || count($parts) < 3) {
            return [
                $this->finding('block', 'agent_skills_spec', 'missing_frontmatter', 'Agent Skills requires YAML frontmatter followed by Markdown instructions.', $file),
            ];
        }

        try {
            $frontmatter = Yaml::parse($parts[1]);
        } catch (ParseException $e) {
            return [
                $this->finding('block', 'agent_skills_spec', 'invalid_frontmatter_yaml', 'Skill frontmatter is not valid YAML: '.$e->getMessage(), $file),
            ];
        }

        if (! is_array($frontmatter)) {
            return [
                $this->finding('block', 'agent_skills_spec', 'invalid_frontmatter_mapping', 'Skill frontmatter must be a YAML mapping.', $file),
            ];
        }

        $findings = [];
        $name = $frontmatter['name'] ?? null;
        $description = $frontmatter['description'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            $findings[] = $this->finding('block', 'agent_skills_spec', 'missing_skill_name', 'Agent Skills requires a non-empty name.', $file);
        } elseif (mb_strlen($name) > 64 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
            $findings[] = $this->finding('block', 'agent_skills_spec', 'invalid_skill_name', 'Skill name must be 1-64 lowercase letters, numbers, or single hyphens.', $file, $this->frontmatterLine($content, 'name'));
        } elseif ($name !== basename(dirname($file))) {
            $findings[] = $this->finding('block', 'agent_skills_spec', 'skill_name_directory_mismatch', 'Skill name must match its parent directory.', $file, $this->frontmatterLine($content, 'name'));
        }

        if (! is_string($description) || trim($description) === '') {
            $findings[] = $this->finding('block', 'agent_skills_spec', 'missing_skill_description', 'Agent Skills requires a non-empty description explaining what the skill does and when to use it.', $file);
        } elseif (mb_strlen($description) > 1024) {
            $findings[] = $this->finding('block', 'agent_skills_spec', 'skill_description_too_long', 'Skill description exceeds the 1024-character Agent Skills limit.', $file, $this->frontmatterLine($content, 'description'));
        }

        if (array_key_exists('license', $frontmatter) && (! is_string($frontmatter['license']) || trim($frontmatter['license']) === '')) {
            $findings[] = $this->finding('block', 'agent_skills_spec', 'invalid_skill_license', 'Optional license must be a non-empty string or bundled license-file reference.', $file, $this->frontmatterLine($content, 'license'));
        }

        if (array_key_exists('compatibility', $frontmatter)) {
            $compatibility = $frontmatter['compatibility'];
            if (! is_string($compatibility) || trim($compatibility) === '' || mb_strlen($compatibility) > 500) {
                $findings[] = $this->finding('block', 'agent_skills_spec', 'invalid_skill_compatibility', 'Optional compatibility must be a non-empty string of at most 500 characters.', $file, $this->frontmatterLine($content, 'compatibility'));
            }
        }

        if (array_key_exists('metadata', $frontmatter) && ! is_array($frontmatter['metadata'])) {
            $findings[] = $this->finding('block', 'agent_skills_spec', 'invalid_skill_metadata', 'Optional metadata must be a YAML mapping.', $file, $this->frontmatterLine($content, 'metadata'));
        }

        if (array_key_exists('allowed-tools', $frontmatter) && (! is_string($frontmatter['allowed-tools']) || trim($frontmatter['allowed-tools']) === '')) {
            $findings[] = $this->finding('block', 'agent_skills_spec', 'invalid_allowed_tools', 'Experimental allowed-tools must be a non-empty space-separated string.', $file, $this->frontmatterLine($content, 'allowed-tools'));
        }

        $instructions = trim((string) $parts[2]);
        if ($instructions === '') {
            $findings[] = $this->finding('block', 'agent_skills_spec', 'missing_skill_instructions', 'Agent Skills requires Markdown instructions after frontmatter.', $file);
        }

        $lineCount = $instructions === '' ? 0 : substr_count($instructions, "\n") + 1;
        if ($lineCount > self::RECOMMENDED_INSTRUCTION_LINES) {
            $findings[] = $this->finding('caution', 'agent_skills_spec', 'skill_progressive_disclosure_limit', 'Skill instructions exceed the recommended 500-line progressive-disclosure limit; move detailed material into one-level references/.', $file);
        }

        $estimatedTokens = $this->estimateInstructionTokens($instructions);
        if ($estimatedTokens > self::RECOMMENDED_INSTRUCTION_TOKENS) {
            $findings[] = $this->finding(
                'caution',
                'agent_skills_spec',
                'skill_progressive_disclosure_token_limit',
                "Skill instructions are estimated at {$estimatedTokens} tokens, above the recommended 5000-token activation budget; move task-specific detail into one-level references/.",
                $file
            );
        }

        return $findings;
    }

    /** @return list<array<string, mixed>> */
    private function skillBundleReferenceFindings(string $content, string $file): array
    {
        if (preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $content, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $skillDirectory = realpath(dirname($file));
        if (! is_string($skillDirectory)) {
            return [];
        }

        $findings = [];
        foreach ($matches[1] as [$rawTarget, $offset]) {
            $target = trim((string) $rawTarget);
            if (str_starts_with($target, '<') && str_ends_with($target, '>')) {
                $target = substr($target, 1, -1);
            }
            $target = preg_replace('/\s+["\'][^"\']*["\']$/', '', $target) ?? $target;
            $path = rawurldecode(explode('#', $target, 2)[0]);

            if ($path === '' || str_starts_with($target, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
                continue;
            }

            $line = $this->lineForOffset($content, (int) $offset);
            $normalized = ltrim(str_replace('\\', '/', $path), './');
            if (str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('#(?:^|/)\.\.(?:/|$)#', $path) === 1) {
                $findings[] = $this->finding('block', 'skill_integrity', 'skill_reference_path_escape', 'Skill references must remain inside their own bundle.', $file, $line);

                continue;
            }

            if (str_starts_with($normalized, 'references/') && substr_count($normalized, '/') > 1) {
                $findings[] = $this->finding('caution', 'skill_shape', 'skill_reference_depth', 'Progressive-disclosure references should remain one level below references/.', $file, $line);
            }

            $candidate = dirname($file).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);
            if ($this->pathContainsSymlink(dirname($file), $normalized)) {
                $findings[] = $this->finding('block', 'skill_integrity', 'skill_reference_symlink', 'Skill references may not resolve through symlinks.', $file, $line);

                continue;
            }

            $resolved = realpath($candidate);
            if (! is_string($resolved) || ! is_file($resolved)) {
                $findings[] = $this->finding('block', 'skill_integrity', 'missing_skill_reference', 'A local skill reference does not resolve to a regular bundled file.', $file, $line);

                continue;
            }

            if (! $this->pathIsWithin($resolved, $skillDirectory)) {
                $findings[] = $this->finding('block', 'skill_integrity', 'skill_reference_path_escape', 'Skill references must remain inside their own bundle.', $file, $line);
            }
        }

        return $findings;
    }

    private function pathContainsSymlink(string $base, string $relativePath): bool
    {
        $candidate = rtrim($base, DIRECTORY_SEPARATOR);
        foreach (explode('/', str_replace('\\', '/', $relativePath)) as $segment) {
            if ($segment === '') {
                continue;
            }

            $candidate .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function frontmatterLine(string $content, string $key): int
    {
        if (preg_match('/^'.preg_quote($key, '/').'\s*:/mi', $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return 1;
        }

        return $this->lineForOffset($content, (int) $match[0][1]);
    }

    /**
     * @param  array<int, string>  $skillFiles
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    private function agentSkillsSpecSummary(array $skillFiles, array $findings): array
    {
        $specFindings = array_values(array_filter(
            $findings,
            static fn (array $finding): bool => ($finding['category'] ?? null) === 'agent_skills_spec'
        ));
        $invalidPaths = array_values(array_unique(array_filter(array_map(
            static fn (array $finding): ?string => ($finding['severity'] ?? null) === 'block'
                ? ($finding['path'] ?? null)
                : null,
            $specFindings
        ), 'is_string')));

        $instructionMetrics = array_map(fn (string $file): array => $this->instructionMetrics($file), $skillFiles);

        return [
            'specification' => 'https://agentskills.io/specification',
            'skills_checked' => count($skillFiles),
            'skills_compliant' => max(0, count($skillFiles) - count($invalidPaths)),
            'invalid_skills' => count($invalidPaths),
            'findings' => count($specFindings),
            'activation_budget' => [
                'recommended_max_lines' => self::RECOMMENDED_INSTRUCTION_LINES,
                'recommended_max_tokens' => self::RECOMMENDED_INSTRUCTION_TOKENS,
                'token_estimate' => 'ceil(utf8_bytes/4)',
                'skills_over_line_budget' => count(array_filter($instructionMetrics, fn (array $metrics): bool => $metrics['lines'] > self::RECOMMENDED_INSTRUCTION_LINES)),
                'skills_over_estimated_token_budget' => count(array_filter($instructionMetrics, fn (array $metrics): bool => $metrics['estimated_tokens'] > self::RECOMMENDED_INSTRUCTION_TOKENS)),
                'max_instruction_lines' => max(array_column($instructionMetrics, 'lines') ?: [0]),
                'max_estimated_instruction_tokens' => max(array_column($instructionMetrics, 'estimated_tokens') ?: [0]),
            ],
        ];
    }

    /** @return array{lines:int,estimated_tokens:int} */
    private function instructionMetrics(string $file): array
    {
        $content = is_readable($file) ? (string) file_get_contents($file) : '';
        $parts = preg_split('/^---\s*$/m', ltrim($content), 3);
        $instructions = is_array($parts) && count($parts) >= 3 ? trim((string) $parts[2]) : '';

        return [
            'lines' => $instructions === '' ? 0 : substr_count($instructions, "\n") + 1,
            'estimated_tokens' => $this->estimateInstructionTokens($instructions),
        ];
    }

    private function estimateInstructionTokens(string $instructions): int
    {
        if ($instructions === '') {
            return 0;
        }

        return (int) ceil(strlen($instructions) / self::ESTIMATED_CHARS_PER_TOKEN);
    }

    /**
     * @param  array{category:string,rule:string,pattern:string,message:string}  $rule
     * @return array<int, array<string, mixed>>
     */
    private function patternFindings(string $severity, array $rule, string $content, string $file): array
    {
        if (! preg_match_all($rule['pattern'], $content, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $findings = [];
        foreach ($matches[0] as $match) {
            $findings[] = $this->finding(
                $severity,
                $rule['category'],
                $rule['rule'],
                $rule['message'],
                $file,
                $this->lineForOffset($content, $match[1])
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateFindings(array $findings): array
    {
        $seen = [];
        $unique = [];

        foreach ($findings as $finding) {
            $key = implode('|', [
                $finding['severity'] ?? '',
                $finding['category'] ?? '',
                $finding['rule'] ?? '',
                $finding['path'] ?? '',
                $finding['line'] ?? '',
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $finding;
        }

        return $unique;
    }

    private function lineForOffset(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, max(0, $offset)), "\n") + 1;
    }

    private function runSkillSpector(string $target, array $skillFiles, array $options): array
    {
        $enabled = (bool) config('agents.skill_audit.skillspector.enabled', true);
        if (! $enabled) {
            return $this->emptySidecarResult('disabled');
        }

        $command = (string) config('agents.skill_audit.skillspector.command', 'skillspector');
        $binary = $this->findExecutable($command);

        if ($binary === null) {
            return $this->emptySidecarResult('not_found', $command);
        }

        $useLlm = (bool) ($options['use_llm'] ?? config('agents.skill_audit.skillspector.use_llm_default', false));
        $enforced = (bool) ($options['enforce_sidecar'] ?? config('agents.skill_audit.skillspector.enforce_recommendation', false));
        $timeout = $this->runtimePolicy->required()['skillspector']['timeout_seconds'];
        $scanTargets = $this->sidecarScanTargets($target, $skillFiles);
        $reports = [];
        $successful = true;
        $recommendation = null;
        $maxScore = null;
        $exitCodes = [];

        foreach ($scanTargets as $scanTarget) {
            $scan = $this->runSkillSpectorScan($binary, $scanTarget, $useLlm, $timeout);
            $reports[] = $scan['summary'];
            $exitCodes[] = $scan['exit_code'];

            if (! $scan['parsed']) {
                $successful = false;
            }

            $recommendation = $this->stricterRecommendation(
                $recommendation ?? self::RECOMMENDATION_SAFE,
                $this->normalizeRecommendation($scan['summary']['recommendation'] ?? null)
            );

            if (isset($scan['summary']['score'])) {
                $maxScore = max($maxScore ?? 0, (int) $scan['summary']['score']);
            }
        }

        return [
            'enabled' => true,
            'available' => true,
            'successful' => $successful,
            'status' => $successful ? 'ok' : 'failed',
            'command' => $command,
            'used_llm' => $useLlm,
            'enforced' => $enforced,
            'exit_code' => $this->representativeExitCode($exitCodes),
            'recommendation' => $recommendation,
            'max_score' => $maxScore,
            'reports' => $reports,
            'report' => count($reports) === 1 ? $reports[0] : null,
        ];
    }

    /**
     * @param  array<int, string>  $skillFiles
     * @return array<int, string>
     */
    private function sidecarScanTargets(string $target, array $skillFiles): array
    {
        if (empty($skillFiles)) {
            return [$target];
        }

        $targets = [];
        foreach ($skillFiles as $file) {
            $targets[] = dirname($file);
        }

        return array_values(array_unique($targets));
    }

    private function runSkillSpectorScan(string $binary, string $target, bool $useLlm, int $timeout): array
    {
        $args = [$binary, 'scan', $target, '--format', 'json'];
        if (! $useLlm) {
            $args[] = '--no-llm';
        }

        $result = Process::timeout($timeout)->run($args);
        $stdout = trim($result->output());
        $stderr = trim($result->errorOutput());
        $decoded = $this->decodeJsonOutput($stdout);
        $parsed = is_array($decoded);

        $summary = $this->summarizeSidecarReport($target, $decoded, $result->exitCode());
        $summary['io_evidence'] = [
            'stdout_bytes' => strlen($stdout),
            'stdout_sha256' => hash('sha256', $stdout),
            'stderr_bytes' => strlen($stderr),
            'stderr_sha256' => hash('sha256', $stderr),
            'content_included' => false,
        ];

        return [
            'parsed' => $parsed,
            'exit_code' => $result->exitCode(),
            'summary' => $summary,
        ];
    }

    private function decodeJsonOutput(string $output): ?array
    {
        if ($output === '') {
            return null;
        }

        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function summarizeSidecarReport(string $target, ?array $report, ?int $exitCode): array
    {
        if ($report === null) {
            return [
                'target' => $target,
                'exit_code' => $exitCode,
                'parsed' => false,
                'recommendation' => null,
                'score' => null,
                'severity' => null,
                'issues_count' => null,
            ];
        }

        return [
            'target' => $target,
            'skill' => $report['skill']['name'] ?? basename($target),
            'exit_code' => $exitCode,
            'parsed' => true,
            'recommendation' => $this->extractSidecarRecommendation($report),
            'score' => $this->extractSidecarScore($report),
            'severity' => $report['risk_assessment']['severity'] ?? null,
            'issues_count' => is_array($report['issues'] ?? null) ? count($report['issues']) : null,
            'issues' => $this->summarizeSidecarIssues($report['issues'] ?? []),
            'issues_truncated' => is_array($report['issues'] ?? null)
                && count($report['issues']) > self::MAX_SIDECAR_ISSUES,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function summarizeSidecarIssues(mixed $issues): array
    {
        if (! is_array($issues)) {
            return [];
        }

        $summaries = [];
        foreach (array_slice($issues, 0, self::MAX_SIDECAR_ISSUES) as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $location = is_array($issue['location'] ?? null) ? $issue['location'] : [];
            $summaries[] = [
                'id' => isset($issue['id']) ? Str::limit((string) $issue['id'], 64, '') : null,
                'category' => isset($issue['category']) ? Str::limit((string) $issue['category'], 120, '') : null,
                'pattern' => isset($issue['pattern']) ? Str::limit((string) $issue['pattern'], 120, '') : null,
                'severity' => isset($issue['severity']) ? Str::limit((string) $issue['severity'], 32, '') : null,
                'confidence' => isset($issue['confidence']) && is_numeric($issue['confidence'])
                    ? (float) $issue['confidence']
                    : null,
                'file' => isset($location['file']) ? basename((string) $location['file']) : null,
                'start_line' => isset($location['start_line']) && is_numeric($location['start_line'])
                    ? (int) $location['start_line']
                    : null,
                'end_line' => isset($location['end_line']) && is_numeric($location['end_line'])
                    ? (int) $location['end_line']
                    : null,
            ];
        }

        return $summaries;
    }

    private function sidecarIssuesDigest(mixed $issues): ?string
    {
        if (! is_array($issues)) {
            return null;
        }

        $json = json_encode($issues, JSON_UNESCAPED_SLASHES);

        return is_string($json) ? hash('sha256', $json) : null;
    }

    private function representativeExitCode(array $exitCodes): ?int
    {
        $exitCodes = array_values(array_filter($exitCodes, fn ($code): bool => $code !== null));
        if (empty($exitCodes)) {
            return null;
        }

        foreach ($exitCodes as $code) {
            if ((int) $code !== 0) {
                return (int) $code;
            }
        }

        return 0;
    }

    private function findExecutable(string $command): ?string
    {
        $command = trim($command);
        if ($command === '') {
            return null;
        }

        if (str_contains($command, DIRECTORY_SEPARATOR)) {
            return is_executable($command) ? $command : null;
        }

        return (new ExecutableFinder)->find($command)
            ?? $this->findUserLocalExecutable($command);
    }

    private function findUserLocalExecutable(string $command): ?string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: null;
        if (! is_string($home) || trim($home) === '') {
            return null;
        }

        $candidate = rtrim($home, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'.local'
            .DIRECTORY_SEPARATOR.'bin'
            .DIRECTORY_SEPARATOR.$command;

        return is_executable($candidate) ? $candidate : null;
    }

    private function extractSidecarRecommendation(?array $report): ?string
    {
        if ($report === null) {
            return null;
        }

        foreach (['recommendation', 'verdict', 'risk_recommendation'] as $key) {
            if (isset($report[$key]) && is_string($report[$key])) {
                return $report[$key];
            }
        }

        if (isset($report['risk_assessment']['recommendation']) && is_string($report['risk_assessment']['recommendation'])) {
            return $report['risk_assessment']['recommendation'];
        }

        if (isset($report['summary']['recommendation']) && is_string($report['summary']['recommendation'])) {
            return $report['summary']['recommendation'];
        }

        return null;
    }

    private function extractSidecarScore(array $report): ?int
    {
        if (isset($report['risk_assessment']['score']) && is_numeric($report['risk_assessment']['score'])) {
            return (int) $report['risk_assessment']['score'];
        }

        if (isset($report['score']) && is_numeric($report['score'])) {
            return (int) $report['score'];
        }

        return null;
    }

    private function emptySidecarResult(string $status, ?string $command = null): array
    {
        return [
            'enabled' => $status !== 'disabled',
            'available' => false,
            'successful' => false,
            'status' => $status,
            'command' => $command ?? (string) config('agents.skill_audit.skillspector.command', 'skillspector'),
            'used_llm' => false,
            'enforced' => false,
            'exit_code' => null,
            'recommendation' => null,
            'max_score' => null,
            'reports' => [],
            'report' => null,
        ];
    }

    private function recommendationForFindings(array $findings): string
    {
        $recommendation = self::RECOMMENDATION_SAFE;

        foreach ($findings as $finding) {
            if (($finding['severity'] ?? null) === 'block') {
                return self::RECOMMENDATION_DO_NOT_INSTALL;
            }

            if (($finding['severity'] ?? null) === 'caution') {
                $recommendation = self::RECOMMENDATION_CAUTION;
            }
        }

        return $recommendation;
    }

    private function normalizeRecommendation(?string $recommendation): ?string
    {
        if ($recommendation === null || trim($recommendation) === '') {
            return null;
        }

        $normalized = strtoupper(str_replace([' ', '-'], '_', trim($recommendation)));

        return match ($normalized) {
            self::RECOMMENDATION_SAFE => self::RECOMMENDATION_SAFE,
            self::RECOMMENDATION_CAUTION, 'REVIEW', 'WARN', 'WARNING' => self::RECOMMENDATION_CAUTION,
            self::RECOMMENDATION_DO_NOT_INSTALL, 'BLOCK', 'BLOCKED', 'UNSAFE' => self::RECOMMENDATION_DO_NOT_INSTALL,
            default => null,
        };
    }

    private function stricterRecommendation(string $left, ?string $right): string
    {
        if ($right === null) {
            return $left;
        }

        $rank = [
            self::RECOMMENDATION_SAFE => 0,
            self::RECOMMENDATION_CAUTION => 1,
            self::RECOMMENDATION_DO_NOT_INSTALL => 2,
        ];

        return ($rank[$right] ?? 0) > ($rank[$left] ?? 0) ? $right : $left;
    }

    private function riskScore(string $recommendation, array $findings): int
    {
        return match ($recommendation) {
            self::RECOMMENDATION_DO_NOT_INSTALL => 100,
            self::RECOMMENDATION_CAUTION => min(79, 30 + (count($findings) * 10)),
            default => 0,
        };
    }

    private function finding(
        string $severity,
        string $category,
        string $rule,
        string $message,
        string $path,
        ?int $line = null
    ): array {
        return array_filter([
            'severity' => $severity,
            'category' => $category,
            'rule' => $rule,
            'message' => $message,
            'path' => $path,
            'line' => $line,
        ], fn ($value): bool => $value !== null);
    }
}
