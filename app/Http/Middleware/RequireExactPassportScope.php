<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

class RequireExactPassportScope
{
    public function handle(Request $request, Closure $next, string ...$requiredScopes): Response
    {
        $token = $request->user('api')?->token();
        $grantedScopes = $token instanceof AccessToken && is_array($token->oauth_scopes)
            ? array_values(array_filter($token->oauth_scopes, 'is_string'))
            : [];

        $hasExactScopes = ! in_array('*', $grantedScopes, true);

        foreach ($requiredScopes as $scope) {
            $hasExactScopes = $hasExactScopes && in_array($scope, $grantedScopes, true);
        }

        if (! $hasExactScopes) {
            return new JsonResponse(['message' => 'Insufficient token scope.'], 403);
        }

        return $next($request);
    }
}
