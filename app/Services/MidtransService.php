<?php

namespace App\Services;

use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Notification;
use Illuminate\Support\Facades\Log;
use Exception;

class MidtransService
{
    public function __construct()
    {
        Config::$serverKey    = config('services.midtrans.server_key');
        Config::$isProduction = config('services.midtrans.is_production');
        Config::$isSanitized  = true;
        Config::$is3ds        = true;
    }

    /**
     * Buat Snap Token untuk pembayaran.
     * SECURITY: amount diambil dari database, bukan dari user input.
     *
     * @throws Exception
     */
    public function createSnapToken(
        string $orderId,
        int $amount,
        string $customerEmail,
        string $productName
    ): string {
        if ($amount <= 0) {
            throw new Exception("Amount tidak valid: harus lebih dari 0.");
        }

        try {
            $params = [
                'transaction_details' => [
                    'order_id'     => $orderId,
                    'gross_amount' => $amount,
                ],
                'item_details' => [[
                    'id'       => $orderId,
                    'price'    => $amount,
                    'quantity' => 1,
                    'name'     => substr($productName, 0, 50), // Midtrans max 50 char
                ]],
                'customer_details' => [
                    'email' => $customerEmail,
                ],
                'callbacks' => [
                    'finish' => config('app.url') . '/payment/finish',
                ],
                'expiry' => [
                    'start_time' => date('Y-m-d H:i:s O'),
                    'unit'       => 'minutes',
                    'duration'   => 60, // 1 jam
                ],
            ];

            $snapToken = Snap::getSnapToken($params);

            Log::info('MidtransService: snap token dibuat', [
                'order_id' => $orderId,
                'amount'   => $amount,
            ]);

            return $snapToken;

        } catch (Exception $e) {
            Log::error('MidtransService: gagal buat snap token', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            throw new Exception('Gagal membuat token pembayaran: ' . $e->getMessage());
        }
    }

    /**
     * Verifikasi signature dari notifikasi Midtrans.
     * Menggunakan hash_equals() untuk mencegah timing attack.
     */
    public function verifySignature(array $notificationData): bool
    {
        try {
            $orderId           = $notificationData['order_id']       ?? null;
            $statusCode        = $notificationData['status_code']    ?? null;
            $grossAmount       = $notificationData['gross_amount']   ?? null;
            $receivedSignature = $notificationData['signature_key']  ?? null;
            $serverKey         = config('services.midtrans.server_key');

            if (!$orderId || !$statusCode || !$grossAmount || !$receivedSignature) {
                Log::warning('MidtransService: field signature tidak lengkap', $notificationData);
                return false;
            }

            $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
            $isValid           = hash_equals($expectedSignature, $receivedSignature);

            if (!$isValid) {
                Log::warning('MidtransService: signature tidak valid', [
                    'order_id' => $orderId,
                ]);
            }

            return $isValid;

        } catch (Exception $e) {
            Log::error('MidtransService: verifySignature exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Ambil objek Notification dari Midtrans.
     */
    public function getNotification(): Notification
    {
        return new Notification();
    }
}
