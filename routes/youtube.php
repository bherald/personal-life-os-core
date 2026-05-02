<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\YouTubeController;
use App\Nodes\YouTube\WaitForCancellation;

/**
 * YouTube Integration Routes
 *
 * These routes provide API endpoints for YouTube workflow management,
 * OAuth authentication, and channel configuration.
 */

// OAuth Authentication Routes
Route::get('/youtube/auth', [YouTubeController::class, 'initiateAuth'])->name('youtube.auth');
Route::get('/youtube/auth/callback', [YouTubeController::class, 'handleCallback'])->name('youtube.auth.callback');
Route::post('/youtube/disconnect', [YouTubeController::class, 'disconnect'])->name('youtube.disconnect');
Route::get('/youtube/connection-status', [YouTubeController::class, 'status'])->name('youtube.connection.status');

// Channel Configuration Routes
Route::get('/youtube/subscriptions', [YouTubeController::class, 'getSubscriptions'])->name('youtube.subscriptions');
Route::get('/youtube/config', [YouTubeController::class, 'getChannelConfig'])->name('youtube.config');
Route::post('/youtube/config', [YouTubeController::class, 'updateChannelConfig'])->name('youtube.config.update');

// Statistics & Monitoring
Route::get('/youtube/stats', [YouTubeController::class, 'getStats'])->name('youtube.stats');

// Workflow cancellation endpoint
Route::post('/youtube/cancel/{workflowRunId}', function (string $workflowRunId) {
    $cancelled = WaitForCancellation::cancelWorkflow($workflowRunId);

    return response()->json([
        'success' => true,
        'workflow_run_id' => $workflowRunId,
        'cancelled' => $cancelled,
        'message' => 'Workflow cancellation requested'
    ]);
})->name('youtube.cancel');

// Check workflow cancellation status
Route::get('/youtube/status/{workflowRunId}', function (string $workflowRunId) {
    $cancelled = WaitForCancellation::isCancelled($workflowRunId);

    return response()->json([
        'workflow_run_id' => $workflowRunId,
        'cancelled' => $cancelled,
    ]);
})->name('youtube.status');

Route::post('/youtube/process', [YouTubeController::class, 'processVideo'])->name('youtube.process');
