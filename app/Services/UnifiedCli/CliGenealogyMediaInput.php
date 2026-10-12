<?php

namespace App\Services\UnifiedCli;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Explicit tree/path mapping for original media; no download or transformation. */
final class CliGenealogyMediaInput
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly CliDataBindings $data,
        private readonly CliArtifactStore $artifacts,
        private readonly ?CliWorkflowMediaProfile $workflow = null,
    ) {}

    public static function validateRoots(mixed $roots): void
    {
        if (! is_array($roots) || ! array_is_list($roots)) {
            throw new InvalidArgumentException('Media roots must be a list of target/tree/path mappings.');
        }
        foreach ($roots as $root) {
            if (! is_array($root) || count($root) !== 5
                || array_diff(array_keys($root), ['target', 'tree_id', 'field', 'source_root', 'local_root']) !== []
                || ! is_string($root['target'] ?? null) || ! preg_match('/^[a-z][a-z0-9_-]*$/D', $root['target'])
                || ! is_int($root['tree_id'] ?? null) || $root['tree_id'] < 1
                || ! in_array($root['field'] ?? null, ['nextcloud_path', 'original_path', 'local_filename'], true)
                || ! is_string($root['source_root'] ?? null) || ! is_string($root['local_root'] ?? null)) {
                throw new InvalidArgumentException('Each media root requires target, positive tree_id, field, source_root and local_root.');
            }
            if ($root['source_root'] !== '.') {
                self::sourcePath($root['source_root']);
            }
            self::localRoot($root['local_root']);
        }
    }

    public function capture(int $mediaId): array
    {
        if ($mediaId < 1) {
            throw new InvalidArgumentException('Media ID must be a positive integer.');
        }
        $context = $this->context();
        $roots = $this->roots();
        $selected = array_values(array_filter($roots, fn ($root) => $root['target'] === $context['operation_target']
            && $root['tree_id'] === $context['tree_id']));
        if ($selected === []) {
            throw new RuntimeException('Configure cli.media_roots for the selected target and tree before attaching media.');
        }
        $row = isset($context['workflow_service_sha256']) ? $this->workflow->read($mediaId, $context)
            : $this->data->read($context, function () use ($context, $mediaId): array {
                $rows = DB::table('genealogy_media')->where('tree_id', $context['tree_id'])->where('id', $mediaId)
                    ->get(['id', 'tree_id', 'nextcloud_path', 'original_path', 'local_filename']);
                if ($rows->count() !== 1) {
                    throw new RuntimeException('One exact media record in the selected tree is required.');
                }

                return (array) $rows->first();
            });
        $matches = [];
        foreach ($selected as $root) {
            $raw = $row[$root['field']] ?? null;
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $path = self::sourcePath($raw);
            $prefix = $root['source_root'] === '.' ? '' : rtrim(self::sourcePath($root['source_root']), '/').'/';
            if ($prefix === '') {
                if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
                    continue;
                }
                $relative = $path;
            } elseif (str_starts_with($path, $prefix)) {
                $relative = substr($path, strlen($prefix));
            } else {
                continue;
            }
            if ($relative !== '') {
                $matches[] = ['mapping' => $root, 'path' => $root['local_root'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative)];
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('Media path must match exactly one explicit local mapping; no path was guessed.');
        }
        $this->unchanged($context, $roots);
        $match = $matches[0];
        self::localRoot($match['mapping']['local_root']);
        $original = $this->artifacts->importFile($match['path'], [$match['mapping']['local_root']]);
        $this->unchanged($context, $roots);
        $provenance = ['context' => $context, 'media_id' => $mediaId, 'database_path_fields' => $row,
            'mapping' => $match['mapping'], 'original_artifact' => $original];
        $this->store->recordEvent($this->sessionId, 'genealogy_media_input', $provenance);

        return $original + ['genealogy' => ['target' => $context['operation_target'], 'tree_id' => $context['tree_id'],
            'media_id' => $mediaId] + array_intersect_key($context, array_flip(['data_binding_sha256', 'workflow_service_sha256']))];
    }

    private function context(): array
    {
        return $this->workflow?->context() ?? $this->data->context();
    }

    private function roots(): array
    {
        $values = (new CliConfiguration($this->store))->effective($this->store->session($this->sessionId))['values'];
        $roots = $values['cli.media_roots'] ?? [];
        self::validateRoots($roots);

        return $roots;
    }

    private function unchanged(array $context, array $roots): void
    {
        if ($this->context() !== $context || $this->roots() !== $roots) {
            throw new RuntimeException('Target, tree or media mapping changed during capture; no attachment was queued.');
        }
    }

    private static function sourcePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path)
            || str_contains($path, '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)
            || str_ends_with($path, '/') || preg_match('~:(?!/)~', $path)
            || (str_contains($path, ':') && ! preg_match('~^[A-Za-z]:/[^:]+$~D', $path))) {
            throw new InvalidArgumentException('Media source paths must be exact file paths without URLs, traversal or ambiguous separators.');
        }

        return $path;
    }

    private static function localRoot(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || realpath($path) !== $path || ! is_dir($path)
            || is_link($path) || dirname($path) === $path) {
            throw new InvalidArgumentException('Media local_root must be an existing canonical folder, excluding a filesystem root.');
        }
    }
}
