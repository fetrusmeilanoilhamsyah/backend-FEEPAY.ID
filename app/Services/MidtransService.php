<?php

namespace App\Services;

use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Notification;
use Exception;
use Illuminate\Support\Facades\Log;

class MidtransService
{
    public function __construct()
    {
        // Set konfigurasi Midtrans
        Config::$serverKey = config('services.midtrans.server_key');
        Config::$isProduction = config('services.midtrans.is_production');
        Config::$isSanitized = config('services.midtrans.is_sanitized');
        Config::$is3ds = config('services.midtrans.is_3ds');
    }

    /**
     * Create Snap Token untuk pembayaran
     * SECURITY: Amount diambil dari database, bukan dari user input
     *
     * @param string $orderId
     * @param int $amount - Harga dari database (dalam Rupiah)
     * @param string $customerEmail
     * @param string $productName
     * @return string Snap Token
     * @throws Exception
     */
    public function createSnapToken(
        string $orderId,
        int $amount,
        string $customerEmail,
        string $productName
    ): string {
        try {
            // Validasi amount harus positif
            if ($amount <= 0) {
                throw new Exception("Invalid amount: must be greater than 0");
            }

            // Parameter untuk Midtrans Snap
            $params = [
                'transaction_details' => [
                    'order_id' => $orderId,
                    'gross_amount' => $amount, // Amount dari database
                ],
                'item_details' => [
                    [
                        'id' => $orderId,
                        'price' => $amount,
                        'quantity' => 1,
                        'name' => $productName,
                    ]
                ],
                'customer_details' => [
                    'email' => $customerEmail,
                ],
                'enabled_payments' => [
                    'credit_card',
                    'bca_va',
                    'bni_va',
                    'bri_va',
                    'permata_va',
                    'other_va',
                    'gopay',
                    'shopeepay',
                    'qris',
                ],
                'callbacks' => [
                    'finish' => config('app.url') . '/payment/finish',
                ],
                'expiry' => [
                    'start_time' => date('Y-m-d H:i:s O'),
                    'unit' => 'minutes',
                    'duration' => 60, // 1 jam
                ],
            ];

            // Generate Snap Token
            $snapToken = Snap::getSnapToken($params);

            Log::info('Snap token created', [
                'order_id' => $orderId,
                'amount' => $amount,
            ]);

            return $snapToken;

        } catch (Exception $e) {
            Log::error('Failed to create snap token', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            throw new Exception('Failed to create payment token: ' . $e->getMessage());
        }
    }

    /**
     * Verify Signature Key dari Midtrans Notification
     * SECURITY: Mencegah webhook palsu dengan validasi signature
     *
     * @param array $notificationData
     * @return bool
     */
    public function verifySignature(array $notificationData): bool
    {
        try {
            $orderId = $notificationData['order_id'] ?? null;
            $statusCode = $notificationData['status_code'] ?? null;
            $grossAmount = $notificationData['gross_amount'] ?? null;
            $serverKey = config('services.midtrans.server_key');
            $receivedSignature = $notificationData['signature_key'] ?? null;

            if (!$orderId || !$statusCode || !$grossAmount || !$receivedSignature) {
                Log::warning('Missing required fields for signature verification', [
                    'notification_data' => $notificationData,
                ]);
                return false;
            }

            // Generate expected signature
            $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

            // Compare signatures
            $isValid = hash_equals($expectedSignature, $receivedSignature);

            if (!$isValid) {
                Log::warning('Invalid signature detected', [
                    'order_id' => $orderId,
                    'expected' => $expectedSignature,
                    'received' => $receivedSignature,
                ]);
            }

            return $isValid;

        } catch (Exception $e) {
            Log::error('Signature verification failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get notification data dari Midtrans
     *
     * @return Notification
     */
    public function getNotification(): Notification
    {
        return new Notification();
    }

    /**
     * Map Midtrans transaction status ke internal order status
     *
     * @param string $transactionStatus
     * @param string $fraudStatus
     * @return string
     */
    public function mapTransactionStatus(string $transactionStatus, string $fraudStatus = 'accept'): string
    {
        // Status Midtrans: capture, settlement, pending, deny, expire, cancel
        if ($transactionStatus == 'capture') {
            if ($fraudStatus == 'accept') {
                return 'processing'; // Payment captured & verified
            }
            return 'pending'; // Fraud detection pending
        }

        if ($transactionStatus == 'settlement') {
            return 'processing'; // Payment sukses, siap diproses ke Digiflazz
        }

        if ($transactionStatus == 'pending') {
            return 'pending'; // Waiting for payment
        }

        if (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
            return 'failed'; // Payment failed
        }

        return 'pending'; // Default
    }
}