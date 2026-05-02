<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WebhookTriggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookTriggerController extends Controller
{
    public function handle(string $token, Request $request, WebhookTriggerService $service): JsonResponse
    {
        if (!$service->validateRequest($token, $request)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid webhook request',
            ], 401);
        }

        $result = $service->triggerWorkflow($token, $request->all());
        if (!($result['success'] ?? false)) {
            return response()->json($result, 422);
        }

        return response()->json($result, 202);
    }
}
