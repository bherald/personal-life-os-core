<?php

namespace App\Console\Commands;

use App\Services\WorkflowPortableDefinitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class WorkflowExport extends Command
{
    protected $signature = 'workflow:export
        {name : Workflow name}
        {--path= : Destination JSON path}
        {--write : Write the portable definition}
        {--json : Emit machine-readable output}';

    protected $description = 'Preview or write a validated, secret-safe portable workflow definition';

    public function handle(WorkflowPortableDefinitionService $definitions): int
    {
        try {
            $manifest = $definitions->export((string) $this->argument('name'));
            $write = (bool) $this->option('write');
            $path = $this->outputPath((string) $manifest['workflow']['name']);
            if ($write) {
                File::ensureDirectoryExists(dirname($path), 0700, true);
                $encoded = json_encode(
                    $manifest,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                if (file_put_contents($path, $encoded."\n", LOCK_EX) === false) {
                    throw new \RuntimeException('Unable to write portable workflow definition.');
                }
                chmod($path, 0600);
            }

            return $this->finish([
                'schema' => WorkflowPortableDefinitionService::SCHEMA,
                'status' => $write ? 'exported' : 'preview',
                'mode' => $write ? 'write' : 'preview',
                'read_only' => ! $write,
                'path' => $write ? $path : null,
                'summary' => [
                    'name' => $manifest['workflow']['name'],
                    'nodes' => count($manifest['workflow']['nodes']),
                    'warnings' => count($manifest['warnings']),
                    'workflow_sha256' => $manifest['integrity']['workflow_sha256'],
                ],
                'manifest' => $this->option('json') && ! $write ? $manifest : null,
            ]);
        } catch (Throwable $e) {
            return $this->finish([
                'schema' => WorkflowPortableDefinitionService::SCHEMA,
                'status' => 'blocked',
                'read_only' => true,
                'error' => $e->getMessage(),
            ], self::FAILURE);
        }
    }

    private function outputPath(string $name): string
    {
        $custom = trim((string) ($this->option('path') ?: ''));
        if ($custom !== '') {
            return $custom;
        }
        $safeName = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name), '-');

        return storage_path('workflows/'.($safeName !== '' ? $safeName : 'workflow').'-'.now()->format('Ymd_His').'.json');
    }

    /** @param array<string,mixed> $payload */
    private function finish(array $payload, int $exit = self::SUCCESS): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('workflow:export '.($payload['status'] ?? 'unknown'));
            if (isset($payload['path'])) {
                $this->line('File: '.$payload['path']);
            }
            if (isset($payload['error'])) {
                $this->error((string) $payload['error']);
            }
        }

        return $exit;
    }
}
