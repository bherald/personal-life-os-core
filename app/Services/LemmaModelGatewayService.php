<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/** Local Lemma's OpenAI-compatible ingress to an exact table-owned PLOS model. */
class LemmaModelGatewayService
{
    public function __construct(
        private readonly ModelHostControllerTransportSupervisor $supervisor,
        private readonly OpenAICompatibleProgressClient $client,
    ) {}

    public function model(): array
    {
        $instanceId = config('lemma_model_gateway.instance_id');
        $role = config('lemma_model_gateway.role');
        if (! is_string($instanceId) || trim($instanceId) === '' || ! is_string($role) || trim($role) === '') {
            throw new InferenceAdmissionException('Lemma model route is not configured.');
        }
        $provider = DB::table('llm_instances')->where('instance_id', $instanceId)
            ->first(['base_url', 'is_active', 'routability', 'config']);
        if ($provider === null || ! $provider->is_active || $provider->routability !== 'allowed') {
            throw new InferenceAdmissionException('Lemma model route is unavailable.');
        }
        $configuration = json_decode((string) $provider->config, true, flags: JSON_THROW_ON_ERROR);
        $model = $configuration['models'][$role] ?? null;
        $maxTokens = $configuration['role_runtime'][$role]['max_tokens'] ?? null;
        $think = $configuration['role_runtime'][$role]['think'] ?? null;
        $baseUrl = rtrim((string) $provider->base_url, '/').'/v1';
        if (! is_string($model) || $model === '' || ! is_int($maxTokens) || $maxTokens < 1
            || ! in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new InferenceAdmissionException('Lemma model route has no complete table contract.');
        }

        return ['base_url' => $baseUrl, 'model' => $model, 'max_tokens' => $maxTokens,
            'think' => $think];
    }

    /** @return array{status:int,body:string,content_type:string} */
    public function complete(array $payload): array
    {
        $route = $this->model();
        if (($payload['model'] ?? null) !== $route['model'] || ! is_array($payload['messages'] ?? null)
            || $payload['messages'] === [] || ! is_bool($payload['stream'] ?? false)) {
            throw new InferenceAdmissionException('Lemma request differs from the selected model contract.');
        }
        if (! isset($payload['max_tokens']) && ! isset($payload['max_completion_tokens'])) {
            $payload['max_tokens'] = $route['max_tokens'];
        }
        if ($route['think'] === false) {
            if (isset($payload['reasoning_effort']) && $payload['reasoning_effort'] !== 'none') {
                throw new InferenceAdmissionException('Lemma request differs from the registered thinking policy.');
            }
            $payload['reasoning_effort'] = 'none';
        }
        if (is_array($payload['tools'] ?? null)) {
            foreach ($payload['tools'] as &$tool) {
                $parameters = $tool['function']['parameters'] ?? null;
                if (! is_array($parameters)) {
                    continue;
                }
                $parameters = self::omitEmptySchemaProperties($parameters);
                if (($parameters['type'] ?? null) === 'object'
                    && ! isset($parameters['properties']) && ($parameters['required'] ?? []) === []
                    && ($parameters['additionalProperties'] ?? false) === false) {
                    unset($tool['function']['parameters']);
                } else {
                    $tool['function']['parameters'] = $parameters;
                }
            }
            unset($tool);
        }
        $endpoint = $route['base_url'].'/chat/completions';

        return $this->supervisor->run('openai_chat_completions', $endpoint, $payload,
            function () use ($route, $payload): array {
                $response = $this->client->requestChat($route['base_url'], $payload);
                $status = $response->getStatusCode();
                if ($status !== 200) {
                    throw new RuntimeException('Lemma model transport returned a non-success status.');
                }
                $body = $response->getBody();
                $content = '';
                while (! $body->eof()) {
                    $content .= $body->read(8192);
                    if (strlen($content) > 4 * 1024 * 1024) {
                        throw new RuntimeException('Lemma model response exceeded its transport limit.');
                    }
                }

                return ['status' => 200, 'body' => $content,
                    'content_type' => ($payload['stream'] ?? false) ? 'text/event-stream' : 'application/json'];
            });
    }

    /** Ollama rejects empty JSON Schema properties maps; omission has the same schema meaning. */
    private static function omitEmptySchemaProperties(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $nested) {
            if ($key === 'properties' && ($nested === [] || ($nested instanceof stdClass && get_object_vars($nested) === []))) {
                continue;
            }
            $result[$key] = self::omitEmptySchemaProperties($nested);
        }

        return $result;
    }
}
