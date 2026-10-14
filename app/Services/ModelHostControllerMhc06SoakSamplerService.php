<?php

namespace App\Services;

use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Read-only MHC-06 soak evidence. This class has no lifecycle mutation
 * methods: it may observe controller status and queue custody only.
 */
class ModelHostControllerMhc06SoakSamplerService
{
    private const SCHEMA = 'plos.model_host_controller.mhc06_soak_receipt.v1';

    public const PROVENANCE_SCHEMA = 'plos.model_host_controller.mhc06_provenance.v2';

    public const CUSTODY_SCHEMA = 'plos.model_host_controller.mhc06_soak_custody.v1';

    private const MAX_RECEIPT_BYTES = 65536;

    private const MAX_LOG_BYTES = 2097152;

    private const MAX_LOG_LINES = 4096;

    private const AGGREGATE_SCHEMA = 'plos.model_host_controller.mhc06_soak_aggregate.v2';

    private const QUALIFICATION_WINDOW_SECONDS = 36000;

    private const MIN_SAMPLES_FOR_10H = 117;

    private const MAX_SAMPLE_GAP_SECONDS = 600;

    private const EVIDENCE_LOOKBACK_SECONDS = 39600;

    /** @var array<string,bool> */
    private const SAFE_CONTENT_MARKERS = [
        'content_free' => true,
        'contains_prompt_or_response_content' => false,
        'tools_enabled' => false,
    ];

    private const PROMOTION_FULL_REF = '7f3b1f1004bd1ceb4385ae5dd21699a6e555dc9a';

    private const ACTIVE_POLICY_SHA256 = '411bccfd0f7d528e23d89f5458feb09a44a359af6d63c33199adf78bbd38ab10';

    private const CUSTODY_ROOT = 'app/private/model-host-controller/mhc06-custody';

    /** @var list<string> */
    public function __construct(
        private readonly ModelHostControllerClient $controller,
        private readonly ModelHostControllerQueueSnapshotService $queues,
        private readonly ?string $receiptPath = null,
        private readonly ?Closure $releaseProvenance = null,
        private readonly ?string $custodyRoot = null,
    ) {}

    /** @return array<string,mixed> */
    public function sample(string $sampleId): array
    {
        $sampleId = $this->identity($sampleId);
        $observedAt = new DateTimeImmutable;
        try {
            $status = $this->controller->status();
            $controllerState = $this->controllerState($status);
            $queue = $this->queueState($this->queues->capture());
            $receipt = [
                'schema' => self::SCHEMA,
                'sample_id' => $sampleId,
                'observed_at' => $observedAt->format(DateTimeImmutable::ATOM),
                'controller' => $controllerState,
                'queue' => $queue,
                'mutation_performed' => false,
                'activation_performed' => false,
                'release_performed' => false,
                'routing_changed' => false,
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
                'result' => 'green',
            ];
        } catch (\Throwable $exception) {
            $receipt = [
                'schema' => self::SCHEMA,
                'sample_id' => $sampleId,
                'observed_at' => $observedAt->format(DateTimeImmutable::ATOM),
                'reason' => $this->reason($exception),
                'mutation_performed' => false,
                'activation_performed' => false,
                'release_performed' => false,
                'routing_changed' => false,
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
                'result' => 'blocked',
            ];
        }

        $this->append($receipt);

        return $receipt;
    }

