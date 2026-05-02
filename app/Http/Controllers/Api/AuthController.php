<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $masterPassword = config('auth_config.master_password');

        \Log::info('Login attempt', [
            'match' => $request->password === $masterPassword,
        ]);

        if (! $masterPassword) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CONFIG_ERROR',
                    'message' => 'Master password not configured',
                ],
            ], 500);
        }

        // Check password
        if ($request->password !== $masterPassword) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'Invalid password',
                ],
            ], 401);
        }

        $adminEmail = config('auth_config.admin_email', 'admin@plos.local');

        // Create or get user using raw SQL
        $sql = 'SELECT * FROM users WHERE email = ? LIMIT 1';
        $users = DB::select($sql, [$adminEmail]);

        if (empty($users)) {
            $users = DB::select('SELECT * FROM users WHERE name = ? ORDER BY id ASC LIMIT 1', ['Admin']);
        }

        if (! empty($users) && $users[0]->email !== $adminEmail) {
            DB::update(
                'UPDATE users SET email = ?, updated_at = ? WHERE id = ?',
                [$adminEmail, now(), $users[0]->id]
            );
            $users = DB::select('SELECT * FROM users WHERE id = ? LIMIT 1', [$users[0]->id]);
        }

        if (empty($users)) {
            // User doesn't exist, create it
            DB::insert(
                'INSERT INTO users (name, email, password, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                ['Admin', $adminEmail, Hash::make($masterPassword), now(), now()]
            );
            $userId = DB::getPdo()->lastInsertId();

            // Fetch the newly created user
            $sql = 'SELECT * FROM users WHERE id = ? LIMIT 1';
            $user = DB::select($sql, [$userId])[0];
        } else {
            $user = $users[0];
        }

        // Convert stdClass to User model for Auth
        $userModel = \App\Models\User::find($user->id);

        // Log the user in using session
        Auth::login($userModel, true);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Not authenticated',
                ],
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
        ]);
    }
}
