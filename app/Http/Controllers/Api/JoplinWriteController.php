<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JoplinWriteService;
use App\Services\JoplinFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Joplin Write API Controller
 *
 * Manages Joplin note write operations via WebDAV.
 * Provides endpoints for creating, updating, appending, and deleting notes.
 */
class JoplinWriteController extends Controller
{
    private JoplinWriteService $writeService;
    private JoplinFilesService $readService;

    public function __construct(JoplinWriteService $writeService, JoplinFilesService $readService)
    {
        $this->writeService = $writeService;
        $this->readService = $readService;
    }

    /**
     * GET /api/joplin/write/status
     * Get Joplin write service status
     */
    public function status(): JsonResponse
    {
        try {
            $readStatus = $this->readService->getStatus();
            $writeStatus = $this->writeService->getStatus();

            return response()->json([
                'success' => true,
                'read' => $readStatus,
                'write' => $writeStatus,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/joplin/notes/{id}/append
     * Append content to note
     */
    public function appendToNote(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'content' => 'required|string',
            'separator' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->writeService->appendToNote(
                $id,
                $request->input('content'),
                $request->input('separator', "\n\n")
            );

            if (!$result['success']) {
                return response()->json($result, 500);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/joplin/sync/detect-conflicts
     * Detect sync conflicts
     */
    public function detectConflicts(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'required|array',
            'notes.*.id' => 'required|string|size:32',
            'notes.*.updated_time' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $notes = collect($request->input('notes'))
                ->mapWithKeys(fn($note) => [$note['id'] => ['updated_time' => $note['updated_time']]])
                ->toArray();

            $conflicts = $this->writeService->detectConflicts($notes);

            return response()->json([
                'success' => true,
                'conflicts_count' => count($conflicts),
                'conflicts' => $conflicts,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
