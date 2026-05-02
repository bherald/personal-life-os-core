<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HorizonPasswordProtection
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip authentication in local environment
        if (app()->environment('local')) {
            session(['horizon_authenticated' => true]);
            return $next($request);
        }

        // Check if already authenticated
        if (session('horizon_authenticated')) {
            return $next($request);
        }

        // Handle password submission
        if ($request->isMethod('post') && $request->has('horizon_password')) {
            $masterPassword = config('app.web_ui_master_password');

            if ($request->input('horizon_password') === $masterPassword) {
                session(['horizon_authenticated' => true]);
                return redirect($request->path());
            }

            return response()->view('horizon-login', [
                'error' => 'Invalid password'
            ], 401);
        }

        // Show login form
        return response()->view('horizon-login');
    }
}
