<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyReviewPacketAdapterService;
use App\Services\Genealogy\GenealogyReviewPacketMaterializationService;
use App\Services\Genealogy\GenealogyReviewPacketValidatorService;
use App\Support\JsonColumn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenealogyReviewPacketMaterializeCommand extends Command
{
    private const MAX_PACKET_BYTES = 1048576;

    protected $signature = 'genealogy:materialize-review-packet
                            {--file= : Path to source-backed genealogy review packet JSON}
                            {--execute : Create or reuse one pending genealogy_review_packet row}
                            {--json : Emit machine-readable JSON}
                            {--compact : Emit compact sanitized output}';

    protected $description = 'Dry-run-first operator trigger for source-backed genealogy review packet materialization';

    public function handle(
        GenealogyReviewPacketMaterializationService $materializer,
        GenealogyReviewPacketAdapterService $adapter,
        GenealogyReviewPacketValidatorService $validator,
    ): int {
        $file = $this->loadPacketFile();
        if (($file['success'] ?? false) !== true) {
            return $this->emitFailure($this->basePayload([
                'success' => false,
                'status' => 'failed',
                'action' => 'none',
                'error' => $file['error'] ?? 'packet_file_unreadable',
                'message' => $file['message'] ?? null,
                'file' => $file['file'] ?? $this->filePayload(null, false),
            ]), self::FAILURE);
        }

        $packet = $file['packet'];
        $validation = $validator->validate($packet);
        if (! $validation['valid']) {
            return $this->emitFailure($this->basePayload([
                'success' => false,
                'status' => 'failed',
                'action' => 'none',
                'error' => 'packet_validation_failed',
                'file' => $file['file'],
                'validation' => $validation,
                'packet_summary' => $this->packetSummary($packet, null, $validation),
            ]), self::FAILURE);
        }

        $reviewPayload = $adapter->toReviewPayload($packet);
        $existing = $this->existingPendingPacket($reviewPayload);
        $execute = (bool) $this->option('execute');

        if (! $execute) {
            return $this->emitSuccess($this->basePayload([
                'success' => true,
                'status' => 'dry_run',
                'action' => $existing === null ? 'would_create_packet' : 'would_reuse_existing_packet',
                'file' => $file['file'],
                'validation' => $validation,
                'packet_summary' => $this->packetSummary($packet, $reviewPayload, $validation),
                'packet' => $existing === null ? null : [
                    'review_queue_id' => (int) $existing->id,
                    'token' => (string) $existing->token,
                    'materialized_existing' => true,
                ],
            ]));
        }

        $result = $materializer->materialize($packet);

        $payload = $this->basePayload([
            'success' => (bool) ($result['success'] ?? false),
            'status' => ($result['success'] ?? false) ? 'materialized' : 'failed',
            'action' => ($result['success'] ?? false)
                ? (($result['materialized_existing'] ?? false) ? 'reused_existing_packet' : 'created_packet')
                : 'none',
            'error' => $result['error'] ?? null,
            'message' => $result['message'] ?? null,
            'file' => $file['file'],
            'validation' => $result['validation'] ?? $validation,
            'packet_summary' => $this->packetSummary($packet, $result['payload'] ?? $reviewPayload, $result['validation'] ?? $validation),
            'packet' => ($result['success'] ?? false) ? [
                'review_queue_id' => (int) ($result['review_queue_id'] ?? 0),
                'token' => (string) ($result['token'] ?? ''),
                'materialized_existing' => (bool) ($result['materialized_existing'] ?? false),
            ] : null,
        ]);

        if (! ($result['success'] ?? false)) {
            return $this->emitFailure($payload, self::FAILURE);
        }

        return $this->emitSuccess($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPacketFile(): array
    {
        $rawPath = $this->text($this->option('file'));
        if ($rawPath === null) {
            return [
                'success' => false,
                'error' => 'packet_file_required',
                'message' => 'Provide --file with a source-backed genealogy review packet JSON path.',
                'file' => $this->filePayload(null, false),
            ];
        }

        $path = $this->expandHome($rawPath);
        if (! is_file($path) || ! is_readable($path)) {
            return [
                'success' => false,
                'error' => 'packet_file_unreadable',
                'message' => 'Packet file does not exist or is not readable.',
                'file' => $this->filePayload($path, false),
            ];
        }

        $size = filesize($path);
        if ($size === false || $size > self::MAX_PACKET_BYTES) {
            return [
                'success' => false,
                'error' => 'packet_file_too_large',
                'message' => 'Packet file must be 1 MiB or smaller.',
                'file' => $this->filePayload($path, true),
            ];
        }

        $raw = file_get_contents($path);
        if (! is_string($raw)) {
            return [
                'success' => false,
                'error' => 'packet_file_unreadable',
                'message' => 'Packet file could not be read.',
                'file' => $this->filePayload($path, true),
            ];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return [
                'success' => false,
                'error' => 'packet_json_invalid',
                'message' => 'Packet file must contain a JSON object.',
                'file' => $this->filePayload($path, true),
            ];
        }

        $packet = is_array($decoded['packet'] ?? null) ? $decoded['packet'] : $decoded;
        if ($this->isList($packet)) {
            return [
                'success' => false,
                'error' => 'packet_json_invalid',
                'message' => 'Packet JSON must be an object, not a list.',
                'file' => $this->filePayload($path, true),
            ];
        }

        return [
            'success' => true,
            'file' => $this->filePayload($path, true),
            'packet' => $packet,
        ];
    }

    /**
     * @param  array<string, mixed>  $reviewPayload
     */
    private function existingPendingPacket(array $reviewPayload): ?object
    {
        $dedupKey = $this->text($reviewPayload['details']['dedup_key'] ?? null);
        $title = (string) ($reviewPayload['title'] ?? '');

        $query = DB::table('agent_review_queue')
            ->select(['id', 'token'])
            ->where('agent_id', (string) ($reviewPayload['agent_id'] ?? GenealogyReviewPacketAdapterService::AGENT_ID))
            ->where('review_type', GenealogyReviewPacketAdapterService::REVIEW_TYPE)
            ->where('status', 'pending');

        if ($dedupKey !== null) {
            JsonColumn::whereScalarEquals($query, 'details', '$.dedup_key', $dedupKey);
        } else {
            $query->where('title', $title);
        }

        return $query->orderBy('id')->first();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'mode' => $this->option('execute') ? 'execute' : 'dry_run',
            'execute' => (bool) $this->option('execute'),
            'dry_run' => ! (bool) $this->option('execute'),
            'no_canonical_write' => true,
            'canonical_writes_performed' => false,
            'apply_held' => true,
            'apply_performed' => false,
            'safety' => $this->safetyPayload(),
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function filePayload(?string $path, bool $readable): array
    {
        return [
            'path' => $path,
            'path_present' => $path !== null,
            'readable' => $readable,
            'size_bytes' => $path !== null && is_file($path) ? filesize($path) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $packet
     * @param  array<string, mixed>|null  $reviewPayload
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    private function packetSummary(array $packet, ?array $reviewPayload, array $validation): array
    {
        $details = is_array($reviewPayload['details'] ?? null) ? $reviewPayload['details'] : [];
        $applyPreview = is_array($details['apply_preview'] ?? null) ? $details['apply_preview'] : [];
        $sprint = is_array($details['sprint'] ?? null) ? $details['sprint'] : [];
        $identity = is_array($details['identity'] ?? null) ? $details['identity'] : [];
        $privacy = is_array($details['privacy'] ?? null) ? $details['privacy'] : [];

        return [
            'target_review_type' => GenealogyReviewPacketAdapterService::REVIEW_TYPE,
            'agent_id' => (string) ($reviewPayload['agent_id'] ?? GenealogyReviewPacketAdapterService::AGENT_ID),
            'dedup_key_present' => $this->text($details['dedup_key'] ?? null) !== null,
            'source_locator_count' => is_array($details['source_locators'] ?? null)
                ? count($details['source_locators'])
                : 0,
            'claim_count' => is_array($details['claims'] ?? null)
                ? count(array_filter($details['claims'], 'is_array'))
                : $this->fallbackClaimCount($packet),
            'identity_present' => $identity !== [] || isset($packet['person_id']) || isset($packet['target_person_id']),
            'privacy_present' => $privacy !== [] || isset($packet['privacy']),
            'boundary_present' => $this->text($sprint['boundary_label'] ?? $packet['sprint_boundary'] ?? $packet['boundary_label'] ?? null) !== null,
            'validation_valid' => (bool) ($validation['valid'] ?? false),
            'validation_error_count' => count($validation['errors'] ?? []),
            'validation_warning_count' => count($validation['warnings'] ?? []),
            'preview_only' => $this->previewOnly($applyPreview),
            'mutates_accepted_facts' => (bool) ($applyPreview['mutates_accepted_facts'] ?? false),
        ];
    }

    private function fallbackClaimCount(array $packet): int
    {
        $claims = $packet['claims'] ?? $packet['extracted_claims'] ?? $packet['facts'] ?? $packet['proposals'] ?? [];
        if (is_array($claims)) {
            return $this->isList($claims) ? count(array_filter($claims, 'is_array')) : 1;
        }

        return isset($packet['claim']) || isset($packet['claim_text']) || isset($packet['extracted_claim']) ? 1 : 0;
    }

    private function previewOnly(array $preview): bool
    {
        if ($preview === []) {
            return false;
        }

        if (($preview['mutates_accepted_facts'] ?? null) !== false) {
            return false;
        }

        if (is_array($preview['accepted_fact_mutations'] ?? null) && $preview['accepted_fact_mutations'] !== []) {
            return false;
        }

        foreach ((array) ($preview['operations'] ?? []) as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            if (($operation['mutates_accepted_facts'] ?? null) === true || ($operation['apply_enabled'] ?? null) === true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, bool|string>
     */
    private function safetyPayload(): array
    {
        return [
            'scope' => 'review_packet_queue_only',
            'target_review_type' => GenealogyReviewPacketAdapterService::REVIEW_TYPE,
            'preview_only' => true,
            'no_canonical_write' => true,
            'canonical_write_allowed' => false,
            'canonical_writes_performed' => false,
            'apply_held' => true,
            'apply_enabled' => false,
            'apply_performed' => false,
        ];
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function expandHome(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME');
            if (is_string($home) && $home !== '') {
                return $home.substr($path, 1);
            }
        }

        return $path;
    }

    private function isList(array $value): bool
    {
        return array_is_list($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitSuccess(array $payload): int
    {
        return $this->emit($payload, self::SUCCESS);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitFailure(array $payload, int $exitCode): int
    {
        return $this->emit($payload, $exitCode);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload, int $exitCode): int
    {
        $emitPayload = $this->option('compact') ? $this->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($emitPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy review packet materialization JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return $exitCode;
        }

        if ($this->option('compact')) {
            $summary = is_array($emitPayload['packet_summary'] ?? null) ? $emitPayload['packet_summary'] : [];
            $this->line(sprintf(
                'Genealogy review packet materialization compact: status=%s mode=%s action=%s success=%s source_locators=%s claims=%s boundary=%s preview_only=%s no_canonical_write=%s apply_held=%s',
                (string) ($emitPayload['status'] ?? 'unknown'),
                (string) ($emitPayload['mode'] ?? 'unknown'),
                (string) ($emitPayload['action'] ?? 'unknown'),
                ($emitPayload['success'] ?? false) ? 'yes' : 'no',
                (string) ($summary['source_locator_count'] ?? 0),
                (string) ($summary['claim_count'] ?? 0),
                ($summary['boundary_present'] ?? false) ? 'yes' : 'no',
                ($summary['preview_only'] ?? false) ? 'yes' : 'no',
                ($emitPayload['safety']['no_canonical_write'] ?? false) ? 'yes' : 'no',
                ($emitPayload['safety']['apply_held'] ?? false) ? 'yes' : 'no',
            ));

            return $exitCode;
        }

        $this->line(sprintf(
            'Genealogy review packet materialization: status=%s mode=%s action=%s execute=%s no_canonical_write=%s apply_held=%s',
            (string) ($payload['status'] ?? 'unknown'),
            (string) ($payload['mode'] ?? 'unknown'),
            (string) ($payload['action'] ?? 'unknown'),
            ($payload['execute'] ?? false) ? 'yes' : 'no',
            ($payload['safety']['no_canonical_write'] ?? false) ? 'yes' : 'no',
            ($payload['safety']['apply_held'] ?? false) ? 'yes' : 'no',
        ));

        if (($payload['success'] ?? false) !== true) {
            $this->error((string) ($payload['message'] ?? $payload['error'] ?? 'Materialization failed.'));
        } elseif (($payload['execute'] ?? false) !== true) {
            $this->warn('dry-run only; add --execute to create or reuse a pending genealogy_review_packet row.');
        }

        return $exitCode;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        $packet = is_array($payload['packet'] ?? null) ? $payload['packet'] : null;
        $file = is_array($payload['file'] ?? null) ? $payload['file'] : [];

        return [
            'version' => $payload['version'] ?? 1,
            'generated_at' => $payload['generated_at'] ?? null,
            'mode' => $payload['mode'] ?? 'unknown',
            'execute' => (bool) ($payload['execute'] ?? false),
            'dry_run' => (bool) ($payload['dry_run'] ?? false),
            'success' => (bool) ($payload['success'] ?? false),
            'status' => $payload['status'] ?? 'unknown',
            'error' => $payload['error'] ?? null,
            'action' => $payload['action'] ?? 'unknown',
            'file' => [
                'path_present' => (bool) ($file['path_present'] ?? false),
                'readable' => (bool) ($file['readable'] ?? false),
                'size_bytes' => is_numeric($file['size_bytes'] ?? null) ? (int) $file['size_bytes'] : null,
            ],
            'validation' => [
                'valid' => (bool) ($payload['validation']['valid'] ?? false),
                'error_count' => count($payload['validation']['errors'] ?? []),
                'warning_count' => count($payload['validation']['warnings'] ?? []),
                'error_codes' => $this->validationCodes($payload['validation']['errors'] ?? []),
            ],
            'packet_summary' => $this->compactPacketSummary($payload['packet_summary'] ?? []),
            'packet' => $packet === null ? null : [
                'present' => true,
                'materialized_existing' => (bool) ($packet['materialized_existing'] ?? false),
            ],
            'safety' => $payload['safety'] ?? $this->safetyPayload(),
        ];
    }

    private function compactPacketSummary(mixed $summary): array
    {
        if (! is_array($summary)) {
            return [];
        }

        return [
            'target_review_type' => $summary['target_review_type'] ?? GenealogyReviewPacketAdapterService::REVIEW_TYPE,
            'source_locator_count' => (int) ($summary['source_locator_count'] ?? 0),
            'claim_count' => (int) ($summary['claim_count'] ?? 0),
            'identity_present' => (bool) ($summary['identity_present'] ?? false),
            'privacy_present' => (bool) ($summary['privacy_present'] ?? false),
            'boundary_present' => (bool) ($summary['boundary_present'] ?? false),
            'validation_valid' => (bool) ($summary['validation_valid'] ?? false),
            'validation_error_count' => (int) ($summary['validation_error_count'] ?? 0),
            'validation_warning_count' => (int) ($summary['validation_warning_count'] ?? 0),
            'preview_only' => (bool) ($summary['preview_only'] ?? false),
            'mutates_accepted_facts' => (bool) ($summary['mutates_accepted_facts'] ?? false),
            'dedup_key_present' => (bool) ($summary['dedup_key_present'] ?? false),
        ];
    }

    /**
     * @return list<string>
     */
    private function validationCodes(mixed $errors): array
    {
        if (! is_array($errors)) {
            return [];
        }

        $codes = [];
        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $code = $this->text($error['code'] ?? null);
            if ($code !== null && preg_match('/^[a-z0-9_:-]{1,80}$/', $code) === 1) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }
}
