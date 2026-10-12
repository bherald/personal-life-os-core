<?php

namespace App\Services\Ops;

use RuntimeException;
use Symfony\Component\Process\Process;

class ReleaseDocumentationAuditService
{
    /**
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    public function evaluate(array $changedFiles): array
    {
        $files = $this->normalizePaths($changedFiles);
        $productionFiles = array_values(array_filter(
            $files,
            fn (string $path): bool => $this->matchesSurface($path, (array) config('release_documentation.production_surface', []))
        ));
        $documentationFiles = array_values(array_filter(
            $files,
            static fn (string $path): bool => str_starts_with($path, 'docs/')
                || in_array($path, ['README.md', 'AGENTS.md', 'CLAUDE.md', 'THIRD_PARTY.md', 'NOTICE.md'], true)
        ));

        $configuredRules = (array) config('release_documentation.rules', []);
        $ruleResults = [];
        foreach ($configuredRules as $rule) {
            if (! is_array($rule) || ! $this->ruleTriggered($rule, $files, $productionFiles)) {
                continue;
            }

            $required = $this->normalizePaths((array) ($rule['required_all'] ?? []));
            $missing = array_values(array_diff($required, $files));
            $valid = $required !== [];
            $ruleResults[] = [
                'rule' => (string) ($rule['key'] ?? 'unnamed_rule'),
                'passed' => $valid && $missing === [],
                'reason_code' => ! $valid
                    ? 'release_documentation_policy_invalid'
                    : ($missing === [] ? null : (string) ($rule['reason_code'] ?? 'required_documentation_missing')),
                'required_documents' => $required,
                'missing_documents' => $missing,
            ];
        }

        if ($productionFiles !== [] && $ruleResults === []) {
            $ruleResults[] = [
                'rule' => 'release_documentation_policy',
                'passed' => false,
                'reason_code' => 'release_documentation_policy_missing',
                'required_documents' => [],
                'missing_documents' => [],
            ];
        }

        $failedRules = array_values(array_filter(
            $ruleResults,
            static fn (array $result): bool => ! (bool) ($result['passed'] ?? false)
        ));
        $missingDocuments = array_values(array_unique(array_merge(...array_map(
            static fn (array $result): array => (array) ($result['missing_documents'] ?? []),
            $failedRules
        ))));

        return [
            'schema' => (string) config('release_documentation.schema', 'plos.release_documentation_audit.v1'),
            'status' => $failedRules === [] ? 'pass' : 'fail',
            'applicable' => $productionFiles !== [],
            'summary' => [
                'changed_files' => count($files),
                'production_files' => count($productionFiles),
                'documentation_files' => count($documentationFiles),
                'triggered_rules' => count($ruleResults),
                'passed_rules' => count($ruleResults) - count($failedRules),
                'failed_rules' => count($failedRules),
                'missing_documents' => count($missingDocuments),
            ],
            'changed_files' => $files,
            'production_files' => $productionFiles,
            'documentation_files' => $documentationFiles,
            'rules' => $ruleResults,
            'posture' => [
                'deterministic' => true,
                'read_only' => true,
                'diff_body_read' => false,
                'document_body_read' => false,
                'llm_calls' => false,
                'network_calls' => false,
                'database_reads' => false,
                'database_writes' => false,
                'content_correctness_claimed' => false,
            ],
        ];
    }

    /** @return list<string> */
    public function collectChangedFiles(string $base, string $head = 'HEAD', bool $staged = false): array
    {
        $command = $staged
            ? ['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMR', '--no-renames', '--']
            : ['git', 'diff', '--name-only', '--diff-filter=ACMR', '--no-renames', $this->revision($base).'..'.$this->revision($head), '--'];
        $process = new Process($command, base_path());
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Unable to inspect the requested Git release range.');
        }

        return $this->normalizePaths(preg_split('/\R/u', $process->getOutput()) ?: []);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function compactPayload(array $payload): array
    {
        $failedRules = array_values(array_filter(
            (array) ($payload['rules'] ?? []),
            static fn (mixed $result): bool => is_array($result) && ! (bool) ($result['passed'] ?? false)
        ));

        return [
            'schema' => $payload['schema'] ?? 'plos.release_documentation_audit.v1',
            'status' => $payload['status'] ?? 'fail',
            'applicable' => $payload['applicable'] ?? false,
            'compact' => true,
            'summary' => $payload['summary'] ?? [],
            'failed_rules' => array_map(
                static fn (array $result): array => [
                    'rule' => $result['rule'] ?? 'unnamed_rule',
                    'reason_code' => $result['reason_code'] ?? 'required_documentation_missing',
                    'missing_documents' => $result['missing_documents'] ?? [],
                ],
                $failedRules
            ),
            'posture' => $payload['posture'] ?? [],
        ];
    }

    /** @param array<string,mixed> $rule @param list<string> $files @param list<string> $productionFiles */
    private function ruleTriggered(array $rule, array $files, array $productionFiles): bool
    {
        if (($rule['trigger'] ?? null) === 'production_surface' && $productionFiles !== []) {
            return true;
        }

        return $this->matchesSurface($files, [
            'prefixes' => (array) ($rule['trigger_prefixes'] ?? []),
            'exact' => (array) ($rule['trigger_exact'] ?? []),
        ]);
    }

    /** @param string|list<string> $paths @param array<string,mixed> $surface */
    private function matchesSurface(string|array $paths, array $surface): bool
    {
        foreach ((array) $paths as $path) {
            if (in_array($path, (array) ($surface['exact'] ?? []), true)) {
                return true;
            }

            foreach ((array) ($surface['prefixes'] ?? []) as $prefix) {
                if ($prefix !== '' && str_starts_with($path, (string) $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<mixed> $paths @return list<string> */
    private function normalizePaths(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '' || str_starts_with($path, '../') || str_starts_with($path, '/')) {
                continue;
            }
            $path = preg_replace('#^\./#', '', $path) ?? $path;
            $normalized[] = $path;
        }

        return array_values(array_unique($normalized));
    }

    private function revision(string $revision): string
    {
        $revision = trim($revision);
        if ($revision === '' || str_starts_with($revision, '-') || preg_match('/^[A-Za-z0-9_\.\/~^:-]+$/', $revision) !== 1) {
            throw new RuntimeException('Invalid Git revision for release documentation audit.');
        }

        return $revision;
    }
}
