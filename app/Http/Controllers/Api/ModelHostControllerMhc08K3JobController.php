<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ModelHostControllerMhc08K3JobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ModelHostControllerMhc08K3JobController extends Controller
{
    public function submit(Request $request, ModelHostControllerMhc08K3JobService $jobs): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['role', 'prompt', 'system_prompt', 'max_tokens']) !== []) {
            return $this->unavailable('K3 submission request contains unsupported fields.', 422);
        }
        $validated = $request->validate([
            'role' => 'required|string|in:ultra_deep,archival',
            'prompt' => 'required|string|max:16000',
            'system_prompt' => 'nullable|string|max:4000',
            'max_tokens' => 'required|integer|min:1|max:128',
        ]);

        try {
            $receipt = $jobs->submit(
                $validated['role'],
                $validated['prompt'],
                $validated['system_prompt'] ?? null,
                (int) $validated['max_tokens'],
                'user',
                $this->requesterId($request),
            );

            return response()->json(['success' => true, 'data' => $receipt], 202);
        } catch (RuntimeException) {
            return $this->unavailable('K3 submission is unavailable.', 409);
        }
    }

    public function status(Request $request, string $taskId, ModelHostControllerMhc08K3JobService $jobs): JsonResponse
    {
        try {
            $status = $jobs->status($taskId, 'user', $this->requesterId($request));
            if ($status === null) {
                return $this->unavailable('K3 task was not found.', 404);
            }

            return response()->json(['success' => true, 'data' => $status]);
        } catch (RuntimeException) {
            return $this->unavailable('K3 task was not found.', 404);
        }
    }

    public function cancel(Request $request, string $taskId, ModelHostControllerMhc08K3JobService $jobs): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $jobs->cancel($taskId, 'user', $this->requesterId($request)),
            ]);
        } catch (RuntimeException) {
            return $this->unavailable('K3 cancellation is unavailable.', 409);
        }
    }

    private function requesterId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();
        if ((! is_int($id) && ! is_string($id)) || trim((string) $id) === '') {
            throw new RuntimeException('Authenticated K3 requester identity is unavailable.');
        }

        return 'user:'.trim((string) $id);
    }

    private function unavailable(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $message,
        ], $status);
    }
}
