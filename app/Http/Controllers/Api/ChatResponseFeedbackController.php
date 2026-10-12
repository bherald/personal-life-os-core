<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatResponseFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ChatResponseFeedbackController extends Controller
{
    public function __invoke(
        Request $request,
        int $conversationId,
        int $messageId,
        ChatResponseFeedbackService $feedback
    ): JsonResponse {
        $validated = $request->validate([
            'value' => 'required|string|in:helpful,unhelpful',
            'reason' => 'nullable|string|in:incorrect,incomplete,ignored_instruction,retry_after_failure,unrequested_action,expressed_frustration,other',
        ]);

        try {
            $recorded = $feedback->record(
                $conversationId,
                $messageId,
                $validated['value'],
                $validated['reason'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'feedback' => $recorded,
        ]);
    }
}
