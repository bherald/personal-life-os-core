<?php

namespace App\Services;

use RuntimeException;

/** Atomic, content-free storage for the bounded MHC-08 acceptance receipts. */
class ModelHostControllerMhc08K3EvidenceService
{
    public const CAPTURE_SCHEMA = 'plos.model_host_controller.mhc08_k3_evidence_capture.v1';

    private const CONTRACTS = [
        'activation-transfer' => [
            'file' => 'MHC-08-STATE-ACTIVATION-TRANSFER.json',
            'schema' => 'plos.model_host_controller.mhc08_k3_state_activation_transfer.v1',
            'result_key' => 'result',
            'result' => 'transferred',
        ],
        'activation-transfer-v2' => [
            'file' => 'MHC-08-V2-STATE-ACTIVATION-TRANSFER.json',
            'schema' => 'plos.model_host_controller.mhc08_k3_state_activation_transfer.v1',
            'result_key' => 'result',
            'result' => 'transferred',
        ],
        'mhc08c-qualification' => [
            'file' => 'MHC-08C-STATE-QUALIFICATION.json',
            'schema' => 'plos.model_host_controller.mhc08_k3_state_qualification.v1',
            'result_key' => 'result',
            'result' => 'green',
        ],
        'canary' => [
            'file' => 'MHC-08D-CANARY-RECEIPT.json',
            'schema' => 'plos.model_host_controller.mhc08_k3_canary_batch.v1',
            'result_key' => 'controls_passed',
            'result' => true,
        ],
        'cancellation' => [
            'file' => 'MHC-08D-CANCELLATION-RECEIPT.json',
            'schema' => 'plos.model_host_controller.mhc08_k3_cancellation_qualification.v1',
            'result_key' => 'result',
            'result' => 'green',
        ],
        'host-clean' => [
            'file' => 'MHC-08D-HOST-CLEAN-QUALIFICATION.json',
            'schema' => 'plos.model_host_controller.mhc08_k3_host_clean_qualification.v1',
            'result_key' => 'result',
            'result' => 'green',
        ],
        'final-smoke' => [
            'file' => 'MHC-08E-EXPLICIT-JOB-SMOKE.json',
            'schema' => 'plos.model_host_controller.mhc08_k3_explicit_job_smoke.v1',
            'result_key' => 'result',
            'result' => 'green',
        ],
    ];

