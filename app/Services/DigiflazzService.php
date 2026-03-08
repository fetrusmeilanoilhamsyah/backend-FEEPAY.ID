<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class DigiflazzService
{
    protected string $username;
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->username = config('services.digiflazz.username');
        $this->apiKey   = config('services.digiflazz.api_key');
        $this->baseUrl  = config('services.digiflazz.base_url', 'https://api.digiflazz.com/v1');
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function sign(string $suffix): string
    {
        return md5($this->username . $this->apiKey . $suffix);
    }

    // ─── Public methods ───────────────────────────────────────────────────────

    /**
     * Ambil daftar harga dari Digiflazz API.
     */
    public function getPriceList(?string $category = null): array
    {
        try {
            $payload = [
                'cmd'      => 'prepaid',
                'username' => $this->username,
                'sign'     => $this->sign('df'),
            ];

            $response = Http::timeout(30)->post("{$this->baseUrl}/price-list", $payload);

            if (!$response->successful()) {
                throw new Exception('Gagal ambil price list: ' . $response->body());
            }

            $data = $response->json();
            $items = $data['data'] ?? [];

            if ($category) {
                $items = array_values(array_filter($items, fn($i) =>
                    isset($i['category']) && strtolower($i['category']) === strtolower($category)
                ));
            }

            return ['success' => true, 'data' => array_values($items)];

        } catch (Exception $e) {
            Log::error('DigiflazzService::getPriceList error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    /**
     * Kirim order ke Digiflazz.
     * Harga TIDAK boleh dikirim dari sini — sudah dikunci di database sebelum method ini dipanggil.
     */
    public function placeOrder(string $sku, string $targetNumber, string $refId): array
    {
        try {
            $payload = [
                'username'       => $this->username,
                'buyer_sku_code' => $sku,
                'customer_no'    => $targetNumber,
                'ref_id'         => $refId,
                'sign'           => $this->sign($refId),
            ];

            $response = Http::timeout(60)->post("{$this->baseUrl}/transaction", $payload);
            $data     = $response->json();

            $apiData = $data['data'] ?? [];
            $status  = strtolower($apiData['status'] ?? '');

            // Tolak eksplisit dari provider
            if ($response->failed() || $status === 'gagal') {
                $pesan = $apiData['message'] ?? 'Transaksi ditolak provider.';

                TelegramService::notify(
                    "⚠️ *DIGIFLAZZ REJECTED*\n" .
                    "----------------------------------\n" .
                    "*Order ID:* #{$refId}\n" .
                    "*SKU:* {$sku}\n" .
                    "*Target:* {$targetNumber}\n" .
                    "*Pesan:* _{$pesan}_\n" .
                    "----------------------------------\n" .
                    "_Laporan otomatis FEEPAY.ID_"
                );

                return ['success' => false, 'message' => $pesan, 'data' => $apiData];
            }

            Log::info('DigiflazzService::placeOrder success', [
                'ref_id' => $refId,
                'sku'    => $sku,
                'status' => $status,
            ]);

            return ['success' => true, 'data' => $apiData];

        } catch (Exception $e) {
            Log::error('DigiflazzService::placeOrder exception', [
                'message' => $e->getMessage(),
                'sku'     => $sku,
                'ref_id'  => $refId,
            ]);

            TelegramService::notify(
                "🚨 *SYSTEM ERROR — placeOrder*\n" .
                "----------------------------------\n" .
                "*Order ID:* #{$refId}\n" .
                "*Error:* " . $e->getMessage() . "\n" .
                "----------------------------------\n" .
                "_Cek log Laravel di VPS segera!_"
            );

            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Cek status order dari Digiflazz.
     */
    public function checkOrderStatus(string $refId): array
    {
        try {
            $payload = [
                'username' => $this->username,
                'ref_id'   => $refId,
                'sign'     => $this->sign($refId),
            ];

            $response = Http::timeout(30)->post("{$this->baseUrl}/transaction", $payload);

            if (!$response->successful()) {
                throw new Exception('Gagal cek status: ' . $response->body());
            }

            $data = $response->json();
            return ['success' => true, 'data' => $data['data'] ?? $data];

        } catch (Exception $e) {
            Log::error('DigiflazzService::checkOrderStatus error', [
                'message' => $e->getMessage(),
                'ref_id'  => $refId,
            ]);
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Cek saldo Digiflazz. Kirim peringatan Telegram jika di bawah threshold.
     */
    public function getBalance(): array
    {
        try {
            $payload = [
                'cmd'      => 'deposit',
                'username' => $this->username,
                'sign'     => $this->sign('depo'),
            ];

            $response = Http::timeout(30)->post("{$this->baseUrl}/cek-saldo", $payload);

            if (!$response->successful()) {
                throw new Exception('Gagal cek saldo: ' . $response->body());
            }

            $data    = $response->json();
            $balance = $data['data']['deposit'] ?? 0;

            if ($balance < 100000) {
                TelegramService::notify(
                    "💸 *WARNING: SALDO TIPIS!*\n" .
                    "----------------------------------\n" .
                    "*Sisa Saldo:* Rp " . number_format($balance, 0, ',', '.') . "\n" .
                    "----------------------------------\n" .
                    "_Segera Top Up saldo Digiflazz!_"
                );
            }

            return ['success' => true, 'data' => $data['data'] ?? $data];

        } catch (Exception $e) {
            Log::error('DigiflazzService::getBalance error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }
}
