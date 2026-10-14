<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Optional, table-controlled TypeSafe System One judgments. */
class TypeSafeJevDecisionService
{
    public const POLICY_KEY = 'ai.typesafe_jev_policy';

    private const PROVIDER_ID = 'typesafe_jev';

    private const HOST = 'api.typesafe.ai';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly OfflinePolicyService $offlinePolicy,
        private readonly CircuitBreaker $circuitBreaker,
    ) {}

    /**
     * Run one bounded typed judgment. All exclusions and provider errors return a successful
     * skip so the caller can continue along the existing PLOS path.
     *
     * @param  array<string,mixed>  $state
     * @param  array<string,array<string,mixed>>  $questions
     * @return array<string,mixed>
     */
    public function evaluate(string $task_type, string $data_classification, array $state, array $questions): array
    {
        $traceId = bin2hex(random_bytes(16));
        $startedAt = microtime(true);
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/', $task_type) !== 1) {
            return $this->skipped($traceId, 'unknown_task', 'invalid_task_type', $startedAt);
        }
        $policy = $this->policy();
        if (! ($policy['enabled'] ?? false)) {
            return $this->skipped($traceId, $task_type, 'feature_disabled', $startedAt);
        }
        if (! is_array($policy['tasks'] ?? null) || ! is_array($policy['allowed_data_classifications'] ?? null)) {
            return $this->skipped($traceId, $task_type, 'task_policy_invalid', $startedAt);
        }
        $taskPolicy = $policy['tasks'][$task_type] ?? null;
        if (! is_array($taskPolicy) || ! ($taskPolicy['enabled'] ?? false)) {
            return $this->skipped($traceId, $task_type, 'task_not_enabled', $startedAt);
        }
        if (! is_array($taskPolicy['allowed_data_classifications'] ?? null)) {
            return $this->skipped($traceId, $task_type, 'task_policy_invalid', $startedAt);
        }
        if (! in_array($data_classification, $policy['allowed_data_classifications'] ?? [], true)
            || ! in_array($data_classification, $taskPolicy['allowed_data_classifications'] ?? [], true)) {
            return $this->skipped($traceId, $task_type, 'data_classification_not_allowed', $startedAt);
        }
        try {
            if ($this->offlinePolicy->isOfflineModeActive()) {
                return $this->skipped($traceId, $task_type, 'offline_mode', $startedAt);
            }
            if (! $this->offlinePolicy->isInternetAccessEnabled()) {
                return $this->skipped($traceId, $task_type, 'internet_access_disabled', $startedAt);
            }
            $decision = $this->offlinePolicy->evaluateRemoteDomain(self::HOST);
            if (! ($decision->allowed ?? false)) {
                return $this->skipped($traceId, $task_type, 'remote_domain_policy_denied', $startedAt);
            }
        } catch (Throwable) {
            return $this->skipped($traceId, $task_type, 'network_policy_unavailable', $startedAt);
        }
        if (! $this->validEndpoint($policy['endpoint'] ?? null)) {
            return $this->skipped($traceId, $task_type, 'provider_endpoint_invalid', $startedAt);
        }

        $model = $policy['model'] ?? null;
        if (! is_string($model) || preg_match('/^jev-[a-zA-Z0-9._-]{1,63}$/', $model) !== 1) {
            return $this->skipped($traceId, $task_type, 'provider_model_invalid', $startedAt);
        }
        if (! $this->validQuestions($questions)) {
            return $this->skipped($traceId, $task_type, 'invalid_question_schema', $startedAt);
        }

        $wireQuestions = $questions;
        foreach ($wireQuestions as &$question) {
            if ($question['type'] === 'choice' && array_is_list($question['criteria'])) {
                // The public bridge accepts label lists; TypeSafe's HTTP API requires a map.
                // Cast to object so numeric labels cannot serialize back into a JSON list.
                $question['criteria'] = (object) array_fill_keys($question['criteria'], null);
            }
        }
        unset($question);
        $payload = ['state' => $state, 'model' => $model, 'questions' => $wireQuestions];
        try {
            $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            return $this->skipped($traceId, $task_type, 'request_not_json_serializable', $startedAt);
        }
        if (strlen($encodedPayload) > $this->boundedInt($policy, 'max_request_bytes', 32768, 1024, 131072)) {
            return $this->skipped($traceId, $task_type, 'request_too_large', $startedAt);
        }

        $exclusions = $policy['payload_exclusions'] ?? null;
        if ($exclusions !== null || $data_classification === 'private_internal') {
            try {
                if ($this->containsExcludedPayload(['state' => $state, 'questions' => $questions], $exclusions)) {
                    return $this->skipped($traceId, $task_type, 'excluded_auth_material', $startedAt);
                }
            } catch (Throwable) {
                return $this->skipped($traceId, $task_type, 'payload_exclusion_policy_invalid', $startedAt);
            }
        }

        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return $this->skipped($traceId, $task_type, 'credential_unavailable', $startedAt);
        }

        $attemptCount = 0;
        try {
            $result = $this->circuitBreaker->call(
                self::PROVIDER_ID,
                function () use ($policy, $apiKey, $payload, $questions, &$attemptCount): array {
                    return $this->requestAndValidate($policy, $apiKey, $payload, $questions, $attemptCount);
                },
                [
                    'failure_threshold' => $this->boundedInt($policy, 'circuit_failure_threshold', 3, 1, 100),
                    'timeout_seconds' => $this->boundedInt($policy, 'circuit_cooldown_seconds', 60, 1, 86400),
                    'success_threshold' => 1,
                ],
            );
        } catch (Throwable $error) {
            $reason = $error instanceof CircuitBreakerException
                ? 'circuit_open'
                : (in_array($error->getMessage(), [
                    'provider_authentication_failed', 'provider_rate_limited', 'provider_http_error', 'invalid_provider_result',
                ], true) ? $error->getMessage() : 'provider_unavailable');
            $attempted = $attemptCount > 0;
            if ($attempted) {
                $this->recordProviderOutcome($traceId, $task_type, $model, false, $reason,
                    $this->durationMs($startedAt), null, null, max(0, $attemptCount - 1));
            }

            return $this->skipped($traceId, $task_type, $reason, $startedAt, $attempted);
        }

        $durationMs = $this->durationMs($startedAt);
        $this->recordProviderOutcome($traceId, $task_type, $result['model'], true, null, $durationMs,
            $result['usage']['input_tokens'], $result['usage']['output_tokens'], max(0, $attemptCount - 1));
        Log::info('TypeSafe Jev typed judgment completed', [
            'route_trace_id' => $traceId,
            'task_type' => $task_type,
            'model' => $result['model'],
            'duration_ms' => $durationMs,
            'input_tokens' => $result['usage']['input_tokens'],
            'output_tokens' => $result['usage']['output_tokens'],
        ]);

        return [
            'success' => true,
            'status' => 'completed',
            'provider' => self::PROVIDER_ID,
            'route_trace_id' => $traceId,
            'task_type' => $task_type,
            'task_schema_version' => (string) ($taskPolicy['schema_version'] ?? 'v1'),
            'model' => $result['model'],
            'answers' => $result['answers'],
            'usage' => $result['usage'],
            'duration_ms' => $durationMs,
        ];
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        try {
            $policy = $this->systemConfig->get(self::POLICY_KEY, []);
            if (is_string($policy)) {
                $policy = json_decode($policy, true, flags: JSON_THROW_ON_ERROR);
            }
        } catch (Throwable) {
            return [];
        }

        return is_array($policy) && ($policy['schema'] ?? null) === 'plos.typesafe_jev_policy.v1' ? $policy : [];
    }

    private function validEndpoint(mixed $endpoint): bool
    {
        if (! is_string($endpoint)) {
            return false;
        }
        $parts = parse_url($endpoint);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === self::HOST
            && ($parts['path'] ?? null) === '/v1/systemone'
            && array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) === []
            && (! isset($parts['port']) || (int) $parts['port'] === 443);
    }

    private function validQuestions(array $questions): bool
    {
        if ($questions === [] || count($questions) > 8) {
            return false;
        }
        foreach ($questions as $id => $question) {
            if (! is_string($id) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $id) !== 1 || ! is_array($question)) {
                return false;
            }
            $type = $question['type'] ?? null;
            $instructions = $question['instructions'] ?? null;
            if (! in_array($type, ['noul', 'choice', 'score'], true)
                || ! (is_string($instructions) && trim($instructions) !== '' || is_array($instructions))) {
                return false;
            }
            if ($type === 'choice' && (! is_array($question['criteria'] ?? null)
                || count($question['criteria']) < 2 || count($question['criteria']) > 255
                || $this->choiceLabels($question['criteria']) === []
                || count(array_unique($this->choiceLabels($question['criteria']))) !== count($question['criteria']))) {
                return false;
            }
            if ($type === 'score' && (! is_array($question['criteria'] ?? null)
                || ! array_is_list($question['criteria']) || count($question['criteria']) < 2 || count($question['criteria']) > 10)) {
                return false;
            }
            if ($type === 'noul' && array_key_exists('criteria', $question) && ! is_array($question['criteria'])) {
                return false;
            }
        }

        return true;
    }

    private function containsExcludedPayload(mixed $value, mixed $rules): bool
    {
        if (! is_array($rules) || ! is_string($rules['key_pattern'] ?? null)
            || $rules['key_pattern'] === '' || ! is_array($rules['text_patterns'] ?? null)
            || $rules['text_patterns'] === []) {
            throw new \RuntimeException('Invalid payload exclusion policy.');
        }
        $matches = static function (string $pattern, string $text): bool {
            $result = @preg_match('~'.str_replace('~', '\\~', $pattern).'~i', $text);
            if ($result === false) {
                throw new \RuntimeException('Invalid payload exclusion expression.');
            }

            return $result === 1;
        };
        // Validate every rule even if this payload has no string values.
        $matches($rules['key_pattern'], '');
        foreach ($rules['text_patterns'] as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                throw new \RuntimeException('Invalid payload exclusion expression.');
            }
            $matches($pattern, '');
        }
        $walk = function (mixed $entry) use (&$walk, $rules, $matches): bool {
            if (is_object($entry)) {
                $entry = (array) $entry;
            }
            if (is_array($entry)) {
                foreach ($entry as $key => $child) {
                    $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $key)));
                    if ($matches($rules['key_pattern'], $normalized) || $walk($child)) {
                        return true;
                    }
                }
                if (is_string($entry['key'] ?? null) && array_key_exists('value', $entry)) {
                    return $walk([$entry['key'] => $entry['value']]);
                }
            } elseif (is_string($entry)) {
                foreach ($rules['text_patterns'] as $pattern) {
                    if ($matches($pattern, $entry)) {
                        return true;
                    }
                }
                $decoded = json_decode($entry, true);
                if (is_array($decoded)) {
                    return $walk($decoded);
                }
            }

            return false;
        };

        return $walk($value);
    }

    /** @return list<string> */
    private function choiceLabels(array $criteria): array
    {
        $labels = array_is_list($criteria) ? $criteria : array_keys($criteria);
        foreach ($labels as $label) {
            if (! is_string($label) || trim($label) === '') {
                return [];
            }
        }

        return array_values($labels);
    }

    /** @return array<string,mixed> */
    private function requestAndValidate(array $policy, string $apiKey, array $payload, array $questions, int &$attemptCount): array
    {
        $retries = $this->boundedInt($policy, 'max_retries', 1, 0, 1);
        $response = null;
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            if ($attempt > 0) {
                usleep(min(1000000, 250000 * (2 ** ($attempt - 1))));
            }
            $attemptCount++;
            try {
                $response = Http::acceptJson()->asJson()->withToken($apiKey)->withoutRedirecting()
                    ->connectTimeout($this->boundedInt($policy, 'connect_timeout_seconds', 2, 1, 10))
                    ->timeout($this->boundedInt($policy, 'timeout_seconds', 8, 2, 20))
                    ->post((string) $policy['endpoint'], $payload);
            } catch (ConnectionException $error) {
                if ($attempt < $retries) {
                    continue;
                }
                throw $error;
            }
            if ((in_array($response->status(), [408, 429], true) || $response->serverError()) && $attempt < $retries) {
                continue;
            }
            break;
        }

        if (! $response instanceof Response || ! $response->successful()) {
            $status = $response?->status();
            throw new \RuntimeException(in_array($status, [401, 403], true)
                ? 'provider_authentication_failed'
                : (in_array($status, [429, 529], true) ? 'provider_rate_limited' : 'provider_http_error'));
        }
        $decoded = $response->json();
        $validated = is_array($decoded) ? $this->validateResponse($decoded, $questions, (string) $payload['model']) : null;
        if ($validated === null) {
            throw new \RuntimeException('invalid_provider_result');
        }

        return $validated;
    }

    /** @return array<string,mixed>|null */
    private function validateResponse(array $response, array $questions, string $requestedModel): ?array
    {
        $model = $response['model'] ?? null;
        $answers = $response['answers'] ?? null;
        $usage = $response['usage'] ?? null;
        if ($model !== $requestedModel || ! is_array($answers) || ! is_array($usage)
            || array_diff(array_keys($questions), array_keys($answers)) !== []
            || array_diff(array_keys($answers), array_keys($questions)) !== []) {
            return null;
        }

        foreach ($questions as $id => $question) {
            $answer = $answers[$id] ?? null;
            if (! is_array($answer) || ($answer['type'] ?? null) !== $question['type']) {
                return null;
            }
            if ($question['type'] === 'noul') {
                if (! $this->numberBetween($answer['noul'] ?? null, 0, 1)) {
                    return null;
                }
            } elseif ($question['type'] === 'choice') {
                $labels = $this->choiceLabels($question['criteria']);
                if (! is_string($answer['choice'] ?? null) || ! in_array($answer['choice'], $labels, true)
                    || ! $this->validProbabilities($answer['probabilities'] ?? null, $labels)
                    || ! $this->numberBetween($answer['confidence'] ?? null, 0, 1)) {
                    return null;
                }
            } else {
                $levels = $question['criteria'];
                if (! $this->numberBetween($answer['score'] ?? null, 0, count($levels) - 1)
                    || ! is_array($answer['legend'] ?? null) || count($answer['legend']) !== count($levels)
                    || ! $this->validProbabilities($answer['probabilities'] ?? null, array_map('strval', array_keys($levels)))
                    || ! $this->numberBetween($answer['confidence'] ?? null, 0, 1)) {
                    return null;
                }
                foreach ($levels as $index => $level) {
                    if (($answer['legend'][(string) $index] ?? null) !== $level) {
                        return null;
                    }
                }
            }
        }

        $normalizedUsage = [];
        foreach (['input_tokens', 'output_tokens'] as $field) {
            $value = $usage[$field] ?? null;
            if ($value !== null && (! is_int($value) || $value < 0)) {
                return null;
            }
            $normalizedUsage[$field] = $value;
        }

        return ['model' => $model, 'answers' => $answers, 'usage' => $normalizedUsage];
    }

    private function validProbabilities(mixed $probabilities, array $keys): bool
    {
        $expectedKeys = array_map('strval', $keys);
        if (! is_array($probabilities) || array_diff($expectedKeys, array_map('strval', array_keys($probabilities))) !== []
            || array_diff(array_map('strval', array_keys($probabilities)), $expectedKeys) !== []) {
            return false;
        }
        $sum = 0.0;
        foreach ($probabilities as $value) {
            if (! $this->numberBetween($value, 0, 1)) {
                return false;
            }
            $sum += (float) $value;
        }

        return abs($sum - 1.0) <= 0.02;
    }

    private function numberBetween(mixed $value, float $minimum, float $maximum): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value)
            && $value >= $minimum && $value <= $maximum;
    }

    private function apiKey(): ?string
    {
        foreach ([getenv('TYPESAFE_API_KEY'), $_ENV['TYPESAFE_API_KEY'] ?? null, $_SERVER['TYPESAFE_API_KEY'] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $path = config('services.runtime_secret_files.TYPESAFE_API_KEY');
        if (! is_string($path) || trim($path) === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        try {
            $value = trim((string) file_get_contents($path));
        } catch (Throwable) {
            return null;
        }

        return $value !== '' ? $value : null;
    }

    /** @return array<string,mixed> */
    private function skipped(string $traceId, string $taskType, string $reason, float $startedAt, bool $providerAttempted = false): array
    {
        $durationMs = $this->durationMs($startedAt);
        if (! $providerAttempted) {
            $this->recordTelemetry($traceId, $taskType, 'jev-latest', 'typed_judgment_skip', true,
                'skipped_'.$reason, $durationMs, null, null, 0);
        }
        Log::info('TypeSafe Jev typed judgment skipped', [
            'route_trace_id' => $traceId,
            'task_type' => $taskType,
            'reason_code' => $reason,
            'duration_ms' => $durationMs,
        ]);

        return [
            'success' => true,
            'status' => 'skipped',
            'provider' => self::PROVIDER_ID,
            'route_trace_id' => $traceId,
            'task_type' => $taskType,
            'reason_code' => $reason,
            'fallback' => 'continue_existing_route',
            'duration_ms' => $durationMs,
        ];
    }

    private function recordProviderOutcome(
        string $traceId,
        string $taskType,
        string $model,
        bool $success,
        ?string $reason,
        int $durationMs,
        ?int $inputTokens,
        ?int $outputTokens,
        int $attemptIndex,
    ): void {
        $this->recordTelemetry($traceId, $taskType, $model, 'typed_judgment', $success,
            $reason, $durationMs, $inputTokens, $outputTokens, $attemptIndex);
    }

    private function recordTelemetry(
        string $traceId,
        string $taskType,
        string $model,
        string $requestKind,
        bool $success,
        ?string $reason,
        int $durationMs,
        ?int $inputTokens,
        ?int $outputTokens,
        int $attemptIndex,
    ): void {
        try {
            if (! Schema::hasTable('llm_model_request_logs')) {
                return;
            }
            $columns = array_flip(Schema::getColumnListing('llm_model_request_logs'));
            $row = [
                'instance_id' => self::PROVIDER_ID,
                'instance_type' => 'typed_api',
                'model_name' => $model,
                'caller' => 'jev_task:'.$taskType,
                'model_role' => 'typed_judgment',
                'request_kind' => $requestKind,
                'route_trace_id' => $traceId,
                'route_attempt_index' => $attemptIndex,
                'success' => $success,
                'latency_ms' => $durationMs,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'error_summary' => $reason,
                'created_at' => now(),
            ];
            DB::table('llm_model_request_logs')->insert(array_intersect_key($row, $columns));
            Cache::forget('ai_observability_request_metrics:24');
        } catch (Throwable) {
            Log::warning('TypeSafe Jev telemetry write failed', ['route_trace_id' => $traceId]);
        }
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function boundedInt(array $policy, string $key, int $fallback, int $minimum, int $maximum): int
    {
        $value = $policy[$key] ?? $fallback;

        return is_numeric($value) ? max($minimum, min($maximum, (int) $value)) : $fallback;
    }
}
