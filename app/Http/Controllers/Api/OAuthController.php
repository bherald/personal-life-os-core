<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class OAuthController extends Controller
{
    /**
     * Get all access tokens for the authenticated user
     */
    public function getTokens(Request $request): JsonResponse
    {
        try {
            // For personal/family use, get all tokens from all users using raw SQL
            // In production, would be: $request->user()->tokens
            $sql = "SELECT * FROM oauth_access_tokens WHERE revoked = 0 ORDER BY created_at DESC";
            $tokenRecords = DB::select($sql);

            $tokens = array_map(function ($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name ?? 'Unnamed Token',
                    'revoked' => (bool) $token->revoked,
                    'created_at' => $token->created_at,
                    'expires_at' => $token->expires_at
                ];
            }, $tokenRecords);

            return response()->json([
                'success' => true,
                'data' => $tokens
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FETCH_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    /**
     * Create a new personal access token
     */
    public function createToken(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255'
        ]);

        try {
            // Generate a random token
            $token = Str::random(64);
            $hashedToken = hash('sha256', $token);

            $tokenUuid = Str::uuid();
            DB::insert(
                "INSERT INTO oauth_access_tokens (id, user_id, client_id, name, scopes, revoked, created_at, updated_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$tokenUuid, 1, 1, $request->name, json_encode([]), false, now(), now(), now()->addYear()]
            );
            $tokenId = DB::getPdo()->lastInsertId();

            return response()->json([
                'success' => true,
                'data' => [
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'expires_at' => now()->addYear()->toIso8601String()
                ],
                'message' => 'Token created successfully'
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CREATE_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    /**
     * Revoke an access token
     */
    public function revokeToken(string $id): JsonResponse
    {
        try {
            $updated = DB::update(
                "UPDATE oauth_access_tokens SET revoked = ?, updated_at = ? WHERE id = ?",
                [true, now(), $id]
            );

            if (!$updated) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Token not found']
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Token revoked successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'REVOKE_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }
}
