<?php

use Illuminate\Support\Facades\Route;

// Horizon authentication POST handler
// Handles password submission before Horizon dashboard loads
Route::post('/horizon/{any?}', function (\Illuminate\Http\Request $request) {
    // Skip authentication in local environment
    if (app()->environment('local')) {
        session(['horizon_authenticated' => true]);
        return redirect('/horizon');
    }

    // Handle password submission
    if ($request->has('horizon_password')) {
        $masterPassword = config('app.web_ui_master_password', env('WEB_UI_MASTER_PASSWORD'));

        if ($request->input('horizon_password') === $masterPassword) {
            session(['horizon_authenticated' => true]);
            return redirect('/horizon');
        }

        return response()->view('horizon-login', [
            'error' => 'Invalid password'
        ], 401);
    }

    return redirect('/horizon');
})->where('any', '.*')->middleware('web');

// Agent review page — served to Pushover supplementary URL taps
// Standalone Blade page (not SPA) for mobile-friendly approval UI
Route::get('/review/{token}', function (string $token) {
    $item = \Illuminate\Support\Facades\DB::selectOne(
        "SELECT * FROM agent_review_queue WHERE token = ?",
        [$token]
    );

    if (!$item) {
        return response('Review item not found or expired.', 404);
    }

    return view('agent-review', ['item' => $item]);
});

// Agent command page — minimal text interface for Pushover remote use
Route::get('/command/{agentId?}', function (string $agentId = 'system') {
    $context = request('context');
    $reviewToken = request('review_token');
    return view('agent-command', [
        'agentId' => $agentId,
        'context' => $context,
        'reviewToken' => $reviewToken,
    ]);
});

// Catch-all route for Vue.js SPA
// This must be at the end to allow API routes to work
// Exclude api/* and horizon/* paths from catch-all
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api|horizon|command).*');
