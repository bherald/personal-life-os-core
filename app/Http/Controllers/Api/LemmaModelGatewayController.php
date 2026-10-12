<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Http\Controllers\Controller;
use App\Services\LemmaModelGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;
use stdClass;
use Throwable;

class LemmaModelGatewayController extends Controller
{
    public function __construct(private readonly LemmaModelGatewayService $gateway) {}

    public function models(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => ['message' => 'Unauthorized.']], 401);
        }
        try {
            $model = $this->gateway->model()['model'];

            return response()->json(['object' => 'list', 'data' => [[
                'id' => $model, 'object' => 'model', 'owned_by' => 'plos-local',
            ]]]);
        } catch (Throwable) {
            return response()->json(['error' => ['message' => 'Local model unavailable.']], 503);
        }
    }

    public function complete(Request $request): Response|JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => ['message' => 'Unauthorized.']], 401);
        }
        try {
            $decoded = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof stdClass) {
                throw new JsonException('A JSON object is required.');
            }
            $payload = self::preserveEmptyObjects($decoded);
            $result = $this->gateway->complete($payload);

            return response($result['body'], $result['status'])
                ->header('Content-Type', $result['content_type'])
                ->header('Cache-Control', 'no-store');
        } catch (InferenceAdmissionException|JsonException) {
            return response()->json(['error' => ['message' => 'Model request is outside its admitted contract.']], 422);
        } catch (Throwable) {
            return response()->json(['error' => ['message' => 'Local model request failed; retry was not attempted.']], 503);
        }
    }

    /** Keep JSON Schema {} distinct from []; Laravel's associative JSON decoder merges them. */
    private static function preserveEmptyObjects(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            if ($fields === []) {
                return $value;
            }

            return array_map(self::preserveEmptyObjects(...), $fields);
        }
        if (is_array($value)) {
            return array_map(self::preserveEmptyObjects(...), $value);
        }

        return $value;
    }

    private function authorized(Request $request): bool
    {
        $token = config('lemma_model_gateway.token');

        return config('lemma_model_gateway.enabled') === true
            && is_string($token) && strlen($token) >= 32
            && is_string($request->bearerToken())
            && hash_equals($token, $request->bearerToken());
    }
}
