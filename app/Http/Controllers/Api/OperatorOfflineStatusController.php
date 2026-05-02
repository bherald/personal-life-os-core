<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OperatorEvidenceService;
use Illuminate\Http\JsonResponse;

class OperatorOfflineStatusController extends Controller
{
    public function __invoke(OperatorEvidenceService $evidence): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $evidence->collectOfflineStatus(),
        ]);
    }
}
