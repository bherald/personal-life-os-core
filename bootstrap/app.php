<?php

use App\Http\Middleware\GenealogyPrivacyMiddleware;
use App\Http\Middleware\RequireExactPassportScope;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/youtube.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Scoped CLI tool arguments preserve their original whitespace and
        // empty strings through the same API validation/router pipeline.
        $scopeRequest = fn (Request $request): bool => $request->is('api/mcp/call-direct-scoped');
        $middleware->trimStrings(except: [$scopeRequest]);
        $middleware->convertEmptyStringsToNull(except: [$scopeRequest]);

        // Trust all proxies for correct URL detection (localhost, .226, .227, etc.)
        $middleware->trustProxies(at: '*');

        // Exempt API routes from CSRF verification
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // Add session middleware to API routes for session-based authentication
        $middleware->group('api', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
        ]);

        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : '/login'
        );

        // Register middleware aliases
        $middleware->alias([
            'genealogy.privacy' => GenealogyPrivacyMiddleware::class,
            'passport.scope.exact' => RequireExactPassportScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
