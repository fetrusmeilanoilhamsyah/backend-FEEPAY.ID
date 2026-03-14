<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WAGatewayController extends Controller
{
    private string $gatewayUrl;

    public function __construct()
    {
        $this->gatewayUrl = rtrim(config('services.wa_gateway.url', 'http://localhost:3001/api/send'), '/send');
    }

    /**
     * GET /api/admin/{path}/wa/status
     * Proxy ke Node.js WA Gateway untuk mendapatkan status koneksi & QR code.
     */
    public function status(): JsonResponse
    {
        try {
            $response = Http::timeout(5)->get($this->gatewayUrl . '/status');
            return response()->json($response->json());
        } catch (\Exception $e) {
            Log::error('WA Gateway status check failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'offline',
                'phone'   => null,
                'qr'      => null,
                'message' => 'Gateway Node.js tidak dapat dijangkau. Pastikan service sudah berjalan.',
            ], 503);
        }
    }

    /**
     * POST /api/admin/{path}/wa/disconnect
     * Proxy ke Node.js WA Gateway untuk logout & generate QR baru.
     */
    public function disconnect(): JsonResponse
    {
        try {
            $response = Http::timeout(10)->post($this->gatewayUrl . '/disconnect');
            return response()->json($response->json());
        } catch (\Exception $e) {
            Log::error('WA Gateway disconnect failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghubungi Gateway. Pastikan service sudah berjalan.',
            ], 503);
        }
    }
}