    /** @return array<string,mixed> */
    public function aggregate(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable;
        // Retain one hour of boundary tolerance so a five-minute scheduler can
        // prove a full ten-hour span instead of losing the oldest sample to a
        // sub-second sliding-window race at aggregation time.
        $cutoff = $now->getTimestamp() - self::EVIDENCE_LOOKBACK_SECONDS;
        $samples = 0;
        $green = 0;
        $blocked = 0;
        $invalid = 0;
        $contentFree = true;
        $targetCounts = [];
        $lastObservedAt = null;
        $timestamps = [];
        $sampleIds = [];

        foreach ($this->readReceipts() as $receipt) {
            if (($receipt['_malformed'] ?? false) === true) {
                $invalid++;

                continue;
            }
            $observedAt = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, (string) ($receipt['observed_at'] ?? ''));
            if ($observedAt === false) {
                $invalid++;

                continue;
            }
            if ($observedAt->getTimestamp() < $cutoff || $observedAt->getTimestamp() > $now->getTimestamp()) {
                continue;
            }
            $sampleId = (string) ($receipt['sample_id'] ?? '');
            if (($receipt['schema'] ?? null) !== self::SCHEMA
                || ($receipt['content_free'] ?? false) !== true
                || ($receipt['contains_prompt_or_response_content'] ?? true) !== false
                || ! in_array($receipt['result'] ?? null, ['green', 'blocked'], true)
                || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}\z/', $sampleId) !== 1
                || isset($sampleIds[$sampleId])) {
                $invalid++;

                continue;
            }
            $target = $receipt['controller']['target_state']['target'] ?? null;
            if ($target !== null && (! is_string($target) || ! in_array($target, array_keys(app(SystemConfigService::class)->llmRoutingBinding('controller_canary')['targets']), true))) {
                $invalid++;

                continue;
            }
            $sampleIds[$sampleId] = true;
            $samples++;
            $timestamps[] = $observedAt->getTimestamp();
            $green += ($receipt['result'] ?? null) === 'green' ? 1 : 0;
            $blocked += ($receipt['result'] ?? null) === 'blocked' ? 1 : 0;
            $contentFree = $contentFree && ($receipt['content_free'] === true);
            $targetBucket = $target ?? 'idle';
            $targetCounts[$targetBucket] = ($targetCounts[$targetBucket] ?? 0) + 1;
            $lastObservedAt = $receipt['observed_at'];
        }

        ksort($targetCounts);
        sort($timestamps);
        $spanSeconds = $timestamps === [] ? 0 : end($timestamps) - $timestamps[0];
        $cadenceOk = true;
        foreach ($timestamps as $index => $timestamp) {
            if ($index > 0 && $timestamp - $timestamps[$index - 1] > self::MAX_SAMPLE_GAP_SECONDS) {
                $cadenceOk = false;
                break;
            }
        }
        $soakComplete = $samples >= self::MIN_SAMPLES_FOR_10H
            && $spanSeconds >= self::QUALIFICATION_WINDOW_SECONDS
            && $cadenceOk
            && $blocked === 0
            && $invalid === 0;

        return [
            'schema' => self::AGGREGATE_SCHEMA,
            'window_seconds' => self::QUALIFICATION_WINDOW_SECONDS,
            'evidence_lookback_seconds' => self::EVIDENCE_LOOKBACK_SECONDS,
            'cutoff_epoch' => $cutoff,
            'samples' => $samples,
            'green_samples' => $green,
            'blocked_samples' => $blocked,
            'invalid_samples' => $invalid,
            'minimum_samples' => self::MIN_SAMPLES_FOR_10H,
            'span_seconds' => $spanSeconds,
            'minimum_span_seconds' => self::QUALIFICATION_WINDOW_SECONDS,
            'cadence_ok' => $cadenceOk,
            'target_counts' => $targetCounts,
            'last_observed_at' => $lastObservedAt,
            'content_free' => $contentFree,
            'contains_prompt_or_response_content' => false,
            'result' => $soakComplete ? 'green' : ($blocked > 0 || $invalid > 0 ? 'blocked' : 'insufficient'),
        ];
    }

    /** @return array<string,mixed> */
    public function provenance(?DateTimeImmutable $now = null, ?string $custodyPath = null): array
    {
        $now ??= new DateTimeImmutable;
        if ($custodyPath !== null) {
            return $this->custodyProvenance($now, $custodyPath);
        }

        return $this->legacyProvenance($now);
    }

    /** @return array<string,mixed> */
    private function legacyProvenance(DateTimeImmutable $now): array
    {
        $aggregate = $this->aggregate($now);
        if (($aggregate['result'] ?? null) !== 'green') {
            throw new RuntimeException('MHC-06 provenance requires a green aggregate.');
        }

        $lastObservedAt = DateTimeImmutable::createFromFormat(
            DateTimeImmutable::ATOM,
            (string) ($aggregate['last_observed_at'] ?? '')
        );
        if ($lastObservedAt === false || $lastObservedAt > $now
            || $now->getTimestamp() - $lastObservedAt->getTimestamp() > 900) {
            throw new RuntimeException('MHC-06 provenance requires fresh aggregate evidence.');
        }
        $spanSeconds = $aggregate['span_seconds'] ?? null;
        if (! is_int($spanSeconds) || $spanSeconds < self::QUALIFICATION_WINDOW_SECONDS) {
            throw new RuntimeException('MHC-06 provenance aggregate span is invalid.');
        }
        $soakStartedAt = $lastObservedAt->modify('-'.$spanSeconds.' seconds');

        $policyRow = DB::table('system_configs')
            ->where('section', ModelHostControllerAutomaticSelectionPolicyService::CONFIG_SECTION)
            ->where('config_key', ModelHostControllerAutomaticSelectionPolicyService::CONFIG_KEY)
            ->first();
        if ($policyRow === null) {
            throw new RuntimeException('MHC-06 provenance policy is missing.');
        }
        $policyJson = (string) $policyRow->config_value;
        if (! hash_equals(self::ACTIVE_POLICY_SHA256, hash('sha256', $policyJson))) {
            throw new RuntimeException('MHC-06 provenance policy identity drifted.');
        }
        $policy = json_decode($policyJson, true, 512, JSON_THROW_ON_ERROR);
        (new ModelHostControllerAutomaticSelectionPolicyService)->normalize($policy);
        if (($policy['revision'] ?? null) !== ModelHostControllerAutomaticSelectionPolicyService::ACTIVE_REVISION) {
            throw new RuntimeException('MHC-06 provenance policy is not the promoted revision.');
        }
        $policyUpdatedAtRaw = $policyRow->updated_at ?? null;
        if (! is_string($policyUpdatedAtRaw) || trim($policyUpdatedAtRaw) === '') {
            throw new RuntimeException('MHC-06 provenance policy timestamp is missing.');
        }
        try {
            $policyUpdatedAt = new DateTimeImmutable($policyUpdatedAtRaw);
        } catch (\Throwable) {
            throw new RuntimeException('MHC-06 provenance policy timestamp is invalid.');
        }
        if ($policyUpdatedAt > $soakStartedAt) {
            throw new RuntimeException('MHC-06 provenance policy changed after the soak began.');
        }

        $release = $this->releaseProvenance === null
            ? $this->readProductionRelease()
            : ($this->releaseProvenance)();
        $deployedFullRef = (string) ($release['deployed_full_ref'] ?? '');
        $promotionFullRef = (string) ($release['promotion_full_ref'] ?? '');
        if (preg_match('/\A[a-f0-9]{40}\z/', $deployedFullRef) !== 1
            || $promotionFullRef !== self::PROMOTION_FULL_REF
            || ($release['promotion_ancestor'] ?? null) !== true) {
            throw new RuntimeException('MHC-06 provenance deployed release lineage is invalid.');
        }

        $aggregateJson = json_encode($aggregate, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'schema' => 'plos.model_host_controller.mhc06_provenance.v1',
            'generated_at' => $now->format(DateTimeImmutable::ATOM),
            'aggregate_json' => $aggregateJson,
            'aggregate_sha256' => hash('sha256', $aggregateJson),
            'deployed_full_ref' => $deployedFullRef,
            'promotion_full_ref' => self::PROMOTION_FULL_REF,
            'promotion_ancestor' => true,
            'policy_revision' => ModelHostControllerAutomaticSelectionPolicyService::ACTIVE_REVISION,
            'policy_sha256' => self::ACTIVE_POLICY_SHA256,
            'policy_updated_at' => $policyUpdatedAt->format(DateTimeImmutable::ATOM),
            'policy_predates_soak' => true,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
            'result' => 'green',
        ];
    }

    /** @return array<string,mixed> */
    private function custodyProvenance(DateTimeImmutable $now, string $custodyPath): array
    {
        if (is_dir($custodyPath)) {
            $custodyPath = rtrim($custodyPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'custody.json';
        }
        if (is_link($custodyPath) || ! is_file($custodyPath)) {
            throw new RuntimeException('MHC-06 custody receipt is missing or symlinked.');
        }
        $root = $this->custodyRoot();
        $this->assertCustodyPath($root, $custodyPath);
        $directory = dirname($custodyPath);
        $realDirectory = realpath($directory);
        if ($realDirectory === false) {
            throw new RuntimeException('MHC-06 custody directory is invalid.');
        }
        $custodyLocked = $this->readLockedFile($custodyPath, 'receipt');
        $custodyRaw = $custodyLocked['raw'];
        $custodyMeta = $custodyLocked;
        if (($custodyMeta['bytes'] ?? 0) > self::MAX_RECEIPT_BYTES || (fileperms($custodyPath) & 0777) !== 0600) {
            throw new RuntimeException('MHC-06 custody permissions are unsafe.');
        }
        $custody = json_decode($custodyRaw, true);
        if (! is_array($custody) || ($custody['schema'] ?? null) !== self::CUSTODY_SCHEMA) {
            throw new RuntimeException('MHC-06 custody receipt schema is invalid.');
        }
        $this->assertExactCustody($custody);
        $logName = $custody['frozen_log_basename'] ?? null;
        if (! is_string($logName) || basename($logName) !== $logName || $logName === '' || str_contains($logName, '..')) {
            throw new RuntimeException('MHC-06 custody frozen log reference is invalid.');
        }
        $logPath = $directory.DIRECTORY_SEPARATOR.$logName;
        if (is_link($logPath) || ! is_file($logPath) || realpath(dirname($logPath)) !== $realDirectory
            || (fileperms($logPath) & 0777) !== 0600) {
            throw new RuntimeException('MHC-06 frozen soak log is not exact and private.');
        }
        $metadata = $this->frozenLogMetadata($logPath);
        foreach (['sha256', 'bytes', 'lines', 'first_observed_at', 'last_observed_at'] as $key) {
            if (($custody['frozen_log_'.$key] ?? null) !== $metadata[$key]) {
                throw new RuntimeException('MHC-06 frozen soak log binding drifted.');
            }
        }
        $aggregateJson = $custody['aggregate_json'] ?? null;
        $aggregate = is_string($aggregateJson) ? json_decode($aggregateJson, true) : null;
        if (! is_array($aggregate) || ! is_string($aggregateJson)
            || hash('sha256', $aggregateJson) !== ($custody['aggregate_sha256'] ?? null)) {
            throw new RuntimeException('MHC-06 custody aggregate binding drifted.');
        }
        $this->assertCustodyAggregate($aggregate);
        $this->assertLegacyProvenance($custody, $aggregate);
        $soakRef = (string) ($custody['soak_deployed_full_ref'] ?? '');
        if (! $this->sha($soakRef, 40)
            || ($custody['envelope_deployed_full_ref'] ?? null) !== null
            || ($custody['promotion_full_ref'] ?? null) !== self::PROMOTION_FULL_REF
            || ($custody['promotion_ancestor'] ?? null) !== true
            || ($custody['policy_revision'] ?? null) !== ModelHostControllerAutomaticSelectionPolicyService::ACTIVE_REVISION
            || ($custody['policy_sha256'] ?? null) !== self::ACTIVE_POLICY_SHA256
            || ($custody['policy_predates_soak'] ?? null) !== true
            || ($custody['content_free'] ?? null) !== true
            || ($custody['contains_prompt_or_response_content'] ?? null) !== false
            || ($custody['result'] ?? null) !== 'green') {
            throw new RuntimeException('MHC-06 custody identity is not exact and green.');
        }
        $capturedAt = $this->timestamp($custody['captured_at'] ?? null, 'custody timestamp');
        if ($capturedAt > $now) {
            throw new RuntimeException('MHC-06 custody receipt timestamp is in the future.');
        }
        $policyUpdatedAt = $this->timestamp($custody['policy_updated_at'] ?? null, 'policy timestamp');
        $lastObservedAt = $this->timestamp($aggregate['last_observed_at'] ?? null, 'aggregate timestamp');
        $soakStartedAt = $lastObservedAt->modify('-'.(int) $aggregate['span_seconds'].' seconds');
        if ($policyUpdatedAt > $soakStartedAt) {
            throw new RuntimeException('MHC-06 policy does not predate the custodied soak.');
        }
        $release = $this->releaseProvenance === null
            ? $this->readProductionRelease()
            : ($this->releaseProvenance)();
        $envelopeRef = (string) ($release['envelope_deployed_full_ref'] ?? $release['deployed_full_ref'] ?? '');
        $soakAncestor = array_key_exists('soak_ancestor', $release)
            ? ($release['soak_ancestor'] === true)
            : $this->releaseIsAncestor($soakRef, $envelopeRef);
        if (! $this->sha($envelopeRef, 40)
            || ($release['promotion_full_ref'] ?? self::PROMOTION_FULL_REF) !== self::PROMOTION_FULL_REF
            || ($release['promotion_ancestor'] ?? null) !== true
            || ! $soakAncestor) {
            throw new RuntimeException('MHC-06 envelope release lineage is invalid.');
        }
        $aggregateJson = json_encode($aggregate, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'schema' => self::PROVENANCE_SCHEMA,
            'generated_at' => $now->format(DateTimeImmutable::ATOM),
            'soak_custody_sha256' => $custodyMeta['sha256'],
            'soak_deployed_full_ref' => $soakRef,
            'envelope_deployed_full_ref' => $envelopeRef,
            'aggregate_json' => $aggregateJson,
            'aggregate_sha256' => hash('sha256', $aggregateJson),
            'promotion_full_ref' => self::PROMOTION_FULL_REF,
            'promotion_ancestor' => true,
            'soak_ancestor' => true,
            'policy_revision' => ModelHostControllerAutomaticSelectionPolicyService::ACTIVE_REVISION,
            'policy_sha256' => self::ACTIVE_POLICY_SHA256,
            'policy_updated_at' => $policyUpdatedAt->format(DateTimeImmutable::ATOM),
            'policy_predates_soak' => true,
            'custody_captured_at' => $capturedAt->format(DateTimeImmutable::ATOM),
            'custody_frozen_log_sha256' => $metadata['sha256'],
            'custody_frozen_log_bytes' => $metadata['bytes'],
            'custody_frozen_log_lines' => $metadata['lines'],
            'custody_sample_count' => (int) ($custody['sample_count'] ?? 0),
            'legacy_provenance_sha256' => $custody['legacy_provenance_sha256'],
            'custody_executor_sha256' => $custody['custody_executor_sha256'],
            'custody_private_package_identity' => $custody['custody_private_package_identity'],
            'custody_production_root_sha256' => $custody['custody_production_root_sha256'],
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
            'result' => 'green',
        ];
    }

    private function custodyRoot(): string
    {
        return $this->custodyRoot ?? storage_path(self::CUSTODY_ROOT);
    }

    private function assertCustodyPath(string $root, string $receiptPath): void
    {
        $this->assertNoSymlinkAncestors($root);
        $this->assertNoSymlinkAncestors($receiptPath);
        $realRoot = realpath($root);
        $realReceipt = realpath($receiptPath);
        if ($realRoot === false || $realReceipt === false || ! str_starts_with($realReceipt, $realRoot.DIRECTORY_SEPARATOR)
            || (fileperms($realRoot) & 0777) !== 0700 || basename($realReceipt) !== 'custody.json') {
            throw new RuntimeException('MHC-06 custody receipt escapes its authorized root.');
        }
        $relative = substr($realReceipt, strlen($realRoot) + 1);
        $parts = explode(DIRECTORY_SEPARATOR, $relative);
        if (count($parts) !== 2 || preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{12}\z/', $parts[0]) !== 1
            || (fileperms($realRoot.DIRECTORY_SEPARATOR.$parts[0]) & 0777) !== 0700) {
            throw new RuntimeException('MHC-06 custody receipt must be one exact child under its authorized root.');
        }
    }

    private function assertNoSymlinkAncestors(string $path): void
    {
        $cursor = $path;
        while ($cursor !== dirname($cursor)) {
            if (is_link($cursor)) {
                throw new RuntimeException('MHC-06 custody path contains a symlink ancestor.');
            }
            $cursor = dirname($cursor);
        }
    }

    /** @return array{sha256:string,bytes:int,raw:string} */
    private function readLockedFile(string $path, string $kind): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false || ! flock($handle, LOCK_SH)) {
            throw new RuntimeException('MHC-06 custody '.$kind.' cannot be shared-locked.');
        }
        try {
            $stat = fstat($handle);
            $limit = $kind === 'receipt' ? self::MAX_RECEIPT_BYTES : self::MAX_LOG_BYTES;
            if ($stat === false || ($stat['size'] ?? $limit + 1) > $limit) {
                throw new RuntimeException('MHC-06 custody '.$kind.' exceeds its safety limit.');
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            if (! is_string($raw) || strlen($raw) !== (int) $stat['size']) {
                throw new RuntimeException('MHC-06 custody '.$kind.' changed during locked read.');
            }

            return ['sha256' => hash('sha256', $raw), 'bytes' => strlen($raw), 'raw' => $raw];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{sha256:string,bytes:int,lines:int,first_observed_at:string,last_observed_at:string} */
    private function frozenLogMetadata(string $path): array
    {
        $locked = $this->readLockedFile($path, 'log');
        $lines = 0;
        $first = null;
        $last = null;
        foreach (explode("\n", $locked['raw']) as $line) {
            if ($line === '') {
                continue;
            }
            if ($lines >= self::MAX_LOG_LINES) {
                throw new RuntimeException('MHC-06 frozen log line count is invalid.');
            }
            $value = json_decode($line, true);
            if (! is_array($value) || ($value['content_free'] ?? null) !== true
                || ($value['contains_prompt_or_response_content'] ?? null) !== false
                || ! is_string($value['observed_at'] ?? null)) {
                throw new RuntimeException('MHC-06 frozen log contains unsafe evidence.');
            }
            $first ??= $value['observed_at'];
            $last = $value['observed_at'];
            $lines++;
        }
        if ($lines === 0 || $lines > self::MAX_LOG_LINES || ! is_string($first) || ! is_string($last)) {
            throw new RuntimeException('MHC-06 frozen log line count is invalid.');
        }

        return ['sha256' => $locked['sha256'], 'bytes' => $locked['bytes'], 'lines' => $lines, 'first_observed_at' => $first, 'last_observed_at' => $last];
    }

    /** @param array<string,mixed> $custody */
    private function assertExactCustody(array $custody): void
    {
        $keys = [
            'schema', 'captured_at', 'soak_deployed_full_ref', 'envelope_deployed_full_ref', 'promotion_full_ref',
            'promotion_ancestor', 'frozen_log_basename', 'frozen_log_sha256', 'frozen_log_bytes', 'frozen_log_lines',
            'frozen_log_first_observed_at', 'frozen_log_last_observed_at', 'sample_count', 'aggregate_json',
            'aggregate_sha256', 'policy_revision', 'policy_sha256', 'policy_updated_at', 'policy_predates_soak',
            'legacy_provenance_json', 'legacy_provenance_sha256', 'read_only', 'mutation_performed', 'content_free',
            'custody_executor_sha256', 'custody_private_package_identity', 'custody_production_root_sha256',
            'contains_prompt_or_response_content', 'result',
        ];
        $actual = array_keys($custody);
        sort($keys);
        sort($actual);
        if ($keys !== $actual || ($custody['frozen_log_basename'] ?? null) !== 'mhc06-soak.jsonl'
            || ! is_string($custody['aggregate_json'] ?? null)
            || ! is_string($custody['legacy_provenance_json'] ?? null)
            || hash('sha256', $custody['legacy_provenance_json']) !== ($custody['legacy_provenance_sha256'] ?? null)
            || ($custody['custody_executor_sha256'] ?? null) !== hash_file('sha256', base_path('scripts/model-host-controller-mhc06-custody.php'))
            || ($custody['custody_production_root_sha256'] ?? null) !== hash('sha256', base_path())
            || ! is_string($custody['custody_private_package_identity'] ?? null)
            || preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', $custody['custody_private_package_identity']) !== 1
            || ($custody['read_only'] ?? null) !== true || ($custody['mutation_performed'] ?? null) !== false) {
            throw new RuntimeException('MHC-06 custody receipt properties are not exact.');
        }
        foreach (['soak_deployed_full_ref' => 40, 'promotion_full_ref' => 40, 'frozen_log_sha256' => 64, 'aggregate_sha256' => 64, 'policy_sha256' => 64, 'legacy_provenance_sha256' => 64] as $key => $length) {
            if (! is_string($custody[$key] ?? null) || preg_match('/\A[a-f0-9]{'.$length.'}\z/', $custody[$key]) !== 1) {
                throw new RuntimeException('MHC-06 custody identity type is invalid.');
            }
        }
        foreach (['frozen_log_bytes', 'frozen_log_lines', 'sample_count'] as $key) {
            if (! is_int($custody[$key] ?? null) || $custody[$key] <= 0) {
                throw new RuntimeException('MHC-06 custody numeric type is invalid.');
            }
        }
        foreach (['captured_at', 'frozen_log_first_observed_at', 'frozen_log_last_observed_at', 'policy_updated_at'] as $key) {
            $this->timestamp($custody[$key] ?? null, 'custody '.$key);
        }
        if (($custody['schema'] ?? null) !== self::CUSTODY_SCHEMA || ($custody['result'] ?? null) !== 'green'
            || ($custody['envelope_deployed_full_ref'] ?? null) !== null || ($custody['promotion_ancestor'] ?? null) !== true
            || ($custody['policy_revision'] ?? null) !== ModelHostControllerAutomaticSelectionPolicyService::ACTIVE_REVISION
            || ($custody['policy_sha256'] ?? null) !== self::ACTIVE_POLICY_SHA256
            || ($custody['policy_predates_soak'] ?? null) !== true || ($custody['content_free'] ?? null) !== true
            || ($custody['contains_prompt_or_response_content'] ?? null) !== false) {
            throw new RuntimeException('MHC-06 custody identity is not exact and green.');
        }
    }

    /** @param array<string,mixed> $custody @param array<string,mixed> $aggregate */
    private function assertLegacyProvenance(array $custody, array $aggregate): void
    {
        $legacy = json_decode((string) $custody['legacy_provenance_json'], true);
        $legacyAggregateJson = is_array($legacy) ? ($legacy['aggregate_json'] ?? null) : null;
        $legacyAggregate = is_string($legacyAggregateJson) ? json_decode($legacyAggregateJson, true) : null;
        if (! is_array($legacy)
            || ($legacy['schema'] ?? null) !== 'plos.model_host_controller.mhc06_provenance.v1'
            || ($legacy['deployed_full_ref'] ?? null) !== ($custody['soak_deployed_full_ref'] ?? null)
            || ($legacy['promotion_full_ref'] ?? null) !== self::PROMOTION_FULL_REF
            || ($legacy['promotion_ancestor'] ?? null) !== true
            || ($legacy['policy_revision'] ?? null) !== ModelHostControllerAutomaticSelectionPolicyService::ACTIVE_REVISION
            || ($legacy['policy_sha256'] ?? null) !== self::ACTIVE_POLICY_SHA256
            || ($legacy['policy_updated_at'] ?? null) !== ($custody['policy_updated_at'] ?? null)
            || ($legacy['policy_predates_soak'] ?? null) !== true
            || ($legacy['content_free'] ?? null) !== true
            || ($legacy['contains_prompt_or_response_content'] ?? null) !== false
            || ($legacy['result'] ?? null) !== 'green'
            || ! is_string($legacyAggregateJson)
            || hash('sha256', $legacyAggregateJson) !== ($legacy['aggregate_sha256'] ?? null)
            || ! is_array($legacyAggregate)) {
            throw new RuntimeException('MHC-06 legacy provenance binding is invalid.');
        }
        $legacyGeneratedAt = $this->timestamp($legacy['generated_at'] ?? null, 'legacy provenance timestamp');
        $capturedAt = $this->timestamp($custody['captured_at'] ?? null, 'custody timestamp');
        if ($legacyGeneratedAt > $capturedAt || $capturedAt->getTimestamp() - $legacyGeneratedAt->getTimestamp() > 900) {
            throw new RuntimeException('MHC-06 legacy provenance was not fresh at custody capture.');
        }
        if (($legacyAggregate['target_counts'] ?? null) === []) {
            $legacyAggregate['target_counts'] = ['idle' => $legacyAggregate['samples'] ?? null];
        }
        if (json_encode($legacyAggregate, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            !== json_encode($aggregate, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) {
            throw new RuntimeException('MHC-06 legacy provenance aggregate disagrees with custody.');
        }
    }

    /** @param array<string,mixed> $aggregate */
    private function assertCustodyAggregate(array $aggregate): void
    {
        $keys = [
            'schema', 'window_seconds', 'evidence_lookback_seconds', 'cutoff_epoch', 'samples', 'green_samples',
            'blocked_samples', 'invalid_samples', 'minimum_samples', 'span_seconds', 'minimum_span_seconds',
            'cadence_ok', 'target_counts', 'last_observed_at', 'content_free',
            'contains_prompt_or_response_content', 'result',
        ];
        $actual = array_keys($aggregate);
        sort($keys);
        sort($actual);
        $targetCounts = $aggregate['target_counts'] ?? null;
        if ($keys !== $actual || ! is_array($targetCounts) || array_is_list($targetCounts) || $targetCounts === []) {
            throw new RuntimeException('MHC-06 custody aggregate target counts are not canonical.');
        }
        $total = 0;
        foreach ($targetCounts as $target => $count) {
            if (! is_string($target) || ! in_array($target, ['idle', app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'instance_id'), app(SystemConfigService::class)->llmRoutingValue('knowledge_graph', 'managed.target_id'), app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'instance_id')], true)
                || ! is_int($count) || $count <= 0) {
                throw new RuntimeException('MHC-06 custody aggregate target counts are invalid.');
            }
            $total += $count;
        }
        if (($aggregate['schema'] ?? null) !== self::AGGREGATE_SCHEMA
            || ($aggregate['result'] ?? null) !== 'green'
            || ($aggregate['window_seconds'] ?? null) !== self::QUALIFICATION_WINDOW_SECONDS
            || ($aggregate['evidence_lookback_seconds'] ?? null) !== self::EVIDENCE_LOOKBACK_SECONDS
            || ! is_int($aggregate['cutoff_epoch'] ?? null) || $aggregate['cutoff_epoch'] <= 0
            || ($aggregate['minimum_samples'] ?? null) !== self::MIN_SAMPLES_FOR_10H
            || ($aggregate['minimum_span_seconds'] ?? null) !== self::QUALIFICATION_WINDOW_SECONDS
            || ! is_int($aggregate['samples'] ?? null) || $aggregate['samples'] < self::MIN_SAMPLES_FOR_10H
            || ($aggregate['samples'] ?? null) !== $total
            || ($aggregate['green_samples'] ?? null) !== $aggregate['samples']
            || ($aggregate['blocked_samples'] ?? null) !== 0
            || ($aggregate['invalid_samples'] ?? null) !== 0
            || ($aggregate['span_seconds'] ?? 0) < self::QUALIFICATION_WINDOW_SECONDS
            || ($aggregate['cadence_ok'] ?? null) !== true
            || ($aggregate['content_free'] ?? null) !== true
            || ($aggregate['contains_prompt_or_response_content'] ?? null) !== false
            || ! is_string($aggregate['last_observed_at'] ?? null)) {
            throw new RuntimeException('MHC-06 custody aggregate is not exact and green.');
        }
    }

    private function timestamp(mixed $value, string $name): DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('MHC-06 '.$name.' is missing.');
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new RuntimeException('MHC-06 '.$name.' is invalid.');
        }
    }

    private function sha(mixed $value, int $length = 64): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{'.$length.'}\z/', $value) === 1;
    }

    private function productionGitDir(): string
    {
        $path = config('model_host_controller.production_git_dir', '');
        if (! is_string($path) || $path === '' || ! is_dir($path)) {
            throw new RuntimeException('An explicitly configured release repository is required.');
        }

        return $path;
    }

    private function releaseIsAncestor(string $ancestor, string $descendant): bool
    {
        $process = new Process([
            'git', '--git-dir='.$this->productionGitDir(), 'merge-base', '--is-ancestor', $ancestor, $descendant,
        ]);
        $process->run();

        return $process->isSuccessful();
    }

    /** @return array{deployed_full_ref:string,promotion_full_ref:string,promotion_ancestor:bool} */
    private function readProductionRelease(): array
    {
        $deployed = new Process([
            'git', '--git-dir='.$this->productionGitDir(), 'rev-parse', 'refs/heads/main',
        ]);
        $deployed->mustRun();
        $deployedFullRef = trim($deployed->getOutput());
        if (preg_match('/\A[a-f0-9]{40}\z/', $deployedFullRef) !== 1) {
            throw new RuntimeException('MHC-06 provenance deployed release ref is invalid.');
        }
        $ancestor = new Process([
            'git', '--git-dir='.$this->productionGitDir(), 'merge-base', '--is-ancestor',
            self::PROMOTION_FULL_REF, $deployedFullRef,
        ]);
        $ancestor->run();
        if (! $ancestor->isSuccessful()) {
            throw new RuntimeException('MHC-06 provenance deployed release is outside the promotion lineage.');
        }

        return [
            'deployed_full_ref' => $deployedFullRef,
            'promotion_full_ref' => self::PROMOTION_FULL_REF,
            'promotion_ancestor' => true,
        ];
    }

    /** @param array<string,mixed> $status @return array<string,mixed> */
    private function controllerState(array $status): array
    {
        if (($status['schema'] ?? null) !== 'plos.model_host_controller.status.v1'
            || ($status['host_id'] ?? null) !== '.87'
            || ($status['result'] ?? null) !== 'guarded_lifecycle'
            || ($status['contains_prompt_or_response_content'] ?? null) !== false) {
            throw new RuntimeException('unsafe_controller_status');
        }
        $this->rejectContentKeys($status);
        $lifecycle = $status['lifecycle'] ?? null;
        if (! is_array($lifecycle)) {
            throw new RuntimeException('incomplete_controller_state');
        }
        $targetState = $this->targetState($lifecycle, $status['targets'] ?? []);
        $leaseState = $this->leaseState($lifecycle);
        if (($leaseState['lease_present'] && $targetState['target'] === null)
            || (! $leaseState['lease_present'] && $targetState['target'] !== null)) {
            throw new RuntimeException('invalid_lease_state');
        }
        $this->assertLifecycleTargets($lifecycle['targets'] ?? null, $targetState['target']);
        $resourceState = $this->boundedMap($lifecycle['resources'] ?? [], 'resource');
        $switchState = [
            'last_hour' => $lifecycle['switches_last_hour'] ?? null,
            'max_switches_per_hour' => $lifecycle['max_switches_per_hour'] ?? null,
        ];
        if (($lifecycle['result'] ?? null) !== 'ready'
            || ($lifecycle['execution_mode'] ?? null) !== 'serial'
            || ($lifecycle['concurrent_inference_enabled'] ?? null) !== false
            || ($lifecycle['fallback_enabled'] ?? null) !== false
            || ($lifecycle['tools_enabled'] ?? null) !== false
            || ($resourceState['telemetry_complete'] ?? null) !== true
            || ($resourceState['hard_abort'] ?? null) !== false
            || ($resourceState['gate_passed'] ?? null) !== true
            || ! is_int($switchState['last_hour'])
            || $switchState['last_hour'] < 0
            || $switchState['last_hour'] > 4
            || ($switchState['max_switches_per_hour'] ?? null) !== 4) {
            throw new RuntimeException('incomplete_controller_state');
        }

        return [
            'host_id' => '.87',
            'result' => is_string($status['result'] ?? null) ? $status['result'] : 'unknown',
            'target_state' => $targetState,
            'lease_state' => $leaseState,
            'resource_state' => $resourceState,
            'switch_state' => $switchState,
            'status_sha256' => hash('sha256', json_encode([
                'target_state' => $targetState,
                'lease_state' => $leaseState,
                'resource_state' => $resourceState,
                'switch_state' => $switchState,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param array<string,mixed> $status @return array<string,mixed> */
    private function targetState(array $lifecycle, mixed $targets): array
    {
        $activeTarget = $lifecycle['active_target'] ?? null;
        if ($activeTarget !== null && ! in_array($activeTarget, array_keys(app(SystemConfigService::class)->llmRoutingBinding('controller_canary')['targets']), true)) {
            throw new RuntimeException('unadmitted_controller_target');
        }
        if ($activeTarget !== null && ! is_string($activeTarget)) {
            throw new RuntimeException('invalid_controller_target');
        }

        if (! is_array($targets)) {
            throw new RuntimeException('incomplete_controller_state');
        }

        return [
            'target' => $activeTarget,
            'target_catalog_sha256' => hash('sha256', json_encode($targets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param array<string,mixed> $lifecycle @return array<string,mixed> */
    private function leaseState(array $lifecycle): array
    {
        foreach (['lease_present', 'lease_expired', 'lease_id_sha256', 'lease_expires_at'] as $key) {
            if (! array_key_exists($key, $lifecycle)) {
                throw new RuntimeException('incomplete_controller_state');
            }
        }
        if (! is_bool($lifecycle['lease_present'])
            || ! is_bool($lifecycle['lease_expired'])
            || $lifecycle['lease_expired'] !== false
            || ($lifecycle['lease_id_sha256'] !== null
                && (! is_string($lifecycle['lease_id_sha256']) || preg_match('/\A[a-f0-9]{64}\z/', $lifecycle['lease_id_sha256']) !== 1))
            || ($lifecycle['lease_expires_at'] !== null && ! is_string($lifecycle['lease_expires_at']))
            || ($lifecycle['lease_present'] && ($lifecycle['lease_id_sha256'] === null || $lifecycle['lease_expires_at'] === null))
            || (! $lifecycle['lease_present'] && ($lifecycle['lease_id_sha256'] !== null || $lifecycle['lease_expires_at'] !== null))) {
            throw new RuntimeException('invalid_lease_state');
        }

        return [
            'lease_present' => $lifecycle['lease_present'],
            'lease_expired' => $lifecycle['lease_expired'],
            'lease_id_sha256' => $lifecycle['lease_id_sha256'],
            'lease_expires_at' => $lifecycle['lease_expires_at'],
        ];
    }

    private function assertLifecycleTargets(mixed $targets, mixed $activeTarget): void
    {
        if (! is_array($targets) || count($targets) !== count(array_keys(app(SystemConfigService::class)->llmRoutingBinding('controller_canary')['targets']))) {
            throw new RuntimeException('incomplete_controller_state');
        }
        $seen = [];
        foreach ($targets as $target) {
            if (! is_array($target) || ! in_array($target['target'] ?? null, array_keys(app(SystemConfigService::class)->llmRoutingBinding('controller_canary')['targets']), true)) {
                throw new RuntimeException('unadmitted_controller_target');
            }
            $targetId = (string) $target['target'];
            if (isset($seen[$targetId])
                || ($target['task_exact'] ?? null) !== true
                || ($target['control_exact'] ?? null) !== true
                || ($target['executable_exact'] ?? null) !== true) {
                throw new RuntimeException('incomplete_controller_state');
            }
            if (in_array($targetId, [app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'instance_id'), app(SystemConfigService::class)->llmRoutingValue('knowledge_graph', 'managed.target_id')], true)
                && (($target['healthy'] ?? null) !== true || ($target['model_exact'] ?? null) !== true)) {
                throw new RuntimeException('incomplete_controller_state');
            }
            if ($activeTarget === $targetId
                && (($target['healthy'] ?? null) !== true
                    || ($target['model_exact'] ?? null) !== true
                    || ($target['running_exact'] ?? null) !== true)) {
                throw new RuntimeException('incomplete_controller_state');
            }
            $seen[$targetId] = true;
        }
        if (array_keys($seen) !== array_keys(app(SystemConfigService::class)->llmRoutingBinding('controller_canary')['targets'])) {
            throw new RuntimeException('incomplete_controller_state');
        }
    }

    /** @param array<string,mixed> $queue @return array<string,mixed> */
    private function queueState(array $queue): array
    {
        if (($queue['content_free'] ?? null) !== true || ($queue['contains_prompt_or_response_content'] ?? true) !== false) {
            throw new RuntimeException('unsafe_queue_snapshot');
        }

        return [
            'snapshot_sha256' => $queue['snapshot_sha256'] ?? null,
            'preservation_sha256' => $queue['preservation_sha256'] ?? null,
            'tables' => array_map(static fn (mixed $table): array => is_array($table) ? [
                'present' => $table['present'] ?? null,
                'count' => $table['count'] ?? null,
                'rows_sha256' => $table['rows_sha256'] ?? null,
                'active_count' => $table['active_count'] ?? null,
                'failed_count' => $table['failed_count'] ?? null,
            ] : [], $queue['tables'] ?? []),
            'content_free' => true,
        ];
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function boundedMap(array $values, string $kind): array
    {
        $result = [];
        foreach (array_slice($values, 0, 24, true) as $key => $value) {
            if (! is_string($key) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,63}\z/', $key) !== 1) {
                throw new RuntimeException("invalid_{$kind}_key");
            }
            $result[$key] = $this->boundedValue($value, $kind, 0);
        }

        return $result;
    }

    private function boundedValue(mixed $value, string $kind, int $depth): mixed
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            if (is_string($value) && strlen($value) > 256) {
                throw new RuntimeException("oversized_{$kind}_value");
            }

            return $value;
        }
        if (! is_array($value) || $depth >= 2) {
            throw new RuntimeException("invalid_{$kind}_value");
        }

        $result = [];
        foreach (array_slice($value, 0, 24, true) as $key => $nested) {
            if (! is_string($key)) {
                throw new RuntimeException("invalid_{$kind}_key");
            }
            $result[$key] = $this->boundedValue($nested, $kind, $depth + 1);
        }

        return $result;
    }

    /** @param array<string,mixed> $values */
    private function rejectContentKeys(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                if (array_key_exists($key, self::SAFE_CONTENT_MARKERS)) {
                    if ($value !== self::SAFE_CONTENT_MARKERS[$key]) {
                        throw new RuntimeException('unsafe_controller_status');
                    }
                } elseif (preg_match('/prompt|response|content|tool|argument|payload|output/i', $key) === 1) {
                    throw new RuntimeException('unsafe_controller_status');
                }
            }
            if (is_array($value)) {
                $this->rejectContentKeys($value);
            }
        }
    }

    /** @param array<string,mixed> $receipt */
    private function append(array $receipt): void
    {
        $encoded = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_RECEIPT_BYTES) {
            throw new RuntimeException('MHC-06 soak receipt exceeded the safety limit.');
        }
        $path = $this->path();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('MHC-06 soak receipt directory is unavailable.');
        }
        if (is_link($path)) {
            throw new RuntimeException('MHC-06 soak receipt path is a symlink.');
        }
        $handle = fopen($path, 'c+b');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('MHC-06 soak receipt path is unavailable.');
        }
        try {
            if (! chmod($path, 0600)) {
                throw new RuntimeException('MHC-06 soak receipt permissions are unsafe.');
            }
            $stat = fstat($handle);
            if ($stat === false || ($stat['size'] ?? self::MAX_LOG_BYTES) + strlen($encoded) + 1 > self::MAX_LOG_BYTES) {
                throw new RuntimeException('MHC-06 soak receipt log reached its bounded size.');
            }
            rewind($handle);
            $lines = 0;
            while (! feof($handle) && $lines < self::MAX_LOG_LINES) {
                if (fgets($handle) !== false) {
                    $lines++;
                }
            }
            if ($lines >= self::MAX_LOG_LINES) {
                throw new RuntimeException('MHC-06 soak receipt log reached its bounded sample count.');
            }
            fseek($handle, 0, SEEK_END);
            $line = $encoded."\n";
            if (fwrite($handle, $line) !== strlen($line) || ! fflush($handle)) {
                throw new RuntimeException('MHC-06 soak receipt write failed.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return list<array<string,mixed>> */
    private function readReceipts(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }
        if (is_link($path) || filesize($path) > self::MAX_LOG_BYTES) {
            throw new RuntimeException('MHC-06 soak receipt evidence path is unsafe.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false || ! flock($handle, LOCK_SH)) {
            throw new RuntimeException('MHC-06 soak receipt evidence is unavailable.');
        }
        $receipts = [];
        try {
            while (! feof($handle) && count($receipts) < self::MAX_LOG_LINES) {
                $line = fgets($handle, self::MAX_RECEIPT_BYTES + 2);
                if ($line === false) {
                    break;
                }
                if (strlen($line) > self::MAX_RECEIPT_BYTES || trim($line) === '') {
                    $receipts[] = ['_malformed' => true];

                    continue;
                }
                $decoded = json_decode(trim($line), true);
                if (is_array($decoded)) {
                    $receipts[] = $decoded;
                } else {
                    $receipts[] = ['_malformed' => true];
                }
            }
            if (count($receipts) >= self::MAX_LOG_LINES && ! feof($handle)) {
                $receipts[] = ['_malformed' => true];
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $receipts;
    }

    private function path(): string
    {
        return $this->receiptPath ?? storage_path('app/private/model-host-controller/mhc06-soak.jsonl');
    }

    private function identity(string $value): string
    {
        $value = trim($value);
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}\z/', $value) !== 1) {
            throw new RuntimeException('MHC-06 soak sample identity is invalid.');
        }

        return $value;
    }

    private function reason(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'unsafe')) {
            return 'unsafe_status_or_queue';
        }
        if (str_contains($message, 'target')) {
            return 'controller_target_rejected';
        }
        if (str_contains($message, 'incomplete') || str_contains($message, 'lease')) {
            return 'controller_state_incomplete';
        }

        return 'observation_failed';
    }
}
