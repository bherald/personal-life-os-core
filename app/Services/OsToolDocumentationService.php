<?php

namespace App\Services;

use Closure;
use Symfony\Component\Process\Process;

/** Local documentation is untrusted evidence, never permission or executable instructions. */
class OsToolDocumentationService
{
    public const MAX_SOURCE_BYTES = 262144;

    public const MAX_DECODED_BYTES = 262144;

    public const MAX_EXCERPT_CHARACTERS = 2000;

    public const MAX_DOCUMENTS = 6;

    private array $roots;

    private bool $requireProtectedPaths;

    /** Root overrides are for isolated tests, not tool arguments or discovered metadata. */
    public function __construct(?array $roots = null, private readonly ?Closure $packageQuery = null)
    {
        $this->requireProtectedPaths = $roots === null;
        $this->roots = $roots ?? ['man1' => '/usr/share/man/man1', 'man8' => '/usr/share/man/man8', 'doc' => '/usr/share/doc'];
    }

    public function evidence(string $toolName, ?string $packageName = null, array $executablePaths = []): array
    {
        $result = ['schema' => 'plos.os-tools.documentation.v1', 'status' => 'missing',
            'observed_at' => gmdate(DATE_ATOM), 'tool' => $toolName, 'package' => $packageName,
            'package_association' => $packageName === null ? 'unknown' : 'caller_supplied_not_verified',
            'documents' => [], 'license_evidence' => ['status' => 'missing', 'source_ids' => []],
            'warnings' => [], 'trust' => 'untrusted_documentation', 'execution_authorized' => false,
            'runtime_probes_executed' => false, 'network_requests_made' => false,
            'scope' => 'fixed_local_man1_man8_and_selected_package_doc_files',
            'bounded_documentation_complete' => false, 'complete_documentation' => false];
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9+_.-]{0,127}\z/', $toolName)
            || ($packageName !== null && ! preg_match('/\A[a-z0-9][a-z0-9+.-]{0,127}\z/', $packageName))) {
            $result['status'] = 'invalid_name';
            $result['tool'] = null;
            $result['package'] = null;

            return $result;
        }

        $ownership = $this->packageOwnership($executablePaths);
        $result['package_ownership'] = $ownership;
        if ($ownership['status'] === 'verified_installed_owner') {
            $packageName = explode(':', $ownership['package'], 2)[0];
            $result['package'] = $packageName;
            $result['package_association'] = 'verified_local_package_file_ownership';
        }
        $result['package_dependencies'] = $ownership['dependencies'] ?? ['status' => 'unknown', 'groups' => []];
        $candidates = [];
        if ($packageName !== null) {
            $candidates[] = ['doc', $packageName.'/copyright', 'license'];
        }
        foreach (['1', '8'] as $section) {
            $candidates[] = ['man'.$section, $toolName.'.'.$section, 'man'];
        }
        if ($packageName !== null) {
            foreach (['README.Debian', 'README', 'README.md'] as $name) {
                $candidates[] = ['doc', $packageName.'/'.$name, 'readme'];
            }
        }
        foreach ($candidates as [$rootId, $relative, $kind]) {
            foreach (['', '.gz'] as $suffix) {
                $sourceId = $rootId.':'.$relative.$suffix;
                $document = $this->read($rootId, $relative.$suffix, $kind, $sourceId);
                if ($document === null) {
                    continue;
                }
                if (isset($document['rejected'])) {
                    $result['warnings'][] = ['source_id' => $sourceId, 'reason' => $document['rejected']];

                    continue;
                }
                $result['documents'][] = $document;
                if ($kind === 'license') {
                    $result['license_evidence'] = ['status' => 'present_unreviewed', 'source_ids' => [$sourceId],
                        'declared_labels' => $document['license_fields']['declared_labels'],
                        'copyright_statements' => $document['license_fields']['copyright_statements'],
                        'extraction_complete' => $document['license_fields']['extraction_complete'],
                        'package_association' => $result['package_association'],
                        'permission_decision' => 'human_review_required', 'legal_compatibility' => 'not_determined'];
                } elseif ($document['excerpt'] !== '') {
                    $result['status'] = 'found';
                }
                break;
            }
            if (count($result['documents']) >= self::MAX_DOCUMENTS) {
                break;
            }
        }

        $result['bounded_documentation_complete'] = $result['status'] === 'found' && $result['warnings'] === []
            && ($result['license_evidence']['extraction_complete'] ?? false) === true;

        return $result;
    }

    private function read(string $rootId, string $relative, string $kind, string $sourceId): ?array
    {
        $configuredRoot = $this->roots[$rootId] ?? null;
        if (! is_string($configuredRoot) || ! str_starts_with($configuredRoot, '/')) {
            return null;
        }
        $root = realpath($configuredRoot);
        if ($root === false || ! is_dir($root)) {
            return null;
        }
        $candidate = $root.'/'.$relative;
        clearstatcache(true, $candidate);
        if (! file_exists($candidate) && ! is_link($candidate)) {
            return null;
        }
        $resolved = realpath($candidate);
        if ($resolved === false || ! str_starts_with($resolved, $root.'/') || ! is_file($resolved)) {
            return ['rejected' => 'not_contained_regular_file'];
        }
        if ($this->requireProtectedPaths && (! $this->protectedPath($candidate) || ! $this->protectedPath($resolved))) {
            return ['rejected' => 'unprotected_document_path'];
        }
        $before = @stat($resolved);
        if ($before === false || $before['size'] > self::MAX_SOURCE_BYTES) {
            return ['rejected' => 'source_size_limit_or_unreadable'];
        }
        $handle = @fopen($resolved, 'rb');
        if ($handle === false) {
            return ['rejected' => 'unreadable'];
        }
        try {
            $opened = fstat($handle);
            if (! $this->sameFile($before, $opened) || ($opened['mode'] & 0170000) !== 0100000) {
                return ['rejected' => 'source_changed'];
            }
            $bytes = stream_get_contents($handle, self::MAX_SOURCE_BYTES + 1);
            clearstatcache(true, $candidate);
            clearstatcache(true, $resolved);
            if ($bytes === false || strlen($bytes) > self::MAX_SOURCE_BYTES
                || realpath($candidate) !== $resolved || ! $this->sameFile($opened, fstat($handle))
                || ! $this->sameFile($opened, @stat($resolved))) {
                return ['rejected' => 'source_changed_or_size_limit'];
            }
        } finally {
            fclose($handle);
        }
        $decoded = str_ends_with($relative, '.gz') ? @gzdecode($bytes, self::MAX_DECODED_BYTES + 1) : $bytes;
        if ($decoded === false || strlen($decoded) > self::MAX_DECODED_BYTES) {
            return ['rejected' => 'invalid_gzip_or_decoded_size_limit'];
        }
        $text = $this->plainText($decoded, $kind === 'man');
        $excerpt = mb_substr($text, 0, self::MAX_EXCERPT_CHARACTERS, 'UTF-8');

        return ['source_id' => $sourceId, 'kind' => $kind, 'sha256' => hash('sha256', $bytes),
            'decoded_sha256' => hash('sha256', $decoded), 'source_bytes' => strlen($bytes),
            'decoded_bytes' => strlen($decoded), 'source_modified_at' => gmdate(DATE_ATOM, $opened['mtime']),
            'observed_at' => gmdate(DATE_ATOM), 'excerpt' => $excerpt,
            'truncated' => mb_strlen($text, 'UTF-8') > self::MAX_EXCERPT_CHARACTERS,
            'representation' => $kind === 'man' ? 'plain_excerpt_without_roff_execution' : 'plain_text_excerpt',
            'license_review' => $kind === 'license' ? 'bounded_fields_extracted_human_review_required' : 'not_applicable',
            'license_fields' => $kind === 'license' ? $this->licenseFields($text) : null,
            'trust' => 'untrusted_documentation'];
    }

    /** Package database reads only; never run a discovered executable. */
    private function packageOwnership(array $paths): array
    {
        $unknown = ['status' => 'unknown', 'basis' => 'local_dpkg_file_list_not_binary_integrity_or_permission',
            'paths' => [], 'execution_authorized' => false];
        if ($paths === []) {
            return $unknown + ['reason' => 'no_executable_paths'];
        }
        if (PHP_OS_FAMILY !== 'Linux' || count($paths) > 4) {
            return $unknown + ['reason' => 'unsupported_platform_or_path_limit'];
        }
        $owners = [];
        foreach (array_unique($paths) as $path) {
            $canonical = is_string($path) ? realpath($path) : false;
            if ($canonical === false || ! is_file($canonical) || preg_match('/[\x00-\x1f\x7f*?\[\]\\\\]/', $canonical)) {
                return $unknown + ['reason' => 'unsafe_or_missing_canonical_path'];
            }
            $query = $this->query(['--search', '--', $canonical]);
            if (! $query['complete']) {
                return $unknown + ['reason' => 'ownership_query_unavailable_or_truncated'];
            }
            $matching = [];
            foreach (explode("\n", trim($query['stdout'])) as $line) {
                if (preg_match('/^([a-z0-9][a-z0-9+.-]*(?::[a-z0-9-]+)?): (.*)$/D', $line, $match)
                    && $match[2] === $canonical) {
                    $matching[] = $match[1];
                }
            }
            $matching = array_values(array_unique($matching));
            if (count($matching) !== 1) {
                return $unknown + ['reason' => 'missing_or_ambiguous_file_owner'];
            }
            $owners[] = ['path_sha256' => hash('sha256', $path), 'canonical_path_sha256' => hash('sha256', $canonical),
                'package' => $matching[0], 'ownership_output_sha256' => hash('sha256', $query['stdout'])];
        }
        $names = array_values(array_unique(array_column($owners, 'package')));
        if (count($names) !== 1) {
            return $unknown + ['reason' => 'different_executable_owners'];
        }
        $format = '${binary:Package}\t${db:Status-Status}\t${Version}\t${Depends}\t${Pre-Depends}\n';
        $metadata = $this->query(['--show', '--showformat='.$format, '--', $names[0]]);
        $fields = explode("\t", rtrim($metadata['stdout'], "\n"));
        if (! $metadata['complete'] || count($fields) !== 5 || $fields[0] !== $names[0] || $fields[1] !== 'installed'
            || $fields[2] === '' || preg_match('/[\r\n\x00]/', implode('', $fields))) {
            return $unknown + ['reason' => 'owner_not_installed_or_metadata_incomplete'];
        }
        // A second exact ownership read refuses a changed package database observation.
        foreach (array_values(array_unique($paths)) as $index => $path) {
            $canonical = realpath($path);
            if ($canonical === false || hash('sha256', $canonical) !== $owners[$index]['canonical_path_sha256']) {
                return $unknown + ['reason' => 'ownership_changed_during_review'];
            }
            $again = $this->query(['--search', '--', $canonical]);
            if (! $again['complete'] || hash('sha256', $again['stdout']) !== $owners[$index]['ownership_output_sha256']) {
                return $unknown + ['reason' => 'ownership_changed_during_review'];
            }
        }
        $groups = [];
        foreach (['depends' => $fields[3], 'pre_depends' => $fields[4]] as $kind => $value) {
            foreach (array_filter(array_map('trim', explode(',', $value)), fn ($entry) => $entry !== '') as $group) {
                if (count($groups) >= 64 || strlen($group) > 1024) {
                    return $unknown + ['reason' => 'dependency_metadata_limit'];
                }
                $alternatives = [];
                foreach (explode('|', $group) as $entry) {
                    if (! preg_match('/^\s*([a-z0-9][a-z0-9+.-]*)(?::[a-z0-9-]+)?(?:\s+\([^\r\n]*\))?\s*$/D', $entry, $match)) {
                        return $unknown + ['reason' => 'unsupported_dependency_syntax'];
                    }
                    $alternatives[] = $match[1];
                }
                $groups[] = ['kind' => $kind, 'declaration' => $group, 'package_alternatives' => $alternatives,
                    'version_constraints' => 'not_evaluated'];
            }
        }
        usort($owners, fn ($a, $b) => strcmp($a['path_sha256'], $b['path_sha256']));

        return ['status' => 'verified_installed_owner', 'basis' => $unknown['basis'], 'paths' => $owners,
            'package' => $fields[0], 'version' => $fields[2], 'metadata_sha256' => hash('sha256', $metadata['stdout']),
            'dependencies' => ['status' => 'declared_metadata_only', 'groups' => $groups,
                'transitive_analysis' => 'not_performed', 'virtual_providers' => 'not_resolved'],
            'execution_authorized' => false];
    }

    private function query(array $arguments): array
    {
        if ($this->packageQuery !== null) {
            $result = ($this->packageQuery)($arguments);
        } else {
            if (! $this->protectedPath('/usr/bin/dpkg-query') || ! $this->protectedPath('/var/lib/dpkg')) {
                return ['complete' => false, 'stdout' => ''];
            }
            $process = new Process(array_merge(['/usr/bin/dpkg-query', '--admindir=/var/lib/dpkg'], $arguments), env: ['LC_ALL' => 'C'], timeout: 2);
            $bytes = 0;
            try {
                $process->run(function ($type, $chunk) use (&$bytes, $process) {
                    $bytes += strlen($chunk);
                    if ($bytes > 32768) {
                        $process->stop(0);
                    }
                });
                $result = ['exit_code' => $process->getExitCode(), 'stdout' => $process->getOutput(),
                    'truncated' => $bytes > 32768];
            } catch (\Throwable) {
                return ['complete' => false, 'stdout' => ''];
            }
        }
        $stdout = $result['stdout'] ?? '';

        return ['complete' => ($result['exit_code'] ?? null) === 0 && is_string($stdout) && strlen($stdout) <= 32768
            && ! ($result['truncated'] ?? false), 'stdout' => is_string($stdout) && strlen($stdout) <= 32768 ? $stdout : ''];
    }

    private function licenseFields(string $text): array
    {
        $labels = [];
        $copyright = [];
        $complete = true;
        foreach (explode("\n", $text) as $line) {
            if (! preg_match('/^(License|Copyright):\s*(.*)$/i', $line, $match)) {
                continue;
            }
            if (strlen($match[2]) > 500 || count($labels) + count($copyright) >= 64) {
                $complete = false;

                continue;
            }
            if (strtolower($match[1]) === 'license') {
                $labels[] = $match[2];
            } else {
                $copyright[] = $match[2];
            }
        }

        return ['declared_labels' => array_values(array_unique($labels)),
            'copyright_statements' => array_values(array_unique($copyright)),
            'extraction_complete' => $complete, 'format' => 'bounded_copyright_field_lines_not_legal_interpretation'];
    }

    private function sameFile(array $left, array|false $right): bool
    {
        if ($right === false) {
            return false;
        }
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $key) {
            if ($left[$key] !== $right[$key]) {
                return false;
            }
        }

        return true;
    }

    private function protectedPath(string $path): bool
    {
        do {
            $stat = @lstat($path);
            if ($stat === false || $stat['uid'] !== 0 || (! is_link($path) && ($stat['mode'] & 0022) !== 0)) {
                return false;
            }
            $parent = dirname($path);
            if ($parent === $path) {
                return true;
            }
            $path = $parent;
        } while (true);
    }

    private function plainText(string $text, bool $roff): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)/', '', $text) ?? '';
        $text = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $text) ?? '';
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|[\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', '', $text) ?? '';
        if ($roff) {
            $lines = [];
            foreach (explode("\n", $text) as $line) {
                if (preg_match('/^[.\x27](SH|SS|B|I|BR|IR|BI|RB|RI|IB)\s+(.*)$/', $line, $match)) {
                    $line = trim($match[2], '"');
                } elseif (preg_match('/^[.\x27]/', $line)) {
                    continue;
                }
                $line = str_replace(['\\-', '\\&', '\\e'], ['-', '', '\\'], $line);
                $line = preg_replace('/\\\\f(?:\[[^\]]*\]|\([^ ]{2}|[A-Za-z0-9])/', '', $line) ?? '';
                $lines[] = $line;
            }
            $text = implode("\n", $lines);
        }

        return trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? '');
    }
}
