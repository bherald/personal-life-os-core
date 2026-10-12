<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PersonalAccessTokenLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class OAuthController extends Controller
{
    public function __construct(
        private readonly PersonalAccessTokenLifecycleService $tokens,
    ) {}

    public function getTokens(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->tokens->listForUser($this->userId($request)),
            ]);
        } catch (Throwable $exception) {
            $this->logFailure('list', $exception);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'FETCH_FAILED', 'message' => 'Unable to list API tokens.'],
            ], 500);
        }
    }

    public function createToken(Request $request): JsonResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->tokens->issueForUser(
                    $this->userId($request),
                    $request->string('name')->toString(),
                ),
                'message' => 'Token created successfully',
            ], 201);
        } catch (Throwable $exception) {
            $this->logFailure('create', $exception);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'CREATE_FAILED', 'message' => 'Unable to create API token.'],
            ], 500);
        }
    }

    public function revokeToken(Request $request, string $id): JsonResponse
    {
        try {
            if (! $this->tokens->revokeForUser($this->userId($request), $id)) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Token not found.'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Token revoked successfully',
            ]);
        } catch (Throwable $exception) {
            $this->logFailure('revoke', $exception);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'REVOKE_FAILED', 'message' => 'Unable to revoke API token.'],
            ], 500);
        }
    }

    private function userId(Request $request): int
    {
        return (int) $request->user('web')->getAuthIdentifier();
    }

    private function logFailure(string $operation, Throwable $exception): void
    {
        Log::warning('Personal access token lifecycle failed', [
            'operation' => $operation,
            'exception_class' => $exception::class,
        ]);
    }
}
