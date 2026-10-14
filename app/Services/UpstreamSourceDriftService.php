<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

class UpstreamSourceDriftService
{
    public const PREPARATION_STATE_KEY = 'upstream_source_preparation_state';

    private ?string $preparationMetadataProfile = null;

    /** @return array<string,mixed> */
    public function prepareScheduled(array $sources, array $policy): array
    {
        $previous = $this->preparationMetadataProfile;
        $profile = $policy['metadata_network_profile'] ?? null;
        if ($profile !== null && (! is_string($profile)
            || config('offline_policy.profiles.'.$profile.'.task_metadata_get.task') !== 'whiteroom_monitor_due_report')) {
            throw new \RuntimeException('Upstream preparation metadata network profile is unqualified.');
        }
        $this->preparationMetadataProfile = $profile;
        try {
            return $this->prepareScheduledWithinScope($sources, $policy);
        } finally {
            $this->preparationMetadataProfile = $previous;
        }
    }

    /** @return array<string,mixed> */
    private function prepareScheduledWithinScope(array $sources, array $policy): array
    {
        if (! $policy['enabled']) {
            return ['schema' => 'plos.upstream_preparation.v1', 'status' => 'disabled', 'operator_actionable' => false];
        }
        $sourceMap = [];
        $unsupported = [];
        foreach ($sources as $source) {
            $identity = [
                'registry' => (string) ($source['registry'] ?? ''), 'key' => (string) ($source['key'] ?? ''),
                'url' => (string) ($source['source_url'] ?? $source['repo'] ?? ''),
                'identifier_scheme' => (string) ($source['identifier_scheme'] ?? ''),
                'identifier' => (string) ($source['identifier'] ?? ''),
                'recorded_revision' => (string) ($source['source_version'] ?? ''),
            ];
            $id = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
            if (! $this->supports($source) || $identity['registry'] === '' || $identity['key'] === '' || $identity['url'] === '' || $identity['recorded_revision'] === '') {
                $unsupported[$id] = $identity + ['reason_code' => ! $this->supports($source) ? 'unsupported_official_metadata_probe' : 'recorded_source_identity_incomplete'];

                continue;
            }
            $sourceMap[$id] = ['source' => $source, 'identity' => $identity];
        }
        if (count($sourceMap) + count($unsupported) > $policy['max_sources']) {
            throw new \RuntimeException('Upstream preparation source inventory exceeds its table-owned bound.');
        }
        ksort($sourceMap);
        $started = microtime(true);

        return DB::transaction(function () use ($sourceMap, $unsupported, $policy, $started): array {
            $stateRow = $this->preparationRow(self::PREPARATION_STATE_KEY)->lockForUpdate()->first();
            if ($stateRow === null) {
                throw new \RuntimeException('Upstream preparation cursor is unavailable.');
            }
            $state = json_decode($stateRow->config_value, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($state) || ($state['schema'] ?? null) !== 'plos.upstream_preparation_state.v1'
                || ! array_key_exists('cursor', $state) || ! ($state['cursor'] === null || (is_string($state['cursor']) && preg_match('/^[a-f0-9]{64}$/', $state['cursor'])))) {
                throw new \RuntimeException('Upstream preparation cursor is malformed.');
            }
            $now = CarbonImmutable::now('UTC');
            $receipts = [];
            foreach (array_chunk(array_keys($sourceMap), 500) as $ids) {
                $rows = DB::table('system_configs')->where('section', 'ops')
                    ->whereIn('config_key', array_map(fn ($id) => 'upstream_source_check_'.$id, $ids))->get();
                foreach ($rows as $row) {
                    $receipts[substr($row->config_key, strlen('upstream_source_check_'))] = json_decode($row->config_value, true, 512, JSON_THROW_ON_ERROR);
                }
            }
            $due = array_filter($sourceMap, function ($entry, $id) use ($receipts, $now): bool {
                $receipt = $receipts[$id] ?? null;
                if ($receipt === null) {
                    return true;
                }
                if (! is_array($receipt) || ($receipt['schema'] ?? null) !== 'plos.upstream_source_check.v1' || ! is_string($receipt['next_check_at'] ?? null)
                    || ! is_array($receipt['source'] ?? null)
                    || count($receipt['source']) !== count($entry['identity'])
                    || array_diff_assoc($receipt['source'], $entry['identity']) !== []) {
                    throw new \RuntimeException('Upstream preparation check receipt is malformed.');
                }

                return CarbonImmutable::parse($receipt['next_check_at'])->lte($now);
            }, ARRAY_FILTER_USE_BOTH);
            $focused = [];
            foreach ($policy['priority_project_keys'] as $key) {
                foreach ($due as $id => $entry) {
                    if ($entry['identity']['key'] === $key) {
                        $focused[$id] = $entry;
                        break;
                    }
                }
            }
            // Reserve a cursor slot even when every priority watch is due or failing.
            $focused = array_slice($focused, 0, max(0, $policy['limit'] - 1), true);
            $rotating = array_diff_key($due, $focused);
            $ids = array_keys($rotating);
            usort($ids, fn ($left, $right) => [$left <= ($state['cursor'] ?? '') ? 1 : 0, $left] <=> [$right <= ($state['cursor'] ?? '') ? 1 : 0, $right]);
            $cursorIds = array_slice($ids, 0, $policy['limit'] - count($focused));
            $selected = $focused;
            foreach ($cursorIds as $id) {
                $selected[$id] = $rotating[$id];
            }
            $checks = [];
            $created = 0;
            $workRefs = [];
            $rateLimited = [];
            $remainingCore = count($selected);
            foreach ($selected as $id => $entry) {
                $remainingCore--;
                if (microtime(true) - $started + $policy['request_timeout_seconds'] > $policy['budget_seconds']) {
                    break;
                }
                $source = $entry['source'];
                $scheme = strtolower((string) ($source['identifier_scheme'] ?? ''));
                $result = isset($rateLimited[$scheme])
                    ? $this->baseResult($source) + ['state' => 'skipped_rate_limited', 'reason_code' => 'provider_rate_limit_already_reached']
                    : $this->inspect([$source], $policy['connect_timeout_seconds'], $policy['request_timeout_seconds'], $policy['response_max_bytes'])[0];
                if (($result['state'] ?? '') === 'rate_limited') {
                    $rateLimited[$scheme] = true;
                }
                $revisionConclusive = in_array($result['state'] ?? '', ['current', 'drift'], true);
                $provenance = $revisionConclusive && $result['state'] === 'drift'
                    ? $this->preparationProvenance($source, $result, $policy, $started, $remainingCore)
                    : [];
                $provenanceComplete = ! array_filter(['license', 'releases/latest'], fn ($endpoint) => isset($provenance[$endpoint])
                    && ! in_array($provenance[$endpoint]['status'] ?? '', ['metadata_observed', 'metadata_absent'], true));
                $provenanceComplete = $provenanceComplete && ! ($provenance['provider_rate_limited'] ?? false);
                if ($provenance['provider_rate_limited'] ?? false) {
                    $rateLimited[$scheme] = true;
                }
                // Reserve half of the existing TEXT capacity for source/ownership/lifecycle fields.
                // Reject the selected external evidence as a whole; never truncate it into valid facts.
                if (strlen(json_encode(['result' => $result, 'provenance' => $provenance], JSON_THROW_ON_ERROR)) > intdiv(65535, 2)) {
                    $provenanceComplete = false;
                    $provenance = ['status' => 'receipt_storage_hold', 'reason_code' => 'metadata_receipt_storage_limit'];
                    unset($result['model_card_license_claim']);
                    $result['reason_code'] = 'metadata_receipt_storage_limit';
                }
                $conclusive = $revisionConclusive && $provenanceComplete;
                $check = ['schema' => 'plos.upstream_source_check.v1', 'source' => $entry['identity'],
                    'checked_at' => $now->toIso8601String(), 'conclusive' => $conclusive,
                    'revision_conclusive' => $revisionConclusive, 'provenance_complete' => $provenanceComplete,
                    'next_check_at' => ($conclusive ? $now->addDays(max(1, (int) ($source['monitor_cadence_days'] ?? 30))) : $now->addSeconds($policy['retry_after_seconds']))->toIso8601String(),
                    'result' => $result, 'provenance' => $provenance];
                $check['last_conclusive'] = $conclusive
                    ? ['checked_at' => $check['checked_at'], 'observed_version' => $result['observed_version'],
                        'observed_updated_at' => $result['observed_updated_at'] ?? null, 'response_sha256' => $result['response_sha256'] ?? null]
                    : ($receipts[$id]['last_conclusive'] ?? null);
                $this->savePreparationValue('upstream_source_check_'.$id, $check);
                if ($revisionConclusive && $result['state'] === 'drift') {
                    $workId = hash('sha256', $id.'|'.$result['observed_version'].'|'.($result['observed_updated_at'] ?? ''));
                    $work = ['schema' => 'plos.upstream_source_preparation.v1', 'source' => $entry['identity'],
                        'observed_version' => $result['observed_version'], 'observed_updated_at' => $result['observed_updated_at'] ?? null,
                        'metadata_checked_at' => $check['checked_at'], 'check_key' => 'ops.upstream_source_check_'.$id,
                        'provenance' => $provenance, 'registry_license_claim' => $source['license'] ?? null,
                        'registry_legal_basis_claim' => $source['legal_basis'] ?? null, 'registry_next_action' => $source['next_action'] ?? null,
                        'owner' => $policy['owner'], 'next_action' => $policy['next_action'], 'status' => 'preparation_pending',
                        'integration_owner' => $policy['integration_owner'], 'deployment_owner' => $policy['deployment_owner'],
                        'owning_record' => $policy['owning_record'], 'last_reviewed_at' => null,
                        'operator_actionable' => false, 'pushover_allowed' => false,
                        'lifecycle' => ['source_check' => 'drift_confirmed', 'metadata_provenance' => $provenanceComplete ? 'observed' : 'incomplete',
                            'benefit' => 'unverified', 'license_qualification' => 'unverified',
                            'tests' => 'unverified', 'integration' => 'unverified', 'deployment' => 'unverified', 'closure' => 'pending'],
                        'public_approval' => ['status' => 'not_requested', 'exact_target' => null, 'exact_content' => null]];
                    $workKey = 'upstream_source_work_'.$workId;
                    // Creation-only: never revive closed/deferred work or replace its owner/next action.
                    $created += DB::table('system_configs')->insertOrIgnore(['section' => 'ops', 'config_key' => $workKey,
                        'config_value' => $this->encodedPreparationValue($work), 'data_type' => 'json',
                        'description' => 'Backend upstream preparation; not an operator decision.', 'created_at' => now(), 'updated_at' => now()]);
                }
                if (isset($workKey) && $revisionConclusive && $result['state'] === 'drift') {
                    $existingWork = $this->preparationRow($workKey)->first();
                    $existing = json_decode($existingWork->config_value, true, 512, JSON_THROW_ON_ERROR);
                    $workRefs[] = ['config_key' => 'ops.'.$workKey, 'owner' => $existing['owner'] ?? 'unverified',
                        'next_action' => $existing['next_action'] ?? 'Inspect unfinished backend work.', 'status' => $existing['status'] ?? 'unverified'];
                }
                $checks[$id] = $check;
                if (in_array($id, $cursorIds, true)) {
                    $state['cursor'] = $id;
                }
            }
            $latest = array_replace($receipts, $checks);
            $fresh = 0;
            $retryHeld = 0;
            $neverChecked = 0;
            foreach ($sourceMap as $id => $_entry) {
                $receipt = $latest[$id] ?? null;
                if ($receipt === null) {
                    $neverChecked++;
                } elseif (($receipt['conclusive'] ?? false) && CarbonImmutable::parse($receipt['next_check_at'])->gt($now)) {
                    $fresh++;
                } elseif (! ($receipt['conclusive'] ?? false) && CarbonImmutable::parse($receipt['next_check_at'])->gt($now)) {
                    $retryHeld++;
                }
            }
            $inconclusive = count(array_filter($checks, fn ($check) => ! $check['conclusive']));
            $coverage = ['eligible' => count($sourceMap), 'unsupported' => count($unsupported), 'due' => count($due),
                'checked' => count($checks), 'omitted_by_limit_or_deadline' => count($due) - count($checks),
                'inconclusive' => $inconclusive, 'fresh_after' => $fresh, 'overdue_or_inconclusive_after' => count($sourceMap) - $fresh,
                'never_checked_after' => $neverChecked, 'retry_held_after' => $retryHeld, 'complete' => count($due) === count($checks) && $inconclusive === 0 && $unsupported === []];
            $state['last_run'] = ['checked_at' => $now->toIso8601String(), 'coverage' => $coverage, 'unsupported_refs' => $unsupported];
            $this->savePreparationValue(self::PREPARATION_STATE_KEY, $state);

            return ['schema' => 'plos.upstream_preparation.v1', 'status' => $coverage['complete'] ? 'complete' : 'partial',
                'coverage' => $coverage, 'new_backend_work' => $created, 'work_refs' => $workRefs, 'checks' => $checks,
                'operator_actionable' => false, 'pushover_allowed' => false, 'public_writes' => false, 'preparation_complete' => false,
                'owner' => $policy['owner'], 'next_action' => $policy['next_action']];
        });
    }

