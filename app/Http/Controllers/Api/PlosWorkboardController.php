<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlosWorkboardService;
use Illuminate\Http\JsonResponse;

class PlosWorkboardController extends Controller
{
    public function __invoke(PlosWorkboardService $workboard): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $workboard->payload(),
        ]);
    }
}
