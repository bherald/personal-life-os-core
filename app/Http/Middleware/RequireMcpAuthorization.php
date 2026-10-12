<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\JsonResponse;

/** Existing browser sessions or exact Passport scopes, never anonymous MCP execution. */
class RequireMcpAuthorization extends VerifyCsrfToken
{
    public function handle($request, Closure $next, string $scope = 'mcp.tools.invoke')
    {
        // A presented service credential must stand on its own; no cookie fallback.
        if ($request->headers->has('Authorization')) {
            if ($request->user('api') === null) {
                return new JsonResponse(['message' => 'Unauthenticated.'], 401);
            }

            return (new RequireExactPassportScope)->handle($request, $next, $scope);
        }
        if ($request->user('web') === null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        // API routes normally skip CSRF. Reuse Laravel's exact session/header and
        // encrypted XSRF-cookie matching here, without that broad API exemption.
        if (! $request->hasSession() || ! $this->tokensMatch($request)) {
            return new JsonResponse(['message' => 'CSRF token mismatch.'], 419);
        }

        return $next($request);
    }
}