    public function supports(array $source): bool
    {
        return match (strtolower((string) ($source['identifier_scheme'] ?? ''))) {
            'git_commit' => $this->githubRepository($source) !== null,
            'hf_model_revision' => $this->huggingFaceModel($source) !== null,
            'arxiv' => preg_match('/^\d{4}\.\d{4,5}(?:v\d+)?$/', (string) ($source['identifier'] ?? '')) === 1,
            default => false,
        };
    }

    private function preparationRow(string $key): Builder
    {
        return DB::table('system_configs')->where('section', 'ops')->where('config_key', $key);
    }

    private function encodedPreparationValue(array $value): string
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        // The existing system_configs.config_value is MySQL TEXT (65,535 bytes).
        if (strlen($encoded) > 65535) {
            throw new \RuntimeException('Upstream preparation receipt exceeds existing storage capacity.');
        }

        return $encoded;
    }

    private function savePreparationValue(string $key, array $value): void
    {
        DB::table('system_configs')->updateOrInsert(['section' => 'ops', 'config_key' => $key], [
            'config_value' => $this->encodedPreparationValue($value), 'data_type' => 'json',
            'description' => 'Durable backend upstream metadata receipt.', 'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function preparationProvenance(array $source, array $result, array $policy, float $started, int $remainingCore): array
    {
        $repository = $this->githubRepository($source);
        if ($repository === null) {
            return ['official_url' => $source['source_url'] ?? $source['repo'] ?? null,
                'observed_revision' => $result['observed_version'], 'model_card_license_claim' => $result['model_card_license_claim'] ?? null,
                'legal_qualification' => 'unverified', 'release_or_model_activation' => 'unverified'];
        }
        $facts = ['official_url' => 'https://github.com/'.$repository, 'observed_revision' => $result['observed_version'], 'legal_qualification' => 'unverified'];
        foreach (['license' => ['ref' => $result['observed_version']], 'releases/latest' => []] as $endpoint => $query) {
            $url = 'https://api.github.com/repos/'.$repository.'/'.$endpoint;
            if (microtime(true) - $started + ($remainingCore + 1) * $policy['request_timeout_seconds'] > $policy['budget_seconds']) {
                $facts[$endpoint] = ['url' => $url, 'query' => $query, 'status' => 'deferred_request_budget'];

                continue;
            }
            try {
                $response = $this->boundedMetadataGet($this->githubRequest($policy['connect_timeout_seconds'], $policy['request_timeout_seconds']), $url, $query, $policy['response_max_bytes'], $policy['request_timeout_seconds']);
                $json = $response->json();
                $facts[$endpoint] = ['url' => $url, 'query' => $query, 'http_status' => $response->status(),
                    'response_sha256' => hash('sha256', $response->body()),
                    'status' => $response->successful() && is_array($json) ? 'metadata_observed' : ($response->status() === 404 ? 'metadata_absent' : 'unverified')];
                if ($response->successful() && is_array($json)) {
                    $facts[$endpoint] += $endpoint === 'license'
                        ? ['file_sha' => $json['sha'] ?? null, 'spdx_claim' => $json['license']['spdx_id'] ?? null]
                        : ['tag_name' => $json['tag_name'] ?? null, 'published_at' => $json['published_at'] ?? null,
                            'release_url' => $json['html_url'] ?? null, 'target_commitish' => $json['target_commitish'] ?? null, 'release_revision_qualified' => false];
                }
                if (in_array($response->status(), [403, 429], true)) {
                    $facts['provider_rate_limited'] = true;
                    break;
                }
            } catch (\Throwable $exception) {
                $facts[$endpoint] = ['url' => $url, 'query' => $query, 'status' => 'request_failed', 'reason_code' => $this->metadataFailureReason($exception, 'metadata_request_failed')];
            }
        }

        return $facts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    public function inspect(array $sources, int $connectTimeout, int $timeout, int $maxBytes): array
    {
        $results = [];
        $arxivSources = [];
        $githubRateLimited = false;

        foreach ($sources as $source) {
            $key = (string) ($source['key'] ?? 'unknown');
            $scheme = strtolower((string) ($source['identifier_scheme'] ?? ''));

            if ($scheme === 'hf_model_revision') {
                $results[$key] = $this->inspectHuggingFaceModel($source, $connectTimeout, $timeout, $maxBytes);

                continue;
            }

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

                $results[$key] = $this->inspectGithub($source, $connectTimeout, $timeout, $maxBytes);
                $githubRateLimited = $results[$key]['state'] === 'rate_limited';

                continue;
            }

            $results[$key] = $this->baseResult($source) + [
                'state' => 'unsupported',
                'reason_code' => 'source_has_no_supported_version_probe',
            ];
        }

        foreach ($this->inspectArxiv($arxivSources, $connectTimeout, $timeout, $maxBytes) as $key => $result) {
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
    private function inspectGithub(array $source, int $connectTimeout, int $timeout, int $maxBytes): array
    {
        $repository = $this->githubRepository($source);
        if ($repository === null) {
            return $this->baseResult($source) + [
                'state' => 'unsupported',
                'reason_code' => 'github_repository_url_invalid',
            ];
        }

        try {
            $response = $this->boundedMetadataGet($this->githubRequest($connectTimeout, $timeout), "https://api.github.com/repos/{$repository}/commits", ['per_page' => 1], $maxBytes, $timeout);
        } catch (\Throwable $exception) {
            return $this->baseResult($source) + [
                'state' => 'unavailable',
                'reason_code' => $this->metadataFailureReason($exception, 'github_request_failed'),
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

        return $this->comparisonResult($source, $version, $updatedAt) + [
            'official_metadata_url' => 'https://api.github.com/repos/'.$repository.'/commits',
            'response_sha256' => hash('sha256', $response->body()),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<string, array<string, mixed>>
     */
    private function inspectArxiv(array $sources, int $connectTimeout, int $timeout, int $maxBytes): array
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
                ->withoutRedirecting()
                ->timeout($timeout)
                ->withHeaders([
                    'Accept' => 'application/atom+xml',
                    'User-Agent' => (string) config('whiteroom_projects.source_drift.user_agent'),
                ]);
            $response = $this->boundedMetadataGet($response, 'https://export.arxiv.org/api/query', [
                'id_list' => implode(',', $ids), 'max_results' => count($ids),
            ], $maxBytes, $timeout);
        } catch (\Throwable $exception) {
            return $this->unavailableArxivResults($sources, $this->metadataFailureReason($exception, 'arxiv_request_failed'));
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
                : $this->comparisonResult($source, $entry['version'], $entry['updated_at']) + [
                    'official_metadata_url' => 'https://export.arxiv.org/api/query', 'response_sha256' => hash('sha256', $response->body()),
                ];
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

    private function huggingFaceModel(array $source): ?string
    {
        $parts = parse_url((string) ($source['source_url'] ?? $source['repo'] ?? ''));
        $identifier = (string) ($source['identifier'] ?? '');
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'huggingface.co'
            || ! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $identifier)
            || trim((string) ($parts['path'] ?? ''), '/') !== $identifier) {
            return null;
        }

        return $identifier;
    }

    private function inspectHuggingFaceModel(array $source, int $connectTimeout, int $timeout, int $maxBytes): array
    {
        $model = $this->huggingFaceModel($source);
        if ($model === null) {
            return $this->baseResult($source) + ['state' => 'unsupported', 'reason_code' => 'hf_model_identity_invalid'];
        }
        $url = 'https://huggingface.co/api/models/'.$model;
        try {
            $response = Http::connectTimeout($connectTimeout)->withoutRedirecting()->timeout($timeout)
                ->withHeaders(['Accept' => 'application/json', 'User-Agent' => (string) config('whiteroom_projects.source_drift.user_agent')]);
            $response = $this->boundedMetadataGet($response, $url, ['expand' => ['sha', 'lastModified', 'cardData']], $maxBytes, $timeout);
        } catch (\Throwable $exception) {
            return $this->baseResult($source) + ['state' => 'unavailable', 'reason_code' => $this->metadataFailureReason($exception, 'hf_metadata_request_failed')];
        }
        if (! $response->successful()) {
            return $this->baseResult($source) + ['state' => in_array($response->status(), [403, 429], true) ? 'rate_limited' : 'unavailable',
                'reason_code' => 'hf_metadata_http_error', 'http_status' => $response->status()];
        }
        $payload = $response->json();
        $revision = is_array($payload) ? ($payload['sha'] ?? null) : null;
        if (! is_string($revision) || ! preg_match('/^[a-f0-9]{40}$/i', $revision)
            || ($payload['id'] ?? null) !== $model) {
            return $this->baseResult($source) + ['state' => 'invalid_response', 'reason_code' => 'hf_model_revision_invalid'];
        }

        return $this->comparisonResult($source, strtolower($revision), $this->dateOnly($payload['lastModified'] ?? null)) + [
            'official_metadata_url' => $url, 'response_sha256' => hash('sha256', $response->body()),
            'model_card_license_claim' => $payload['cardData']['license'] ?? null,
            'license_qualification' => 'unverified', 'weights_downloaded' => false, 'model_activation' => false,
        ];
    }

    private function boundedMetadataGet(PendingRequest $request, string $url, array $query, int $maxBytes, int $timeout): \Illuminate\Http\Client\Response
    {
        if ($maxBytes < 1 || $maxBytes > 1048576) {
            throw new \RuntimeException('metadata_response_limit_invalid');
        }
        try {
            $policy = app(OfflinePolicyService::class);
            $allowed = $this->preparationMetadataProfile === null
                ? $policy->evaluateRemoteDomain((string) parse_url($url, PHP_URL_HOST))->allowed
                : $policy->evaluateTaskMetadataGet('whiteroom_monitor_due_report', $url, $query, $this->preparationMetadataProfile)->allowed;
            if (! $policy->isInternetAccessEnabled() || ! $allowed) {
                throw new \RuntimeException('metadata_network_policy_refused');
            }
        } catch (\Throwable $exception) {
            throw new \RuntimeException($exception->getMessage() === 'metadata_network_policy_refused' ? 'metadata_network_policy_refused' : 'metadata_network_policy_unavailable', 0, $exception);
        }
        $failure = null;
        $started = microtime(true);
        $sink = Utils::streamFor(fopen('php://temp/maxmemory:65536', 'w+'));
        $received = 0;
        $boundedSink = FnStream::decorate($sink, ['write' => static function (string $bytes) use ($sink, &$received, &$failure, $maxBytes): int {
            if ($received + strlen($bytes) > $maxBytes) {
                $failure = 'metadata_response_body_byte_limit';
                throw new \RuntimeException($failure);
            }
            $received += strlen($bytes);

            return $sink->write($bytes);
        }]);
        $headers = static function (ResponseInterface $response) use ($maxBytes, &$failure): void {
            $length = $response->getHeaderLine('Content-Length');
            if (strlen(json_encode($response->getHeaders(), JSON_THROW_ON_ERROR)) > 16384) {
                $failure = 'metadata_response_header_byte_limit';
            } elseif ($length !== '' && (! ctype_digit($length) || (int) $length > $maxBytes)) {
                $failure = 'metadata_response_content_length_limit';
            } elseif (! in_array(strtolower(trim($response->getHeaderLine('Content-Encoding'))), ['', 'identity'], true)) {
                $failure = 'metadata_response_encoding_unqualified';
            }
            if ($failure !== null) {
                throw new \RuntimeException($failure);
            }
        };
        $progress = static function ($total, $downloaded) use ($maxBytes, &$failure): void {
            if ($total > $maxBytes || $downloaded > $maxBytes) {
                $failure = 'metadata_response_body_byte_limit';
                throw new \RuntimeException($failure);
            }
        };
        $bodyStream = null;
        try {
            $response = $request->withHeaders(['Accept-Encoding' => 'identity'])->withOptions([
                'stream' => true, 'sink' => $boundedSink, 'decode_content' => false, 'allow_redirects' => false,
                'on_headers' => $headers, 'progress' => $progress, 'read_timeout' => $timeout,
            ])->get($url, $query);
            $psr = $response->toPsrResponse();
            // Fakes and alternate existing handlers may not invoke transport callbacks.
            $headers($psr);
            $bodyStream = $psr->getBody();
            if ($bodyStream->isSeekable()) {
                $bodyStream->rewind();
            }
            $body = '';
            while (! $bodyStream->eof()) {
                if (microtime(true) - $started > $timeout) {
                    throw new \RuntimeException('metadata_response_deadline');
                }
                $chunk = $bodyStream->read(min(8192, $maxBytes - strlen($body) + 1));
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    throw new \RuntimeException('metadata_response_body_byte_limit');
                }
                if ($chunk === '' && ! $bodyStream->eof()) {
                    throw new \RuntimeException('metadata_response_stream_stalled');
                }
                $body .= $chunk;
            }

            return new \Illuminate\Http\Client\Response(new Response($psr->getStatusCode(), $psr->getHeaders(), $body));
        } catch (\Throwable $exception) {
            if ($failure !== null) {
                throw new \RuntimeException($failure, 0, $exception);
            }
            throw $exception;
        } finally {
            $bodyStream?->close();
            $boundedSink->close();
        }
    }

    private function metadataFailureReason(\Throwable $exception, string $fallback): string
    {
        $allowed = ['metadata_response_limit_invalid', 'metadata_response_header_byte_limit', 'metadata_response_content_length_limit',
            'metadata_response_encoding_unqualified', 'metadata_response_body_byte_limit', 'metadata_response_deadline',
            'metadata_response_stream_stalled', 'metadata_network_policy_refused', 'metadata_network_policy_unavailable'];
        do {
            if (in_array($exception->getMessage(), $allowed, true)) {
                return $exception->getMessage();
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return $fallback;
    }

    private function githubRequest(int $connectTimeout, int $timeout): PendingRequest
    {
        $request = Http::connectTimeout($connectTimeout)
            ->withoutRedirecting()
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
