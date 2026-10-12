<?php

// Candidate inclusion from routes/api.php, which already provides the /api prefix.
use App\Http\Controllers\Api\CyberWorkflowJobController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'passport.scope.exact:cyber.jobs.read'])
    ->get('/v1/cyber/workflow-jobs/{handle}', [CyberWorkflowJobController::class, 'status'])->whereUuid('handle');
Route::middleware(['auth:api', 'passport.scope.exact:cyber.jobs.submit'])
    ->post('/v1/cyber/workflow-jobs', [CyberWorkflowJobController::class, 'submit']);
Route::middleware(['auth:api', 'passport.scope.exact:cyber.jobs.cancel'])
    ->post('/v1/cyber/workflow-jobs/{handle}/cancel', [CyberWorkflowJobController::class, 'cancel'])->whereUuid('handle');
Route::middleware(['auth:api', 'passport.scope.exact:cyber.jobs.resume'])
    ->post('/v1/cyber/workflow-jobs/{handle}/resume', [CyberWorkflowJobController::class, 'resume'])->whereUuid('handle');

// Explicit remote inspection/reconciliation; GET status retains local-only behavior.
Route::middleware(['auth:api', 'passport.scope.exact:cyber.jobs.read'])
    ->post('/v1/cyber/workflow-jobs/{handle}/refresh', [CyberWorkflowJobController::class, 'refresh'])->whereUuid('handle');
Route::middleware(['auth:api', 'passport.scope.exact:cyber.jobs.resume'])
    ->post('/v1/cyber/workflow-jobs/{handle}/reconcile', [CyberWorkflowJobController::class, 'reconcile'])->whereUuid('handle');
