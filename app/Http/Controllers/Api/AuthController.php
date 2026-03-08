<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * POST /api/admin/login
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email|max:255',
            'password' => 'required|string|min:8|max:128',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $user = User::where('email', $request->email)->first();

            // Selalu lakukan Hash::check meskipun user tidak ditemukan
            // untuk mencegah timing attack (user enumeration)
            $passwordValid = $user && Hash::check($request->password, $user->password);

            if (!$user || !$passwordValid) {
                Log::warning('Login gagal: kredensial salah', [
                    'email'      => $request->email,
                    'ip'         => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Email atau password salah.',
                ], 401);
            }

            if (!$user->is_active) {
                Log::warning('Login gagal: akun nonaktif', [
                    'email' => $request->email,
                    'ip'    => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Akun tidak aktif. Hubungi administrator.',
                ], 403);
            }

            // Hapus token lama milik user ini sebelum buat baru (single session)
            $user->tokens()->delete();

            $token = $user->createToken('admin-token', ['*'], now()->addMinutes(1440))->plainTextToken;

            Log::info('Login berhasil', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'ip'      => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Login berhasil.',
                'data'    => [
                    'token'      => $token,
                    'expires_in' => 1440,
                    'user'       => [
                        'id'    => $user->id,
                        'name'  => $user->name,
                        'email' => $user->email,
                        'role'  => $user->role,
                    ],
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Login exception', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server.'], 500);
        }
    }

    /**
     * POST /api/admin/logout
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            Log::info('Logout berhasil', [
                'user_id' => $request->user()->id,
            ]);

            return response()->json(['success' => true, 'message' => 'Logout berhasil.'], 200);

        } catch (\Exception $e) {
            Log::error('Logout exception', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Logout gagal.'], 500);
        }
    }

    /**
     * GET /api/admin/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/refresh
     * Revoke token lama, terbitkan token baru.
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $user->currentAccessToken()->delete();

            $newToken = $user->createToken('admin-token', ['*'], now()->addMinutes(1440))->plainTextToken;

            Log::info('Token di-refresh', ['user_id' => $user->id, 'ip' => $request->ip()]);

            return response()->json([
                'success' => true,
                'message' => 'Token berhasil diperbarui.',
                'data'    => [
                    'token'      => $newToken,
                    'expires_in' => 1440,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Token refresh exception', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal memperbarui token.'], 500);
        }
    }
}
