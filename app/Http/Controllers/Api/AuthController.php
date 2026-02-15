<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Admin login
     * 
     * POST /api/admin/login
     */
  public function login(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email' => 'required|email',
        'password' => 'required|string',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors(),
        ], 422);
    }

    try {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            // ✅ LOG failed login attempts
            Log::warning('Failed admin login attempt', [
                'email' => $request->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (!$user->is_active) {
            Log::warning('Inactive account login attempt', [
                'email' => $request->email,
                'ip' => $request->ip(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive',
            ], 403);
        }

        $token = $user->createToken('admin-token')->plainTextToken;

        // ✅ LOG successful login
        Log::info('Admin logged in successfully', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ],
        ], 200);

    } catch (\Exception $e) {
        Log::error('Login error', [
            'error' => $e->getMessage(),
            'email' => $request->email,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Login failed',
        ], 500);
    }
}
    /**
     * Admin logout
     * 
     * POST /api/admin/logout
     */
    public function logout(Request $request)
    {
        try {
            // Revoke current token
            $request->user()->currentAccessToken()->delete();

            Log::info('Admin logout', [
                'user_id' => $request->user()->id,
                'email' => $request->user()->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logout successful',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Logout error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Logout failed',
            ], 500);
        }
    }

    /**
     * Get authenticated user info
     * 
     * GET /api/admin/me
     */
    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'role' => $request->user()->role,
            ],
        ], 200);
    }
}