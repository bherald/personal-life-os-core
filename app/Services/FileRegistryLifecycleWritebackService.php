<?php

namespace App\Services;

class FileRegistryLifecycleWritebackService
{
    public const CONFIRMATION_TOKEN = 'FILE-LIFECYCLE-WRITEBACK';

    private const MOVED_BY = 'lifecycle_writeback';

    private const MOVE_REASON = 'Manual file lifecycle writeback move selector';

    private const SOFT_DELETE_REASON = 'Manual file lifecycle writeback soft-delete selector';

    public function __construct(
        private FileRegistryLifecycleService $lifecycle
    ) {}

    public function run(array $moveSelectors, array $softDeleteSelectors, bool $apply): array
    {
        $parsed = $this->parseSelectors($moveSelectors, $softDeleteSelectors);
        $actions = $parsed['actions'];

        $payload = [
            'version' => 1,
            'mode' => $apply ? 'apply' : 'dry_run',
            'status' => $parsed['errors'] === [] ? ($apply ? 'applied' : 'planned') : 'blocked',
            'posture' => [
                'read_only' => ! $apply,
                'writes_enabled' => $apply,
                'move_apply_enabled' => $apply,
                'delete_apply_enabled' => $apply,
                'filesystem_mutation_enabled' => false,
                'nextcloud_mutation_enabled' => false,
                'direct_file_registry_delete_enabled' => false,
                'hard_purge_enabled' => false,
                'canonical_remap_enabled' => false,
                'rag_cleanup_enabled' => false,
            ],
            'counts' => [
                'planned' => count($actions),
                'applied' => 0,
                'failed' => 0,
                'blocked' => 0,
            ],
            'actions' => $actions,
            'unsupported_operations' => $this->unsupportedOperations(),
            'errors' => $parsed['errors'],
        ];

        if (! $apply || $parsed['errors'] !== []) {
            return $payload;
        }

        foreach ($payload['actions'] as $index => $action) {
            $result = match ($action['operation']) {
                'move' => $this->lifecycle->remapFilePath(
                    $action['asset_uuid'],
                    $action['new_path'],
                    self::MOVED_BY,
                    self::MOVE_REASON
                ),
                'soft_delete' => $this->lifecycle->deleteFileFromRegistry(
                    $action['asset_uuid'],
                    null,
                    $action['reason']
                ),
            };

            $payload['actions'][$index]['result'] = $result;
            $payload['actions'][$index]['status'] = $result ? 'applied' : 'failed';
            $payload['counts'][$result ? 'applied' : 'failed']++;
        }

        if ($payload['counts']['failed'] > 0) {
            $payload['status'] = 'partial_failure';
        }

        return $payload;
    }

    public function unsupportedOperations(): array
    {
        return [
            'hard_purge' => [
                'status' => 'blocked',
                'reason' => 'Hard purge is intentionally unsupported in this writeback slice.',
            ],
            'canonical_remap' => [
                'status' => 'blocked',
                'reason' => 'Canonical remap is intentionally unsupported in this writeback slice.',
            ],
            'rag_cleanup' => [
                'status' => 'blocked',
                'reason' => 'RAG cleanup is intentionally unsupported in this writeback slice.',
            ],
        ];
    }

    private function parseSelectors(array $moveSelectors, array $softDeleteSelectors): array
    {
        $actions = [];
        $errors = [];

        foreach ($moveSelectors as $selector) {
            $selector = trim((string) $selector);
            if ($selector === '' || ! str_contains($selector, '=')) {
                $errors[] = 'Move selectors must use asset_uuid=/new/path.';
                continue;
            }

            [$assetUuid, $newPath] = array_map('trim', explode('=', $selector, 2));
            if ($assetUuid === '' || $newPath === '') {
                $errors[] = 'Move selectors must include both asset_uuid and new_path.';
                continue;
            }

            $actions[] = [
                'operation' => 'move',
                'asset_uuid' => $assetUuid,
                'new_path' => '/'.ltrim($newPath, '/'),
                'status' => 'planned',
                'result' => null,
            ];
        }

        foreach ($softDeleteSelectors as $selector) {
            $selector = trim((string) $selector);
            if ($selector === '') {
                $errors[] = 'Soft-delete selectors must include an asset_uuid.';
                continue;
            }

            [$assetUuid, $reason] = array_pad(array_map('trim', explode('=', $selector, 2)), 2, '');
            if ($assetUuid === '') {
                $errors[] = 'Soft-delete selectors must include an asset_uuid.';
                continue;
            }

            $actions[] = [
                'operation' => 'soft_delete',
                'asset_uuid' => $assetUuid,
                'reason' => $reason !== '' ? $reason : self::SOFT_DELETE_REASON,
                'status' => 'planned',
                'result' => null,
            ];
        }

        return [
            'actions' => $actions,
            'errors' => $errors,
        ];
    }
}
