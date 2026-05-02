<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ops\AgentDoctorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentDoctorController extends Controller
{
    public function __invoke(Request $request, AgentDoctorService $doctor): JsonResponse
    {
        $agent = trim((string) $request->query('agent', ''));
        $windowHours = min(max((int) $request->query('since', 24), 1), 168);

        return response()->json([
            'success' => true,
            'data' => $doctor->collect(
                windowHours: $windowHours,
                agent: $agent !== '' ? $agent : null,
                quick: $request->boolean('quick')
            ),
        ]);
    }
}
