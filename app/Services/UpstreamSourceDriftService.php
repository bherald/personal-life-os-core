<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class UpstreamSourceDriftService
{
    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    public function inspect(array $sources, int $connectTimeout, int $timeout): array
    {
        $results = [];
        $arxivSources = [];
        $githubRateLimited = false;

        foreach ($sources as $source) {
            $key = (string) ($source['key'] ?? 'unknown');
            $scheme = strtolower((string) ($source['identifier_scheme'] ?? ''));

            if ($scheme === 'arxiv') {
                $arxivSources[] = $source;

                continue;
            }

            if ($scheme === 'git_commit' && $this->githubRepository($source) !== null) {
                if ($githubRateLimited) {
                    $results[$key] = $this->baseResult($source) + [
                        'state' => 'skipped_rate_limited',
                        'reason_code' => 'github_rate_limit_already_reached',
                    ];

                    continue;
                }

                $results[$key] = $this->inspectGithub($source, $connectTimeout, $timeout);
                $githubRateLimited = $results[$key]['state'] === 'rate_limited';

                continue;
            }

            $results[$key] = $this->baseResult($source) + [
                'state' => 'unsupported',
                'reason_code' => 'source_has_no_supported_version_probe',
            ];
        }

        foreach ($this->inspectArxiv($arxivSources, $connectTimeout, $timeout) as $key => $result) {
            $results[$key] = $result;
        }

        return array_values(array_map(
            fn (array $source): array => $results[(string) ($source['key'] ?? 'unknown')],
            $sources
        ));
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function inspectGithub(array $source, int $connectTimeout, int $timeout): array
    {
        $repository = $this->githubRepository($source);
        if ($repository === null) {
            return $this->baseResult($source) + [
                'state' => 'unsupported',
                'reason_code' => 'github_repository_url_invalid',
            ];
        }

        try {
            $response = $this->githubRequest($connectTimeout, $timeout)
                ->get("https://api.github.com/repos/{$repository}/commits", ['per_page' => 1]);
        } catch (\Throwable) {
            return $this->baseResult($source) + [
                'state' => 'unavailable',
                'reason_code' => 'github_request_failed',
            ];
        }

        if (in_array($response->status(), [403, 429], true)) {
            return $this->baseResult($source) + [
                'state' => 'rate_limited',
                'reason_code' => 'github_rate_limited',
                'http_status' => $response->status(),
                'rate_limit_remaining' => $this->nullableInteger($response->header('X-RateLimit-Remaining')),
                'retry_after_seconds' => $this->nullableInteger($response->header('Retry-After')),
            ];
        }

        if (! $response->successful()) {
            return $this->baseResult($source) + [
                'state' => 'unavailable',
                'reason_code' => $response->status() === 404 ? 'github_repository_not_found' : 'github_http_error',
                'http_status' => $response->status(),
            ];
        }

        $payload = $response->json();
        $commit = is_array($payload) && is_array($payload[0] ?? null) ? $payload[0] : [];
        $version = strtolower(trim((string) ($commit['sha'] ?? '')));
        if (! preg_match('/^[a-f0-9]{40}$/', $version)) {
            return $this->baseResult($source) + [
                'state' => 'invalid_response',
                'reason_code' => 'github_commit_sha_missing',
            ];
        }

        $updatedAt = $this->dateOnly(
            $commit['commit']['committer']['date']
                ?? $commit['commit']['author']['date']
                ?? null
        );

        return $this->comparisonResult($source, $version, $updatedAt);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<string, array<string, mixed>>
     */
    private function inspectArxiv(array $sources, int $connectTimeout, int $timeout): array
    {
        if ($sources === []) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn (array $source): string => trim((string) ($source['identifier'] ?? '')),
            $sources
        ))));

        try {
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->withHeaders([
                    'Accept' => 'application/atom+xml',
                    'User-Agent' => (string) config('whiteroom_projects.source_drift.user_agent'),
                ])
                ->get('https://export.arxiv.org/api/query', [
                    'id_list' => implode(',', $ids),
                    'max_results' => count($ids),
                ]);
        } catch (\Throwable) {
            return $this->unavailableArxivResults($sources, 'arxiv_request_failed');
        }

        if (! $response->successful()) {
            return $this->unavailableArxivResults($sources, 'arxiv_http_error', $response->status());
        }

        $observed = $this->parseArxivEntries($response->body());
        if ($observed === null) {
            return $this->unavailableArxivResults($sources, 'arxiv_atom_invalid');
        }

        $results = [];
        foreach ($sources as $source) {
            $key = (string) ($source['key'] ?? 'unknown');
            $identifier = trim((string) ($source['identifier'] ?? ''));
            $entry = $observed[$identifier] ?? null;

            $results[$key] = $entry === null
                ? $this->baseResult($source) + [
                    'state' => 'unavailable',
                    'reason_code' => 'arxiv_entry_missing',
                ]
                : $this->comparisonResult($source, $entry['version'], $entry['updated_at']);
        }

        return $results;
    }

    /**
     * @return array<string, array{version:string,updated_at:string|null}>|null
     */
    private function parseArxivEntries(string $body): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof \SimpleXMLElement) {
            return null;
        }

        $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
        $entries = $xml->xpath('//atom:entry');
        if (! is_array($entries)) {
            return null;
        }

        $observed = [];
        foreach ($entries as $entry) {
            $entry->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
            $idNodes = $entry->xpath('./atom:id');
            $updatedNodes = $entry->xpath('./atom:updated');
            $entryId = trim((string) ($idNodes[0] ?? ''));

            if (! preg_match('#/abs/(.+?)(v\d+)$#', $entryId, $matches)) {
                continue;
            }

            $observed[$matches[1]] = [
                'version' => $matches[2],
                'updated_at' => $this->dateOnly((string) ($updatedNodes[0] ?? '')),
            ];
        }

        return $observed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<string, array<string, mixed>>
     */
    private function unavailableArxivResults(array $sources, string $reasonCode, ?int $httpStatus = null): array
    {
        $results = [];
        foreach ($sources as $source) {
            $result = $this->baseResult($source) + [
                'state' => 'unavailable',
                'reason_code' => $reasonCode,
            ];
            if ($httpStatus !== null) {
                $result['http_status'] = $httpStatus;
            }

            $results[(string) ($source['key'] ?? 'unknown')] = $result;
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function comparisonResult(array $source, string $observedVersion, ?string $observedUpdatedAt): array
    {
        $recordedVersion = trim((string) ($source['source_version'] ?? $source['identifier'] ?? ''));
        $recordedUpdatedAt = $this->dateOnly($source['source_updated_at'] ?? null);
        $driftFields = [];

        if ($recordedVersion === '' || ! hash_equals(strtolower($recordedVersion), strtolower($observedVersion))) {
            $driftFields[] = 'source_version';
        }
        if ($recordedUpdatedAt !== null && $observedUpdatedAt !== null && $recordedUpdatedAt !== $observedUpdatedAt) {
            $driftFields[] = 'source_updated_at';
        }

        return $this->baseResult($source) + [
            'state' => $driftFields === [] ? 'current' : 'drift',
            'reason_code' => $driftFields === [] ? 'recorded_source_is_current' : 'recorded_source_changed',
            'recorded_version' => $recordedVersion !== '' ? $recordedVersion : null,
            'observed_version' => $observedVersion,
            'recorded_updated_at' => $recordedUpdatedAt,
            'observed_updated_at' => $observedUpdatedAt,
            'drift_fields' => $driftFields,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function baseResult(array $source): array
    {
        return [
            'project_key' => (string) ($source['key'] ?? 'unknown'),
            'registry' => (string) ($source['registry'] ?? 'unknown'),
            'source_type' => (string) ($source['source_type'] ?? 'unknown'),
            'identifier_scheme' => (string) ($source['identifier_scheme'] ?? 'none'),
        ];
    }

    /** @param array<string, mixed> $source */
    private function githubRepository(array $source): ?string
    {
        $url = trim((string) ($source['repo'] ?? $source['source_url'] ?? ''));
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['host'] ?? '')) !== 'github.com') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/'))));
        if (count($segments) < 2) {
            return null;
        }

        $owner = $segments[0];
        $repository = preg_replace('/\.git$/i', '', $segments[1]) ?? '';
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $owner) || ! preg_match('/^[A-Za-z0-9_.-]+$/', $repository)) {
            return null;
        }

        return $owner.'/'.$repository;
    }

    private function githubRequest(int $connectTimeout, int $timeout): PendingRequest
    {
        $request = Http::connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => (string) config('whiteroom_projects.source_drift.github_api_version'),
                'User-Agent' => (string) config('whiteroom_projects.source_drift.user_agent'),
            ]);

        $token = trim((string) (getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN') ?: ''));

        return $token === '' ? $request : $request->withToken($token);
    }

    private function dateOnly(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableInteger(?string $value): ?int
    {
        return is_string($value) && preg_match('/^\d+$/', $value) ? (int) $value : null;
    }
}
