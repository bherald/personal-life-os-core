<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Exceptions\ModelHostControllerUnavailableException;
use App\Services\UnifiedCli\CliAgentRuntime;
use App\Services\UnifiedCli\CliOllamaRuntimePolicy;
use App\Services\UnifiedCli\CliProviderRuntimePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Existing controller custody around exact HTTP work; the original payload is never rewritten. */
class ModelHostControllerTransportSupervisor
{
    public function __construct(
        private readonly ModelHostControllerAutomaticSelectionService $selection,
        private readonly ModelHostControllerResourcePolicyService $resources,
        private readonly ModelHostControllerWaitService $waiting,
    ) {}

    public function run(string $wire, string $endpoint, array $payload, callable $operation, array $context = []): mixed
    {
        if ($wire === 'rerank' && $this->resources->managedProvider($endpoint) === null) {
            throw new InferenceAdmissionException('Dedicated reranking always requires a registered native controller endpoint.');
        }
        if (AIService::hasManagedInferenceContract() || ! $this->requiresSupervision($endpoint)) {
            return $operation();
        }
        $scope = $this->admit($wire, $endpoint, $payload, $context,
            ($context['capacity_fallback_supported'] ?? false) === true);
        $completed = false;
        try {
            $result = $this->step($scope, $wire, $endpoint, $payload, $operation);
            if (is_array($result) && ($result['success'] ?? null) === false) {
                throw new InferenceAdmissionException('Supervised model work failed; no alternate request is allowed.');
            }
            if (in_array($wire, ['ollama_embeddings', 'openai_embeddings'], true)) {
                $dimensions = $scope['request']['request_contract']['dimensions'] ?? null;
                if (! is_int($dimensions) || $dimensions < 1 || ! is_array($result)
                    || ! array_is_list($result) || count($result) !== $dimensions) {
                    throw new InferenceAdmissionException('Embedding response differs from its registered vector dimensions.');
                }
                foreach ($result as $value) {
                    if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                        throw new InferenceAdmissionException('Embedding response has invalid numeric values.');
                    }
                }
            }
            $completed = true;

            return $result;
        } catch (\Throwable $error) {
            throw $error instanceof InferenceAdmissionException ? $error
                : new InferenceAdmissionException('Supervised inference failed; no request may be replayed or replaced.', 0, $error);
        } finally {
            $this->finish($scope, $completed);
        }
    }

    /** Keep custody through the last streamed byte without retaining a global permit between yields. */
    public function stream(string $wire, string $endpoint, array $payload, callable $operation, array $context = []): \Generator
    {
        if (AIService::hasManagedInferenceContract() || ! $this->requiresSupervision($endpoint)) {
            yield from $operation();

            return;
        }
        $scope = $this->admit($wire, $endpoint, $payload, $context);
        $completed = false;
        $stream = null;
        try {
            $stream = $operation();
            if (! $stream instanceof \Generator) {
                throw new InferenceAdmissionException('Supervised streaming requires a generator.');
            }
            while ($this->step($scope, $wire, $endpoint, $payload, fn () => $stream->valid())) {
                yield $stream->key() => $stream->current();
                $this->step($scope, $wire, $endpoint, $payload, fn () => $stream->next());
            }
            $completed = true;
        } catch (\Throwable $error) {
            throw $error instanceof InferenceAdmissionException ? $error
                : new InferenceAdmissionException('Supervised stream failed; no request may be replayed or replaced.', 0, $error);
        } finally {
            unset($stream);
            $this->finish($scope, $completed);
        }
    }

    private function step(array &$scope, string $wire, string $endpoint, array $payload, callable $operation): mixed
    {
        if ($scope['dispatched']) {
            $this->selection->renewExact($scope);
        }

        $keeper = function () use (&$scope): void {
            $this->selection->renewExact($scope);
        };
        $validator = function (string $actualWire, string $actualEndpoint, array $settings) use (&$scope, $wire, $endpoint, $payload): void {
            if ($actualWire !== $wire || $actualEndpoint !== $endpoint
                || ModelHostControllerResourcePolicyService::requestContractIdentity($settings)
                    !== ModelHostControllerResourcePolicyService::requestContractIdentity(self::settings($payload,
                        $wire === 'rerank' ? ['Host' => $scope['request']['request_contract']['backend_http_host']] : []))) {
                throw new InferenceAdmissionException('Supervised HTTP request changed after admission.');
            }
            $this->selection->dispatchExact($scope);
        };

        return AIService::withNonReplayableInference(fn () => AIService::withInferenceTransportValidator(
            fn () => AIService::withInferenceProgressKeeper($operation, $keeper), $validator));
    }

    private function finish(array &$scope, bool $completed): void
    {
        try {
            $this->selection->finishExact($scope, $completed);
        } catch (\Throwable $error) {
            throw new InferenceAdmissionException('Controller outcome requires reconciliation; no request may be replayed.', 0, $error);
        }
    }

    private function requiresSupervision(string $endpoint): bool
    {
        if ($this->resources->managedProvider($endpoint) !== null) {
            return true;
        }
        if ($this->resources->managedHostForEndpoint($endpoint) !== null) {
            throw new InferenceAdmissionException('Managed host inference has no registered provider or capacity contract.');
        }

        return false;
    }

    private function admit(string $wire, string $endpoint, array $payload, array $context, bool $capacityFallbackSupported = false): array
    {
        try {
            return $this->open($wire, $endpoint, $payload, $context);
        } catch (\Throwable $error) {
            if ($capacityFallbackSupported && $wire === 'ollama_generate' && ! array_key_exists('images', $payload)
                && $error instanceof ModelHostControllerUnavailableException && $error->allowsCapacityFallback()) {
                throw $error;
            }
            throw new InferenceAdmissionException('Controller admission did not complete; no alternate model request is allowed.', 0, $error);
        }
    }

    protected function open(string $wire, string $endpoint, array $payload, array $context = []): array
    {
        ['request' => $request, 'target' => $target] = $this->prepare($wire, $endpoint, $payload, $context);

        return $this->waiting->await(fn () => $this->selection->beginExact($request, $target), [
            ...$context, 'host_id' => $target['controller_host_id'], 'request_id' => $request['request_id'],
            'admission_failed' => function (array $scope): void {
                $this->selection->finishExact($scope, false);
            },
        ]);
    }

    /** Read-only preparation for tests and operator review; no controller or inference HTTP. */
    public function prepare(string $wire, string $endpoint, array $payload, array $context = []): array
    {
        $provider = $this->resources->managedProvider($endpoint);
        if ($provider === null) {
            throw new InferenceAdmissionException('Managed endpoint registration changed.');
        }
        $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
        $model = $payload['model'] ?? null;
        $matches = array_filter($config['controller_catalog']['targets'] ?? [], fn ($target) => ($target['model'] ?? null) === $model);
        if (count($matches) !== 1 || ! (bool) $provider->is_active || $provider->routability !== 'allowed') {
            throw new InferenceAdmissionException('Exact model is not uniquely admitted on this managed endpoint.');
        }
        $target = reset($matches);
        if (in_array($wire, ['ollama_embeddings', 'openai_embeddings', 'image_generation', 'rerank'], true)) {
            return $this->prepareWorkload($wire, $payload, $provider, $config, $target, $context);
        }
        $roles = array_values(array_intersect($target['roles'] ?? [], $config['route_policy']['allowed_model_roles'] ?? []));
        $roles = array_values(array_filter($roles, fn ($role) => ($config['models'][$role] ?? null) === $model));
        if ($roles === []) {
            throw new InferenceAdmissionException('Selected managed model has no admitted role.');
        }
        $role = $config['controller_transport_role'] ?? $roles[0];
        if (! is_string($role) || ! in_array($role, $roles, true)) {
            throw new InferenceAdmissionException('Configured transport role is not admitted for the selected model.');
        }
        $definition = DB::table('ollama_models')->where('instance_id', $provider->id)->where('model_name', $model)
            ->where('is_available', 1)->first(['capabilities', 'context_length']);
        if ($definition === null) {
            throw new InferenceAdmissionException('Selected model is unavailable in its catalog.');
        }
        $capabilities = json_decode($definition->capabilities ?? '[]', true) ?? [];
        $nativeTools = ($config['role_runtime'][$role]['native_tools_enabled'] ?? $config['route_policy']['tools_enabled'] ?? false) === true
            && array_intersect($capabilities, ['tools', 'tool_calling', 'tool_use']) !== [];
        $candidate = ['configuration_eligible' => true, 'instance_id' => $provider->instance_id,
            'engine' => $target['engine'], 'model' => $model, 'role' => $role,
            'native_tools_configured' => $nativeTools,
            'model_definition' => ['capabilities' => $capabilities, 'context_length' => $definition->context_length],
            'instance' => (array) $provider];
        $defaults = json_decode((string) DB::table('system_configs')->where('section', 'cli')->where('config_key', 'runtime_policy')->value('config_value'), true);
        $contract = match (true) {
            $provider->instance_type === 'ollama' => (new CliOllamaRuntimePolicy)->resolve($candidate, ['cli.runtime_policy' => $defaults]),
            CliAgentRuntime::isColibriEngine($candidate['engine']) => CliAgentRuntime::validateContract($candidate),
            default => (new CliProviderRuntimePolicy)->resolve($candidate, ['cli.runtime_policy' => $defaults]),
        };
        // Preserve the native contract hash; modality defaults only validate this HTTP payload.
        self::assertPayload($wire, $payload, $contract + ['native_tools_enabled' => $nativeTools, 'input_modalities' => ['text']], $config);
        $binding = $this->resources->assertRequestContract($target['controller_host_id'], $target['target_id'], $contract);
        if ($binding === null) {
            throw new InferenceAdmissionException('Managed transport requires enabled resource admission.');
        }
        $request = ['role' => $role, 'request_id' => $context['request_id'] ?? 'mhc-http-'.Str::uuid(),
            'lease_id' => 'mhc-http-'.Str::uuid(), 'request_contract' => $contract, 'capacity_binding' => $binding];

        return compact('request', 'target');
    }

    /** Select only registered embedding defaults; never shorten the supplied input. */
    public function embeddingPayload(string $endpoint, string $model, string $input): array
    {
        $provider = $this->resources->managedProvider($endpoint);
        $config = json_decode($provider->config ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $contract = $config['controller_workloads']['ollama_embeddings'][$model]['contract'] ?? null;
        if (! is_array($contract)) {
            throw new InferenceAdmissionException('Embedding model has no registered workload settings.');
        }
        $payload = ['model' => $model, 'input' => $input, 'options' => $contract['ollama_options'] ?? [],
            'keep_alive' => 0, 'truncate' => false];
        self::assertWorkloadPayload('ollama_embeddings', $payload, $contract);

        return $payload;
    }

    /** Non-chat workloads use explicit existing provider configuration and the same custody path. */
    private function prepareWorkload(string $wire, array $payload, object $provider, array $config, array $target, array $context): array
    {
        $entry = $config['controller_workloads'][$wire][$payload['model']] ?? null;
        $contract = $entry['contract'] ?? null;
        $role = $entry['role'] ?? null;
        $capability = match ($wire) {
            'image_generation' => 'image_generation', 'rerank' => 'reranking', default => 'embedding'
        };
        $definition = DB::table('ollama_models')->where('instance_id', $provider->id)
            ->where('model_name', $payload['model'])->where('is_available', 1)->first(['capabilities']);
        $capabilities = json_decode($definition->capabilities ?? '[]', true) ?? [];
        if (! is_array($contract) || ! is_string($role)
            || ! in_array($role, $target['roles'] ?? [], true)
            || ! in_array($role, $config['route_policy']['allowed_model_roles'] ?? [], true)
            || ($config['models'][$role] ?? null) !== $payload['model']
            || ! in_array($capability, $capabilities, true)
            || ($contract['model'] ?? null) !== $payload['model']
            || ($contract['wire_api'] ?? null) !== $wire
            || ($contract['controller_target'] ?? null) !== $target['target_id']
            || ($contract['controller_host_id'] ?? null) !== $target['controller_host_id']
            || ($wire === 'rerank' && ($contract['native_port'] ?? null) !== ($target['port'] ?? null))) {
            throw new InferenceAdmissionException('Workload has no exact admitted capability and resource contract.');
        }
        if ($capability === 'embedding' && (! is_int($contract['dimensions'] ?? null) || $contract['dimensions'] < 1)) {
            throw new InferenceAdmissionException('Embedding vector dimensions are not registered.');
        }
        self::assertWorkloadPayload($wire, $payload, $contract);
        $binding = $this->resources->assertRequestContract($target['controller_host_id'], $target['target_id'], $contract);
        if ($binding === null) {
            throw new InferenceAdmissionException('Workload requires enabled controller resource admission.');
        }
        $request = ['role' => $role, 'request_id' => $context['request_id'] ?? 'mhc-http-'.Str::uuid(),
            'lease_id' => 'mhc-http-'.Str::uuid(), 'request_contract' => $contract, 'capacity_binding' => $binding];

        return compact('request', 'target');
    }

    public static function assertWorkloadPayload(string $wire, array $payload, array $contract): void
    {
        if (($payload['model'] ?? null) !== ($contract['model'] ?? null) || ($contract['wire_api'] ?? null) !== $wire) {
            throw new InferenceAdmissionException('Workload model or transport differs from its admitted contract.');
        }
        if ($wire === 'rerank') {
            self::assertRerankPayload($payload, $contract);

            return;
        }
        $maximumBytes = $contract['maximum_input_bytes'] ?? null;
        $input = $wire === 'image_generation' ? ($payload['prompt'] ?? null) : ($payload['input'] ?? null);
        if (! is_int($maximumBytes) || $maximumBytes < 1 || ! is_string($input)
            || $input === '' || strlen($input) > $maximumBytes || ! mb_check_encoding($input, 'UTF-8')) {
            throw new InferenceAdmissionException('Workload input exceeds its admitted envelope; input was not modified.');
        }
        if ($wire === 'ollama_embeddings') {
            if (array_diff(array_keys($payload), ['model', 'input', 'options', 'truncate', 'keep_alive']) !== []
                || ($payload['truncate'] ?? null) !== false || ($payload['keep_alive'] ?? null) !== 0
                || ! is_array($contract['ollama_options'] ?? null)
                || self::requestSettingsIdentity($payload['options'] ?? []) !== self::requestSettingsIdentity($contract['ollama_options'])
                || ($payload['options']['num_ctx'] ?? null) !== ($contract['context_window'] ?? null)) {
                throw new InferenceAdmissionException('Embedding resource options or input preservation settings differ.');
            }

            return;
        }
        if ($wire === 'openai_embeddings') {
            if (array_diff(array_keys($payload), ['model', 'input', 'dimensions']) !== []
                || ($payload['dimensions'] ?? null) !== ($contract['dimensions'] ?? null)) {
                throw new InferenceAdmissionException('Embedding dimensions differ from their registered identity.');
            }

            return;
        }
        if ($wire !== 'image_generation' || array_diff(array_keys($payload),
            ['model', 'prompt', 'negative_prompt', 'width', 'height', 'steps', 'guidance_scale', 'seed']) !== []) {
            throw new InferenceAdmissionException('Unknown workload transport or settings.');
        }
        foreach (['width', 'height', 'steps'] as $field) {
            $maximum = $contract['maximum_'.$field] ?? null;
            if (! is_int($maximum) || ! is_int($payload[$field] ?? null) || $payload[$field] < 1 || $payload[$field] > $maximum
                || (in_array($field, ['width', 'height'], true) && $payload[$field] % 64 !== 0)) {
                throw new InferenceAdmissionException('Image workload exceeds its qualified resolution or step budget.');
            }
        }
        if (isset($payload['negative_prompt']) && (! is_string($payload['negative_prompt'])
            || strlen($payload['negative_prompt']) > $maximumBytes)) {
            throw new InferenceAdmissionException('Negative prompt exceeds its admitted input budget.');
        }
        if (! is_numeric($payload['guidance_scale'] ?? null) || ! is_finite((float) $payload['guidance_scale'])
            || ! is_numeric($contract['maximum_guidance_scale'] ?? null)
            || $payload['guidance_scale'] < 0 || $payload['guidance_scale'] > $contract['maximum_guidance_scale']) {
            throw new InferenceAdmissionException('Image guidance is outside its admitted envelope.');
        }
        if (isset($payload['seed']) && (! is_int($payload['seed']) || $payload['seed'] < 0 || $payload['seed'] > 4294967295)) {
            throw new InferenceAdmissionException('Image seed is invalid.');
        }
    }

    private static function assertRerankPayload(array $payload, array $contract): void
    {
        $maximum = $contract['maximum_input_bytes'] ?? null;
        $documents = $payload['documents'] ?? null;
        $limit = $contract['maximum_documents'] ?? null;
        if (array_diff(array_keys($payload), ['model', 'query', 'instruction', 'documents']) !== []
            || ! is_int($maximum) || $maximum < 1 || $maximum > 262144
            || ! is_int($limit) || $limit < 1 || $limit > 50
            || ! is_array($documents) || ! array_is_list($documents) || $documents === [] || count($documents) > $limit
            || ! is_string($payload['query'] ?? null) || trim($payload['query']) === ''
            || ! mb_check_encoding($payload['query'], 'UTF-8')
            || ! is_string($payload['instruction'] ?? null) || trim($payload['instruction']) === ''
            || ($payload['instruction'] ?? null) !== ($contract['instruction'] ?? null)
            || ! is_string($contract['revision'] ?? null) || preg_match('/^[a-f0-9]{40}$/D', $contract['revision']) !== 1
            || ($contract['scoring'] ?? null) !== 'yes_no_softmax' || ($contract['device'] ?? null) !== 'cpu'
            || ($contract['complete_input'] ?? null) !== true
            || ! is_int($contract['native_port'] ?? null) || $contract['native_port'] < 1024 || $contract['native_port'] > 65535
            || ! in_array($contract['backend_http_host'] ?? null,
                ['127.0.0.1:'.$contract['native_port'], 'localhost:'.$contract['native_port']], true)) {
            throw new InferenceAdmissionException('Rerank input or scoring differs from its exact registered contract.');
        }
        $ids = [];
        foreach ($documents as $document) {
            if (! is_array($document) || array_diff(array_keys($document), ['id', 'text']) !== []
                || ! is_string($document['id'] ?? null) || $document['id'] === '' || isset($ids[$document['id']])
                || strlen($document['id']) > 120 || ! is_string($document['text'] ?? null)
                || trim($document['text']) === '' || ! mb_check_encoding($document['text'], 'UTF-8')) {
                throw new InferenceAdmissionException('Rerank requires complete, unique original documents.');
            }
            $ids[$document['id']] = true;
        }
        if (strlen(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > $maximum) {
            throw new InferenceAdmissionException('Rerank exceeds its admitted input bytes; source was not shortened.');
        }
    }

    private static function requestSettingsIdentity(array $settings): string
    {
        return ModelHostControllerResourcePolicyService::requestContractIdentity($settings);
    }

    /** Capacity envelope and actual wire settings are separate; never clamp an inadmissible request. */
    public static function assertPayload(string $wire, array $payload, array $contract, array $config): void
    {
        $ollama = in_array($wire, ['ollama_generate', 'ollama_chat'], true);
        if ((! $ollama && $wire !== 'openai_chat_completions') || ($payload['model'] ?? null) !== $contract['model']) {
            throw new InferenceAdmissionException('Transport or exact model is outside the admitted envelope.');
        }
        $allowed = $ollama
            ? ['model', 'messages', 'prompt', 'system', 'images', 'stream', 'temperature', 'options', 'format', 'think', 'tools', 'keep_alive', 'truncate', 'shift']
            : ['model', 'messages', 'stream', 'stream_options', 'max_tokens', 'max_completion_tokens', 'temperature', 'top_p',
                'response_format', 'tools', 'tool_choice', 'parallel_tool_calls', 'reasoning_effort', 'stop', 'enable_thinking'];
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new InferenceAdmissionException('Unknown HTTP settings cannot inherit controller capacity admission.');
        }
        // The normal compatible text builder explicitly disables reasoning. This does
        // not authorize enabling it or accepting loosely typed server extensions.
        if (! $ollama && array_key_exists('enable_thinking', $payload) && $payload['enable_thinking'] !== false) {
            throw new InferenceAdmissionException('Only explicitly disabled thinking is admitted for compatible text.');
        }
        $options = $payload['options'] ?? [];
        if (! is_array($options)) {
            throw new InferenceAdmissionException('Invalid model options.');
        }
        $maximum = $ollama ? ($options['num_predict'] ?? null) : ($payload['max_tokens'] ?? $payload['max_completion_tokens'] ?? null);
        if (! is_int($maximum) || $maximum < 1 || $maximum > $contract['max_output_tokens']) {
            throw new InferenceAdmissionException('Requested output exceeds the admitted capacity envelope.');
        }
        if ($ollama) {
            $expected = $contract['ollama_options'] ?? [];
            foreach ($expected as $key => $value) {
                if (! in_array($key, ['num_predict', 'temperature', 'top_p'], true)
                    && (! array_key_exists($key, $options) || $options[$key] !== $value)) {
                    throw new InferenceAdmissionException('Required model resource options are missing or changed.');
                }
            }
            foreach ($options as $key => $value) {
                if (in_array($key, ['num_predict', 'temperature', 'top_p'], true)) {
                    continue;
                }
                if ($key === 'num_ctx') {
                    if ($value !== $contract['context_window']) {
                        throw new InferenceAdmissionException('Requested context differs from the admitted envelope.');
                    }

                    continue;
                }
                if (! array_key_exists($key, $expected) || $value !== $expected[$key]) {
                    throw new InferenceAdmissionException('Model resource options differ from the admitted table settings.');
                }
            }
            if (($options['num_ctx'] ?? null) !== $contract['context_window']) {
                throw new InferenceAdmissionException('Managed Ollama requires its explicit admitted context.');
            }
        }
        if ((! empty($payload['tools']) || isset($payload['tool_choice'])) && ! $contract['native_tools_enabled']) {
            throw new InferenceAdmissionException('Model tools are not admitted for this role.');
        }
        if (($payload['parallel_tool_calls'] ?? false) !== false) {
            throw new InferenceAdmissionException('Parallel model work is not admitted.');
        }
        if ((isset($payload['response_format']) || isset($payload['format'])) && ($config['structured_output']['enabled'] ?? false) !== true) {
            throw new InferenceAdmissionException('Structured output is not admitted for this model.');
        }
        if (($contract['manual_text_only'] ?? false) === true) {
            foreach ($payload['messages'] ?? [] as $message) {
                if (! is_array($message) || ($message['role'] ?? null) === 'tool'
                    || array_key_exists('tool_calls', $message) || array_key_exists('function_call', $message)) {
                    throw new InferenceAdmissionException('Manual text admission does not admit tool history.');
                }
            }
        }
        $image = ! empty($payload['images']);
        foreach ($payload['messages'] ?? [] as $message) {
            if (! is_array($message)) {
                throw new InferenceAdmissionException('Invalid model message.');
            }
            if (! empty($message['images'])) {
                $image = true;
            }
            if (is_array($message['content'] ?? null)) {
                foreach ($message['content'] as $part) {
                    if (! in_array($part['type'] ?? null, ['text', 'image_url'], true)) {
                        throw new InferenceAdmissionException('Input modality is not admitted.');
                    }
                    $image = $image || ($part['type'] ?? null) === 'image_url';
                }
            }
        }
        if ($image && ! in_array('image', $contract['input_modalities'], true)) {
            throw new InferenceAdmissionException('Image input is not admitted for this model.');
        }
    }

    public static function settings(array $payload, array $headers = []): array
    {
        if (array_key_exists('documents', $payload)) {
            $payload['rerank_input_sha256'] = hash('sha256', json_encode(
                ['query' => $payload['query'] ?? null, 'instruction' => $payload['instruction'] ?? null,
                    'documents' => $payload['documents']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
            unset($payload['query'], $payload['instruction'], $payload['documents']);
        }
        unset($payload['messages'], $payload['prompt'], $payload['system'], $payload['images'], $payload['input'], $payload['negative_prompt']);

        return $headers === [] ? $payload : $payload + ['http_headers' => $headers];
    }
}