    public function __construct(private readonly ?string $evidenceRoot = null) {}

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    public function store(string $kind, array $receipt): array
    {
        $contract = $this->contract($kind);
        $this->assertReceipt($receipt, $contract);
        $root = $this->root();
        $rootIdentity = $this->identity($root);
        $destination = $root.DIRECTORY_SEPARATOR.$contract['file'];
        if (is_link($destination) || (file_exists($destination) && ! is_file($destination))) {
            throw new RuntimeException('MHC-08 evidence destination is not a regular file.');
        }

        $encoded = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $temporary = $root.DIRECTORY_SEPARATOR.'.'.$contract['file'].'.tmp-'.bin2hex(random_bytes(12));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('MHC-08 evidence temporary file could not be created.');
        }
        try {
            if (fwrite($handle, $encoded) !== strlen($encoded) || ! fflush($handle)
                || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('MHC-08 evidence write was incomplete.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($temporary, 0600);
        clearstatcache(true, $temporary);
        if (($this->mode($temporary) & 0777) !== 0600 || $this->identity($root) !== $rootIdentity) {
            @unlink($temporary);
            throw new RuntimeException('MHC-08 evidence root or temporary-file security changed.');
        }
        if (! @rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('MHC-08 evidence atomic publication failed.');
        }
        @chmod($destination, 0600);
        clearstatcache(true, $destination);
        if (! is_file($destination) || is_link($destination)
            || realpath(dirname($destination)) !== $root
            || ($this->mode($destination) & 0777) !== 0600
            || $this->identity($root) !== $rootIdentity
            || hash_file('sha256', $destination) !== hash('sha256', $encoded)) {
            throw new RuntimeException('MHC-08 evidence post-publication verification failed.');
        }
        $stored = $this->read($kind);

        return [
            'schema' => self::CAPTURE_SCHEMA,
            'kind' => $kind,
            'path' => $this->relativePath($destination),
            'sha256' => $stored['sha256'],
            'result' => 'stored',
            'atomic' => true,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
    }

    /** @return array{receipt:array<string,mixed>,path:string,sha256:string} */
    public function read(string $kind): array
    {
        $contract = $this->contract($kind);
        $path = $this->root().DIRECTORY_SEPARATOR.$contract['file'];
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException("MHC-08 {$kind} evidence is missing or unsafe.");
        }
        $receipt = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($receipt)) {
            throw new RuntimeException("MHC-08 {$kind} evidence is invalid.");
        }
        $this->assertReceipt($receipt, $contract);

        return ['receipt' => $receipt, 'path' => $path, 'sha256' => hash_file('sha256', $path)];
    }

    /** @return array<string,mixed> */
    public function capture(string $kind, string $source): array
    {
        $resolved = realpath($source);
        if ($resolved === false || ! is_file($resolved) || is_link($source)) {
            throw new RuntimeException('MHC-08 evidence source is not a regular file.');
        }
        $receipt = json_decode((string) file_get_contents($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($receipt)) {
            throw new RuntimeException('MHC-08 evidence source is not a JSON object.');
        }

        return $this->store($kind, $receipt);
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $contract */
    private function assertReceipt(array $receipt, array $contract): void
    {
        if (($receipt['schema'] ?? null) !== $contract['schema']
            || ($receipt[$contract['result_key']] ?? null) !== $contract['result']
            || ($receipt['content_free'] ?? null) !== true
            || ($receipt['contains_prompt_or_response_content'] ?? null) !== false) {
            throw new RuntimeException('MHC-08 evidence is not exact, green, and content-free.');
        }
        if (($receipt['schema'] ?? null) === 'plos.model_host_controller.mhc08_k3_state_activation_transfer.v1'
            && (array_keys($receipt) !== [
                'schema', 'action', 'result', 'host_id', 'baseline_sha256', 'staging_sha256',
                'private_package_identity', 'executor_sha256', 'state_build_receipt_sha256',
                'mhc06_provenance_sha256', 'activation_mhc06_provenance_sha256',
                'checkpoint_state', 'activation_allowed', 'completed_at', 'destination_count',
                'content_free', 'contains_prompt_or_response_content',
            ]
                || ($receipt['action'] ?? null) !== 'transfer'
                || ($receipt['host_id'] ?? null) !== '.87'
                || ($receipt['checkpoint_state'] ?? null) !== 'transferred'
                || ($receipt['activation_allowed'] ?? null) !== true
                || ($receipt['destination_count'] ?? null) !== 6
                || ! $this->sha($receipt['baseline_sha256'] ?? null)
                || ! $this->sha($receipt['staging_sha256'] ?? null)
                || ! $this->sha($receipt['executor_sha256'] ?? null)
                || ! $this->sha($receipt['state_build_receipt_sha256'] ?? null)
                || ! $this->sha($receipt['mhc06_provenance_sha256'] ?? null)
                || ! $this->sha($receipt['activation_mhc06_provenance_sha256'] ?? null)
                || ! is_string($receipt['private_package_identity'] ?? null)
                || preg_match('/\A[a-f0-9]{40}\z/', $receipt['private_package_identity']) !== 1
                || strtotime((string) ($receipt['completed_at'] ?? '')) === false)) {
            throw new RuntimeException('MHC-08 activation-transfer evidence contract is not exact.');
        }
        $this->assertNoContent($receipt);
    }

    /** @param array<string,mixed> $value */
    private function assertNoContent(array $value): void
    {
        foreach ($value as $key => $item) {
            if (in_array((string) $key, ['prompt', 'system_prompt', 'output', 'response', 'messages'], true)) {
                throw new RuntimeException('MHC-08 evidence contains prohibited prompt or response content.');
            }
            if (is_array($item)) {
                $this->assertNoContent($item);
            }
        }
    }

    /** @return array<string,mixed> */
    private function contract(string $kind): array
    {
        if (! isset(self::CONTRACTS[$kind])) {
            throw new RuntimeException('MHC-08 evidence kind is not admitted.');
        }

        return self::CONTRACTS[$kind];
    }

    private function root(): string
    {
        $root = realpath($this->evidenceRoot ?? base_path('scripts/patches/colibri/k3-selective-v162'));
        if ($root === false || ! is_dir($root) || is_link($root)) {
            throw new RuntimeException('MHC-08 private evidence root is unavailable or unsafe.');
        }

        return $root;
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /** @return array{dev:int,ino:int} */
    private function identity(string $path): array
    {
        $stat = @lstat($path);
        if (! is_array($stat)) {
            throw new RuntimeException('MHC-08 evidence path identity is unavailable.');
        }

        return ['dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino']];
    }

    private function mode(string $path): int
    {
        $permissions = @fileperms($path);
        if (! is_int($permissions)) {
            throw new RuntimeException('MHC-08 evidence file permissions are unavailable.');
        }

        return $permissions;
    }

    private function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
