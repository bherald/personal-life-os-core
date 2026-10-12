<?php

namespace App\Services\Ops;

use App\Services\WhiteroomProjectRegistryService;

class UpstreamDocumentationConsistencyService
{
    public const SCHEMA = 'plos.upstream_documentation_consistency.v2';

    /**
     * @param  array<int, array<string, mixed>>|null  $projects
     * @param  array<string, string>|null  $documentContents
     * @return array<string, mixed>
     */
    public function audit(?array $projects = null, ?array $documentContents = null): array
    {
        $projects ??= app(WhiteroomProjectRegistryService::class)->all();
        $checks = [];
        $contractProjects = 0;

        foreach ($projects as $project) {
            $contract = $project['documentation_contract'] ?? null;
            if (! is_array($contract)) {
                continue;
            }

            $contractProjects++;
            $projectKey = trim((string) ($project['key'] ?? ''));
            $identifierScheme = trim((string) ($project['identifier_scheme'] ?? ''));
            $identifier = trim((string) ($project['identifier'] ?? ''));
            $sourceVersion = trim((string) ($project['source_version'] ?? ''));
            $expectedRevision = $sourceVersion !== '' ? $sourceVersion : $identifier;
            $rowLabel = trim((string) ($contract['row_label'] ?? $project['name'] ?? ''));
            $documents = array_values(array_unique(array_filter(array_map(
                static fn (mixed $path): string => trim((string) $path),
                (array) ($contract['documents'] ?? [])
            ))));

            if ($projectKey === '' || $expectedRevision === '' || $rowLabel === '' || $documents === []) {
                $checks[] = $this->check(
                    $projectKey !== '' ? $projectKey : 'unknown',
                    null,
                    $rowLabel,
                    $expectedRevision,
                    false,
                    'documentation_contract_invalid'
                );

                continue;
            }

            if ($identifierScheme === 'git_commit') {
                $identityMatches = preg_match('/^[a-f0-9]{40}$/', $identifier) === 1
                    && preg_match('/^[a-f0-9]{40}$/', $sourceVersion) === 1
                    && hash_equals($sourceVersion, $identifier);
                $checks[] = $this->check(
                    $projectKey,
                    null,
                    $rowLabel,
                    $expectedRevision,
                    $identityMatches,
                    $identityMatches ? null : 'registry_revision_identity_mismatch',
                    'registry_revision_identity'
                );
            }

            foreach ($documents as $path) {
                if (! $this->validRepositoryPath($path)) {
                    $checks[] = $this->check($projectKey, $path, $rowLabel, $expectedRevision, false, 'documentation_path_invalid');

                    continue;
                }

                $contents = $documentContents !== null
                    ? ($documentContents[$path] ?? null)
                    : $this->readDocument($path);
                if (! is_string($contents)) {
                    $checks[] = $this->check($projectKey, $path, $rowLabel, $expectedRevision, false, 'documentation_file_missing');

                    continue;
                }

                $prefix = '| '.$rowLabel.' |';
                $rows = array_values(array_filter(
                    preg_split('/\R/u', $contents) ?: [],
                    static fn (string $line): bool => str_starts_with(trim($line), $prefix)
                ));

                if ($rows === []) {
                    $checks[] = $this->check($projectKey, $path, $rowLabel, $expectedRevision, false, 'documentation_row_missing');

                    continue;
                }

                if (count($rows) !== 1) {
                    $checks[] = $this->check($projectKey, $path, $rowLabel, $expectedRevision, false, 'documentation_row_ambiguous');

                    continue;
                }

                $checks[] = $this->check(
                    $projectKey,
                    $path,
                    $rowLabel,
                    $expectedRevision,
                    str_contains($rows[0], $expectedRevision),
                    str_contains($rows[0], $expectedRevision) ? null : 'documentation_revision_stale'
                );
            }
        }

        $failures = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ! (bool) $check['passed']
        ));

        return [
            'schema' => self::SCHEMA,
            'status' => $failures === [] && $contractProjects > 0 ? 'pass' : 'fail',
            'summary' => [
                'contract_projects' => $contractProjects,
                'checks' => count($checks),
                'passed' => count($checks) - count($failures),
                'failed' => count($failures),
            ],
            'checks' => $checks,
            'posture' => [
                'deterministic' => true,
                'read_only' => true,
                'document_rows_only' => false,
                'document_and_registry_metadata_only' => true,
                'canonical_revision_field' => 'source_version',
                'git_commit_identifier_match_required' => true,
                'raw_document_content_returned' => false,
                'network_calls' => false,
                'llm_calls' => false,
                'database_reads' => false,
                'database_writes' => false,
                'changes_source_pins' => false,
                'changes_adoption_or_runtime_authority' => false,
                'compression' => false,
                'precompaction' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function compact(array $payload): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => $payload['status'] ?? 'fail',
            'compact' => true,
            'summary' => $payload['summary'] ?? [],
            'failures' => array_values(array_filter(
                (array) ($payload['checks'] ?? []),
                static fn (mixed $check): bool => is_array($check) && ! (bool) ($check['passed'] ?? false)
            )),
            'posture' => $payload['posture'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function check(
        string $projectKey,
        ?string $document,
        string $rowLabel,
        string $expectedRevision,
        bool $passed,
        ?string $reasonCode,
        string $checkKind = 'documentation_revision'
    ): array {
        return [
            'check_kind' => $checkKind,
            'project_key' => $projectKey,
            'document' => $document,
            'row_label' => $rowLabel,
            'expected_revision' => $expectedRevision,
            'passed' => $passed,
            'reason_code' => $reasonCode,
        ];
    }

    private function validRepositoryPath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! in_array('..', explode('/', str_replace('\\', '/', $path)), true);
    }

    private function readDocument(string $path): ?string
    {
        $contents = @file_get_contents(base_path($path));

        return is_string($contents) ? $contents : null;
    }
}
