<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CyberWorkflowJobService;
use App\Services\SystemConfigService;
use App\Services\UnifiedCli\CliStateStore;
use App\Services\UnifiedCli\CliWorkflowExecutionScope;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Narrow authenticated adapter; deployment is disabled until a qualified CYBER workflow is registered. */
final class CyberWorkflowJobController extends Controller
{
    public function submit(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $values = $this->validateMutation($request, ['submission_id', 'execution_intent', 'execution_scope']);

            return $this->scoped($values['execution_scope'], fn () => $this->jobs($request)->submit($values['submission_id'], $values['execution_intent']), 202);
        });
    }

    public function status(Request $request, string $handle): JsonResponse
    {
        return $this->respond(fn () => new JsonResponse(['success' => true, 'data' => $this->jobs($request)->status($handle)]));
    }

    public function cancel(Request $request, string $handle): JsonResponse
    {
        return $this->respond(function () use ($request, $handle) {
            $values = $this->validateMutation($request, ['execution_intent']);

            return new JsonResponse(['success' => true, 'data' => $this->jobs($request)->cancel($handle, $values['execution_intent'])]);
        });
    }

    public function refresh(Request $request, string $handle): JsonResponse
    {
        return $this->respond(function () use ($request, $handle) {
            $values = $this->validateMutation($request, ['execution_intent']);

            return new JsonResponse(['success' => true, 'data' => $this->jobs($request)->refresh($handle, $values['execution_intent'])]);
        });
    }

    public function reconcile(Request $request, string $handle): JsonResponse
    {
        return $this->respond(function () use ($request, $handle) {
            $values = $this->validateMutation($request, ['execution_intent']);

            return new JsonResponse(['success' => true, 'data' => $this->jobs($request)->reconcile($handle, $values['execution_intent'])]);
        });
    }

    public function resume(Request $request, string $handle): JsonResponse
    {
        return $this->respond(function () use ($request, $handle) {
            $values = $this->validateMutation($request, ['execution_intent', 'execution_scope']);

            return $this->scoped($values['execution_scope'], fn () => $this->jobs($request)->resume($handle, $values['execution_intent']), 202);
        });
    }

    private function respond(Closure $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (RuntimeException $error) {
            // Unexpected database/runtime subclasses retain the normal server-error boundary.
            if (get_class($error) !== RuntimeException::class) {
                throw $error;
            }
            $missing = $error->getMessage() === 'Task not found for this authenticated owner.';

            return new JsonResponse(['success' => false, 'error' => $missing ? 'Task not found.' : 'CYBER job request was not admitted.'], $missing ? 404 : 409);
        }
    }

    private function jobs(Request $request): CyberWorkflowJobService
    {
        $id = $request->user('api')?->getAuthIdentifier();
        if ((! is_int($id) && ! is_string($id)) || trim((string) $id) === '') {
            throw new RuntimeException('Authenticated API identity is required.');
        }
        $policy = app(SystemConfigService::class)->get('cyber.workflow_jobs', []);
        if (! is_array($policy) || ($policy['enabled'] ?? false) !== true
            || ! is_string($policy['state_path'] ?? null) || ! str_starts_with($policy['state_path'], '/')) {
            throw new RuntimeException('CYBER durable workflow endpoint has not been qualified and enabled.');
        }

        return new CyberWorkflowJobService(new CliStateStore($policy['state_path']), 'user:'.trim((string) $id));
    }

    private function validateMutation(Request $request, array $keys): array
    {
        if (array_diff(array_keys($request->all()), $keys) !== []) {
            throw new RuntimeException('Unsupported CYBER job request fields.');
        }
        $rules = ['submission_id' => 'required|uuid', 'execution_intent' => 'required|string', 'execution_scope' => 'required|array'];

        return $request->validate(array_intersect_key($rules, array_flip($keys)));
    }

    private function scoped(array $scope, Closure $operation, int $status): JsonResponse
    {
        $result = (new CliWorkflowExecutionScope)->run($scope, $operation);

        return new JsonResponse(['success' => true, 'data' => $result, 'execution_scope_schema' => CliWorkflowExecutionScope::SCHEMA], $status);
    }
}
