<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;
use Google_Client;

class CustomerAuthController extends Controller
{
    /**
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'phone'    => 'nullable|string|max:20|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $user = User::create([
                'name'      => $request->name,
                'email'     => $request->email,
                'phone'     => $request->phone,
                'password'  => Hash::make($request->password),
                'role'      => 'user',
                'is_active' => 1,
            ]);

            $token = $user->createToken('user-token', ['*'], now()->addDays(7))->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Registrasi berhasil.',
                'data'    => $this->formatUserResponse($user, $token),
            ], 201);
        } catch (Exception $e) {
            Log::error('Register error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server.'], 500);
        }
    }

    /**
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'login'    => 'required|string', // Bisa email atau nomor HP
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $loginType = filter_var($request->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

            $user = User::where($loginType, $request->login)
                        ->where('role', 'user')
                        ->first();

            $passwordValid = $user && Hash::check($request->password, $user->password);

            if (!$user || !$passwordValid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kredensial salah atau pengguna tidak ditemukan.',
                ], 401);
            }

            if (!$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun tidak aktif.',
                ], 403);
            }

            $user->tokens()->delete();
            $token = $user->createToken('user-token', ['*'], now()->addDays(7))->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login berhasil.',
                'data'    => $this->formatUserResponse($user, $token),
            ], 200);

        } catch (Exception $e) {
            Log::error('Login error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server.'], 500);
        }
    }

    /**
     * POST /api/auth/google
     */
    public function google(Request $request): JsonResponse
    {
        $request->validate(['id_token' => 'required|string']);

        try {
            $client = new Google_Client(['client_id' => config('services.google.client_id')]);
            $payload = $client->verifyIdToken($request->id_token);

            if (!$payload) {
                return response()->json(['success' => false, 'message' => 'Token Google tidak valid.'], 401);
            }

            $user = User::where('google_id', $payload['sub'])
                        ->orWhere('email', $payload['email'])
                        ->first();

            if (!$user) {
                // Registrasi via Google
                $user = User::create([
                    'name'      => $payload['name'],
                    'email'     => $payload['email'],
                    'google_id' => $payload['sub'],
                    'avatar'    => $payload['picture'] ?? null,
                    'password'  => Hash::make(uniqid('google_', true)),
                    'role'      => 'user',
                    'is_active' => true,
                ]);
            } else {
                // Update Google ID jika belum ada (misal sebelumnya daftar via Email)
                if (empty($user->google_id)) {
                    $user->google_id = $payload['sub'];
                }
                // Update avatar 
                if (!empty($payload['picture'])) {
                    $user->avatar = $payload['picture'];
                }
                $user->save();
            }

            if (!$user->is_active) {
                return response()->json(['success' => false, 'message' => 'Akun tidak aktif.'], 403);
            }

            $user->tokens()->delete();
            $token = $user->createToken('user-token', ['*'], now()->addDays(7))->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login Google berhasil.',
                'data'    => $this->formatUserResponse($user, $token),
            ], 200);

        } catch (Exception $e) {
            Log::error('Google login error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat otentikasi Google.'], 500);
        }
    }

    /**
     * POST /api/auth/otp/request
     */
    public function otpRequest(Request $request): JsonResponse
    {
        $request->validate(['phone' => 'required|string|max:20']);

        try {
            $user = User::where('phone', $request->phone)->first();
            
            // Generate OTP 6 digit
            $otp = rand(100000, 999999);
            
            // Simpan OTP ke Cache selama 5 menit
            $cacheKey = 'otp_' . preg_replace('/[^0-9]/', '', $request->phone);
            Cache::put($cacheKey, $otp, now()->addMinutes(5));

            // Jika WA Gateway di-setup, tembak API-nya
            $waGatewayUrl = env('WA_GATEWAY_URL') ?: config('services.wa_gateway.url');
            Log::info("DEBUG: WA_GATEWAY_URL is " . ($waGatewayUrl ?: 'EMPTY'));

            if ($waGatewayUrl) {
                try {
                    $response = \Illuminate\Support\Facades\Http::timeout(10)->post($waGatewayUrl, [
                        'target'  => $request->phone,
                        'message' => "Kode OTP Rahasia FEEPAY Anda: *$otp*\n\n_Hati-hati, jangan berikan kode ini ke orang lain, termasuk pihak FEEPAY._"
                    ]);
                    
                    if ($response->successful()) {
                        Log::info("OTP dikirim ke {$request->phone} via Gateway. Response: " . $response->body());
                    } else {
                        Log::error("Gateway merespon dengen error: " . $response->status() . " - " . $response->body());
                    }
                } catch (\Exception $e) {
                    Log::error("Gagal mengirim OTP ke Gateway (Exception): " . $e->getMessage());
                }
            } else {
                 Log::info("OTP generated for {$request->phone}: {$otp} (Mode Simulasi - WA Gateway Belum Dikonfigurasi)");
            }

            return response()->json([
                'success' => true,
                'message' => 'Jika nomor terdaftar di WA, OTP akan dikirim.',
                // Catatan: Di production, jangan kembalikan OTP di payload. Ini hanya untuk simulasi/testing.
                'simulate_otp' => config('app.env') === 'local' ? $otp : null, 
            ], 200);

        } catch (Exception $e) {
            Log::error('OTP request error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal mengirim OTP.'], 500);
        }
    }

    /**
     * POST /api/auth/otp/verify
     */
    public function otpVerify(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|max:20',
            'otp'   => 'required|numeric|digits:6',
        ]);

        try {
            $phoneClean = preg_replace('/[^0-9]/', '', $request->phone);
            $cacheKey = 'otp_' . $phoneClean;
            $cachedOtp = Cache::get($cacheKey);

            if (!$cachedOtp || $cachedOtp != $request->otp) {
                return response()->json(['success' => false, 'message' => 'OTP tidak valid atau telah kedaluwarsa.'], 401);
            }

            // Bersihkan OTP dari cache
            Cache::forget($cacheKey);

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                // Registrasi otomatis jika belum ada
                $user = User::create([
                    'name'      => 'Pengguna ' . substr($phoneClean, -4),
                    'email'     => $phoneClean . '@wa.feepay.local',
                    'phone'     => $request->phone,
                    'password'  => Hash::make(uniqid('otp_', true)),
                    'role'      => 'user',
                    'is_active' => true,
                ]);
            }

            if (!$user->is_active) {
                return response()->json(['success' => false, 'message' => 'Akun tidak aktif.'], 403);
            }

            $user->tokens()->delete();
            $token = $user->createToken('user-token', ['*'], now()->addDays(7))->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login via OTP berhasil.',
                'data'    => $this->formatUserResponse($user, $token),
            ], 200);

        } catch (Exception $e) {
            Log::error('OTP verify error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server.'], 500);
        }
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'success' => true,
            'data'    => $this->formatUserResponse($user, null)['user']
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Logout berhasil.']);
    }

    /**
     * Helper response formatter
     */
    private function formatUserResponse(User $user, ?string $token): array
    {
        $data = [
            'user' => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => str_ends_with($user->email, '@wa.feepay.local') ? null : $user->email,
                'phone'     => $user->phone,
                'avatar'    => $user->avatar,
                'role'      => $user->role,
                'joined_at' => $user->created_at->toISOString(),
            ],
        ];

        if ($token) {
            $data['token'] = $token;
            $data['expires_in'] = 7 * 24 * 60; // 7 hari
        }

        return $data;
    }
}
