<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MidtransService
{
    protected string $serverKey;
    protected string $clientKey;
    protected bool   $isProduction;
    protected string $snapUrl;
    protected string $apiUrl;

    public function __construct()
    {
        $this->serverKey    = config('midtrans.server_key');
        $this->clientKey    = config('midtrans.client_key');
        $this->isProduction = config('midtrans.is_production', false);

        $this->snapUrl = $this->isProduction
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        $this->apiUrl = $this->isProduction
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2';
    }

    // ─────────────────────────────────────────────────────────
    // 1. BUAT SNAP TOKEN (Dipanggil saat user checkout)
    // ─────────────────────────────────────────────────────────

    /**
     * Buat Snap Token dari Midtrans dan simpan ke tabel payments.
     *
     * @param  Transaction  $transaction  Data transaksi dari tabel lama
     * @return Payment
     * @throws \Exception
     */
    public function createSnapToken(Transaction $transaction): Payment
    {
        $orderId = 'FEEPAY-' . strtoupper(Str::random(8)) . '-' . time();

        $params = [
            'transaction_details' => [
                'order_id'     => $orderId,
                'gross_amount' => (int) $transaction->amount, // Midtrans wajib integer
            ],
            'customer_details' => [
                'first_name' => $transaction->customer_name ?? 'Customer',
                'email'      => $transaction->customer_email ?? '',
                'phone'      => $transaction->customer_phone ?? '',
            ],
            'item_details' => [
                [
                    'id'       => $transaction->product_code ?? 'PROD-001',
                    'price'    => (int) $transaction->amount,
                    'quantity' => 1,
                    'name'     => $transaction->product_name ?? 'Produk Digital FEEPAY',
                ],
            ],
            // Aktifkan metode bayar yang diinginkan
            'enabled_payments' => [
                'bca_va',
                'bni_va',
                'bri_va',
                'permata_va',
                'other_va',
                'gopay',
                'qris',
                'dana',
                'ovo',
            ],
            // Konfigurasi Snap
            'credit_card' => [
                'secure' => true,
            ],
            // Waktu expired: 24 jam
            'expiry' => [
                'unit'     => 'hours',
                'duration' => 24,
            ],
        ];

        $response = $this->callSnapApi($params);

        // Simpan ke tabel payments
        $payment = Payment::create([
            'order_id'        => $orderId,
            'transaction_id'  => $transaction->id,
            'snap_token'      => $response['token'],
            'payment_url'     => $response['redirect_url'],
            'gross_amount'    => $transaction->amount,
            'status'          => 'pending',
            'customer_name'   => $transaction->customer_name,
            'customer_email'  => $transaction->customer_email,
            'customer_phone'  => $transaction->customer_phone,
            'expired_at'      => now()->addHours(24),
            'midtrans_response' => $response,
        ]);

        Log::info('[Midtrans] Snap token dibuat', [
            'order_id'   => $orderId,
            'payment_id' => $payment->id,
        ]);

        return $payment;
    }

    // ─────────────────────────────────────────────────────────
    // 2. HANDLE WEBHOOK (Dipanggil oleh Midtrans otomatis)
    // ─────────────────────────────────────────────────────────

    /**
     * Proses notifikasi webhook dari Midtrans.
     * Midtrans akan POST ke /api/midtrans/webhook setiap ada update status.
     *
     * @param  array  $payload  Data dari request webhook
     * @return Payment|null
     */
    public function handleWebhook(array $payload): ?Payment
    {
        // Verifikasi signature keamanan
        if (! $this->verifySignature($payload)) {
            Log::warning('[Midtrans] Signature webhook tidak valid', $payload);
            return null;
        }

        $orderId = $payload['order_id'] ?? null;
        if (! $orderId) {
            Log::warning('[Midtrans] order_id tidak ada di webhook');
            return null;
        }

        $payment = Payment::where('order_id', $orderId)->first();
        if (! $payment) {
            Log::warning('[Midtrans] Payment tidak ditemukan', ['order_id' => $orderId]);
            return null;
        }

        // Jangan proses ulang jika sudah settlement/capture
        if ($payment->isPaid()) {
            Log::info('[Midtrans] Payment sudah terbayar, skip', ['order_id' => $orderId]);
            return $payment;
        }

        $newStatus   = $this->mapStatus($payload);
        $paymentType = $payload['payment_type'] ?? null;

        $updateData = [
            'status'           => $newStatus,
            'payment_type'     => $paymentType,
            'webhook_payload'  => $payload,
        ];

        // Ambil detail VA / QR Code sesuai metode bayar
        $updateData = array_merge($updateData, $this->extractPaymentDetail($payload, $paymentType));

        // Tandai waktu bayar jika berhasil
        if (in_array($newStatus, ['settlement', 'capture'])) {
            $updateData['paid_at'] = now();
        }

        $payment->update($updateData);

        Log::info('[Midtrans] Status payment diupdate', [
            'order_id'  => $orderId,
            'old_status' => $payment->getOriginal('status'),
            'new_status' => $newStatus,
        ]);

        // Trigger event jika payment berhasil
        if ($payment->isPaid()) {
            $this->onPaymentSuccess($payment);
        }

        return $payment->fresh();
    }

    // ─────────────────────────────────────────────────────────
    // 3. CEK STATUS (Manual check via API Midtrans)
    // ─────────────────────────────────────────────────────────

    /**
     * Cek status transaksi langsung ke API Midtrans.
     * Berguna untuk polling atau fallback jika webhook tidak masuk.
     */
    public function checkStatus(string $orderId): array
    {
        $url = "{$this->apiUrl}/{$orderId}/status";

        $response = $this->callApi('GET', $url);

        Log::info('[Midtrans] Status check', [
            'order_id'            => $orderId,
            'transaction_status'  => $response['transaction_status'] ?? 'unknown',
        ]);

        return $response;
    }

    // ─────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────

    /**
     * Panggil Snap API untuk mendapatkan token
     */
    private function callSnapApi(array $params): array
    {
        $response = $this->callApi('POST', $this->snapUrl, $params);

        if (empty($response['token'])) {
            throw new \Exception('[Midtrans] Gagal mendapatkan snap token: ' . json_encode($response));
        }

        return $response;
    }

    /**
     * HTTP client ke Midtrans API
     */
    private function callApi(string $method, string $url, array $data = []): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($this->serverKey . ':'),
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('[Midtrans] cURL Error: ' . $error);
        }

        $decoded = json_decode($result, true);

        if ($httpCode >= 400) {
            Log::error('[Midtrans] API Error', [
                'http_code' => $httpCode,
                'response'  => $decoded,
            ]);
            throw new \Exception('[Midtrans] API Error HTTP ' . $httpCode . ': ' . $result);
        }

        return $decoded ?? [];
    }

    /**
     * Verifikasi signature dari Midtrans webhook
     * Formula: SHA512(order_id + status_code + gross_amount + server_key)
     */
    private function verifySignature(array $payload): bool
    {
        $orderId     = $payload['order_id'] ?? '';
        $statusCode  = $payload['status_code'] ?? '';
        $grossAmount = $payload['gross_amount'] ?? '';

        $signature = hash('sha512', $orderId . $statusCode . $grossAmount . $this->serverKey);

        return $signature === ($payload['signature_key'] ?? '');
    }

    /**
     * Map status Midtrans ke status internal
     */
    private function mapStatus(array $payload): string
    {
        $transactionStatus = $payload['transaction_status'] ?? '';
        $fraudStatus       = $payload['fraud_status'] ?? '';

        return match (true) {
            $transactionStatus === 'capture' && $fraudStatus === 'accept' => 'capture',
            $transactionStatus === 'capture' && $fraudStatus === 'challenge' => 'pending',
            $transactionStatus === 'settlement' => 'settlement',
            $transactionStatus === 'deny'       => 'deny',
            $transactionStatus === 'cancel'     => 'cancel',
            $transactionStatus === 'expire'     => 'expire',
            $transactionStatus === 'failure'    => 'failure',
            default                             => 'pending',
        };
    }

    /**
     * Ekstrak detail pembayaran (VA number, QR code, dll)
     */
    private function extractPaymentDetail(array $payload, ?string $paymentType): array
    {
        $detail = [];

        switch ($paymentType) {
            case 'bank_transfer':
                $vaNumbers = $payload['va_numbers'] ?? [];
                if (! empty($vaNumbers[0])) {
                    $detail['bank']      = $vaNumbers[0]['bank'] ?? null;
                    $detail['va_number'] = $vaNumbers[0]['va_number'] ?? null;
                }
                // Mandiri Bill
                if (! empty($payload['biller_code'])) {
                    $detail['bank']      = 'mandiri';
                    $detail['va_number'] = $payload['biller_code'] . $payload['bill_key'];
                }
                break;

            case 'gopay':
                $actions = $payload['actions'] ?? [];
                foreach ($actions as $action) {
                    if ($action['name'] === 'generate-qr-code') {
                        $detail['qr_code_url'] = $action['url'];
                    }
                    if ($action['name'] === 'deeplink-redirect') {
                        $detail['deeplink_url'] = $action['url'];
                    }
                }
                break;

            case 'qris':
                $detail['qr_code_url'] = $payload['qr_code_url'] ?? null;
                break;
        }

        return $detail;
    }

    /**
     * Aksi setelah payment berhasil:
     * Update status transaksi, kirim notifikasi Telegram, dll
     */
    private function onPaymentSuccess(Payment $payment): void
    {
        // Update status di tabel transactions lama
        if ($payment->transaction) {
            $payment->transaction->update(['status' => 'paid']);
        }

        // Dispatch job untuk kirim notifikasi (Telegram, email, dll)
        // Uncomment setelah job dibuat:
        // SendPaymentNotification::dispatch($payment);

        Log::info('[Midtrans] ✅ Payment BERHASIL', [
            'order_id'     => $payment->order_id,
            'amount'       => $payment->gross_amount,
            'payment_type' => $payment->payment_type,
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // GETTER untuk Frontend
    // ─────────────────────────────────────────────────────────

    public function getClientKey(): string
    {
        return $this->clientKey;
    }

    public function isProduction(): bool
    {
        return $this->isProduction;
    }

    public function getSnapJsUrl(): string
    {
        return $this->isProduction
            ? 'https://app.midtrans.com/snap/snap.js'
            : 'https://app.sandbox.midtrans.com/snap/snap.js';
    }
}