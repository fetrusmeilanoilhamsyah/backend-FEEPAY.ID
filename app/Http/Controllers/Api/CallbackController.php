<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Enums\OrderStatus;
use App\Mail\OrderSuccess;
use App\Mail\OrderFailed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Exception;

class CallbackController extends Controller
{
    /**
     * Handle Digiflazz Callback
     * 
     * PENJELASAN:
     * - Digiflazz akan POST ke endpoint ini setiap kali ada perubahan status order
     * - Ada 3 kemungkinan status dari Digiflazz: Sukses, Gagal, Pending
     * - Kita WAJIB verifikasi signature sebelum proses apapun (keamanan!)
     * - Kalau sukses -> update status + kirim email ke customer
     * - Kalau gagal -> update status + log error untuk debugging
     * 
     * CARA SETUP WEBHOOK DI DIGIFLAZZ:
     * - Login ke dashboard Digiflazz
     * - Masuk ke menu "API"
     * - Set callback URL ke: https://yourdomain.com/api/callback/digiflazz
     * - Saat testing pakai ngrok: https://xxxx.ngrok.io/api/callback/digiflazz
     * 
     * POST /api/callback/digiflazz
     */
    public function digiflazz(Request $request)
    {
        try {
            // Log semua incoming callback untuk debugging
            // PENTING: Jangan hapus log ini, sangat berguna saat troubleshoot
            Log::info("Digiflazz callback received", $request->all());

            // ============================================
            // STEP 1: VERIFIKASI SIGNATURE
            // Ini langkah KEAMANAN paling penting!
            // Tujuan: Pastikan request beneran dari Digiflazz, bukan dari orang iseng
            // Cara kerja: MD5 dari username + api_key + ref_id
            // ============================================
            $username = config("services.digiflazz.username");
            $apiKey   = config("services.digiflazz.api_key");
            $refId    = $request->input("data.ref_id");

            // Generate signature yang kita harapkan
            $expectedSign = md5($username . $apiKey . $refId);

            // Signature yang dikirim Digiflazz
            $receivedSign = $request->input("sign");

            // Kalau signature tidak cocok -> TOLAK request
            if ($expectedSign !== $receivedSign) {
                Log::warning("Invalid Digiflazz callback signature - Possible security threat!", [
                    "expected" => $expectedSign,
                    "received" => $receivedSign,
                    "ref_id"   => $refId,
                    "ip"       => $request->ip(),
                ]);

                return response()->json([
                    "success" => false,
                    "message" => "Invalid signature",
                ], 401);
            }

            // ============================================
            // STEP 2: CARI ORDER DI DATABASE
            // ref_id dari Digiflazz = order_id kita
            // ============================================
            $order = Order::where("order_id", $refId)->first();

            if (!$order) {
                Log::warning("Order not found in Digiflazz callback", [
                    "ref_id" => $refId,
                ]);

                return response()->json([
                    "success" => false,
                    "message" => "Order not found",
                ], 404);
            }

            // ============================================
            // STEP 3: AMBIL DATA CALLBACK
            // Digiflazz kirim data dalam key "data"
            // Status bisa: "Sukses", "Gagal", "Pending"
            // SN = Serial Number produk (voucher code, token listrik, dll)
            // ============================================
            $status  = $request->input("data.status");
            $sn      = $request->input("data.sn");
            $message = $request->input("data.message");

            // ============================================
            // STEP 4: HANDLE STATUS SUKSES
            // Kalau Digiflazz bilang sukses:
            // 1. Update status order jadi SUCCESS
            // 2. Simpan SN (serial number) produk
            // 3. Log perubahan status
            // 4. Kirim email sukses ke customer
            // ============================================
            if ($status === "Sukses") {
                $order->update([
                    "status" => OrderStatus::SUCCESS->value,
                    "sn"     => $sn, // Serial number dari Digiflazz
                ]);

                $order->logStatusChange(
                    OrderStatus::SUCCESS,
                    "Order completed via Digiflazz callback. SN: " . $sn
                );

                Log::info("Order marked as success via Digiflazz callback", [
                    "order_id" => $order->order_id,
                    "sn"       => $sn,
                ]);

                // Kirim email sukses ke customer
                // Email berisi: order detail + serial number produk
                // Template ada di: resources/views/emails/order-success.blade.php
                $this->sendSuccessEmail($order);

            // ============================================
            // STEP 5: HANDLE STATUS GAGAL
            // Kalau Digiflazz bilang gagal:
            // 1. Update status order jadi FAILED
            // 2. Log error detail untuk debugging
            // 3. Kirim email gagal ke customer (pesan diterjemahkan jadi user-friendly)
            // CATATAN: Kalau saldo habis, Digiflazz juga return Gagal
            // ============================================
            } elseif ($status === "Gagal") {
                $order->update([
                    "status" => OrderStatus::FAILED->value,
                ]);

                $order->logStatusChange(
                    OrderStatus::FAILED,
                    "Order failed via Digiflazz callback. Reason: " . $message
                );

                Log::warning("Order marked as failed via Digiflazz callback", [
                    "order_id" => $order->order_id,
                    "message"  => $message,
                    "note"     => "Possible causes: insufficient balance, invalid SKU, provider error",
                ]);

                // Kirim email gagal ke customer
                // Raw reason dari Digiflazz akan diterjemahkan ke bahasa yang lebih friendly
                // Template ada di: resources/views/emails/order-failed.blade.php
                $this->sendFailedEmail($order, $message);

            // ============================================
            // STEP 6: HANDLE STATUS PENDING
            // Kalau masih pending, kita log aja
            // Status di database tetap "processing" (gak diubah)
            // Digiflazz akan kirim callback lagi nanti
            // ============================================
            } else {
                Log::info("Digiflazz callback with non-final status", [
                    "order_id" => $order->order_id,
                    "status"   => $status,
                    "message"  => $message,
                ]);
            }

            // Selalu return 200 ke Digiflazz
            // Kalau kita return error, Digiflazz akan retry terus menerus
            return response()->json([
                "success" => true,
                "message" => "Callback processed",
            ], 200);

        } catch (Exception $e) {
            Log::error("Digiflazz callback processing failed", [
                "error"   => $e->getMessage(),
                "trace"   => $e->getTraceAsString(),
                "payload" => $request->all(),
            ]);

            // Tetap return 200 supaya Digiflazz gak retry
            // Tapi log error biar kita tau ada masalah
            return response()->json([
                "success" => false,
                "message" => "Callback processing failed",
            ], 500);
        }
    }

    /**
     * Send Success Email to Customer
     * 
     * PENJELASAN:
     * - Dipanggil hanya saat order berhasil (status Sukses dari Digiflazz)
     * - Email berisi: order ID, nama produk, nomor tujuan, total harga, SN
     * - Template email: resources/views/emails/order-success.blade.php
     * - Error pengiriman email TIDAK menggagalkan order (try-catch terpisah)
     *   Kenapa? Karena order sudah sukses, email cuma notifikasi tambahan
     * - Error email dicatat di log untuk debugging
     * 
     * @param Order $order
     * @return void
     */
    private function sendSuccessEmail(Order $order): void
    {
        try {
            // Cari data produk berdasarkan SKU
            // Dibutuhkan untuk template email (nama produk, harga, dll)
            $product = Product::where("sku", $order->sku)->first();

            // Kalau produk gak ketemu, buat dummy product object
            // Supaya email tetap bisa dikirim walau produk dihapus
            if (!$product) {
                Log::warning("Product not found for email, using order data", [
                    "order_id" => $order->order_id,
                    "sku"      => $order->sku,
                ]);

                // Buat mock product dari data order
                $product = new \App\Models\Product([
                    "name"          => $order->product_name,
                    "sku"           => $order->sku,
                    "selling_price" => $order->total_price,
                ]);
            }

            // Kirim email menggunakan OrderSuccess Mailable
            // Config email ada di .env: MAIL_HOST, MAIL_PORT, MAIL_USERNAME, dll
            Mail::to($order->customer_email)
                ->send(new OrderSuccess($order, $product));

            Log::info("Success email sent to customer", [
                "order_id" => $order->order_id,
                "email"    => $order->customer_email,
                "sn"       => $order->sn,
            ]);

        } catch (Exception $mailException) {
            // PENTING: Error email TIDAK throw exception ke parent
            // Artinya: kalau email gagal kirim, order tetap SUCCESS
            // Kita cuma log error-nya untuk debugging manual
            Log::error("Failed to send success email", [
                "order_id" => $order->order_id,
                "email"    => $order->customer_email,
                "error"    => $mailException->getMessage(),
                "note"     => "Order status is still SUCCESS. Only email failed.",
            ]);
        }
    }

    /**
     * Send Failed Email to Customer
     * 
     * PENJELASAN:
     * - Dipanggil hanya saat order gagal (status Gagal dari Digiflazz)
     * - Raw reason dari Digiflazz diterjemahkan dulu ke bahasa yang user-friendly
     * - Template email: resources/views/emails/order-failed.blade.php
     * - Error pengiriman email TIDAK mengubah status order (try-catch terpisah)
     *   Kenapa? Karena order sudah FAILED, email cuma notifikasi tambahan
     * - Error email dicatat di log untuk debugging
     * 
     * @param Order $order
     * @param string|null $rawReason  Pesan error mentah dari Digiflazz
     * @return void
     */
    private function sendFailedEmail(Order $order, ?string $rawReason = null): void
    {
        try {
            // Terjemahkan pesan error Digiflazz ke bahasa yang lebih customer-friendly
            // Supaya customer gak bingung baca pesan teknikal dari provider
            $userFriendlyReason = $this->translateFailedReason($rawReason);

            // Kirim email menggunakan OrderFailed Mailable
            // Config email ada di .env: MAIL_HOST, MAIL_PORT, MAIL_USERNAME, dll
            Mail::to($order->customer_email)
                ->send(new OrderFailed($order, $userFriendlyReason));

            Log::info("Failed email sent to customer", [
                "order_id"          => $order->order_id,
                "email"             => $order->customer_email,
                "raw_reason"        => $rawReason,
                "translated_reason" => $userFriendlyReason,
            ]);

        } catch (Exception $mailException) {
            // PENTING: Error email TIDAK throw exception ke parent
            // Artinya: kalau email gagal kirim, order tetap FAILED
            // Kita cuma log error-nya untuk debugging manual
            Log::error("Failed to send failed email", [
                "order_id" => $order->order_id,
                "email"    => $order->customer_email,
                "error"    => $mailException->getMessage(),
                "note"     => "Order status is still FAILED. Only email failed.",
            ]);
        }
    }

    /**
     * Translate Raw Digiflazz Error Reason to User-Friendly Message
     * 
     * PENJELASAN:
     * - Pesan error dari Digiflazz kadang teknikal dan gak ramah untuk customer
     * - Method ini menerjemahkan ke bahasa Indonesia yang lebih mudah dipahami
     * - Pengecekan pakai keyword matching (str_contains) biar fleksibel
     * - Kalau tidak ada keyword yang cocok, fallback ke pesan generic
     * - Pesan asli dari Digiflazz tetap disimpan di log untuk debugging
     * 
     * @param string|null $reason  Pesan error mentah dari Digiflazz
     * @return string               Pesan yang sudah diterjemahkan
     */
    private function translateFailedReason(?string $reason): string
    {
        if (!$reason) {
            return "Terjadi kesalahan saat memproses pesanan Anda.";
        }

        $reason = strtolower($reason);

        // Saldo Digiflazz habis / insufficient balance
        if (str_contains($reason, "saldo") || str_contains($reason, "balance") || str_contains($reason, "insufficient")) {
            return "Layanan sedang tidak tersedia untuk sementara. Silakan coba lagi nanti atau hubungi Customer Service.";
        }

        // Nomor tujuan tidak valid
        if (str_contains($reason, "nomor") || str_contains($reason, "number") || str_contains($reason, "destination")) {
            return "Nomor tujuan tidak valid. Pastikan nomor yang Anda masukkan sudah benar.";
        }

        // SKU / produk tidak valid atau tidak tersedia
        if (str_contains($reason, "sku") || str_contains($reason, "produk") || str_contains($reason, "product")) {
            return "Produk yang Anda pesan sedang tidak tersedia. Silakan pilih produk lain.";
        }

        // Timeout atau server error provider
        if (str_contains($reason, "timeout") || str_contains($reason, "server") || str_contains($reason, "connection")) {
            return "Koneksi ke server provider terputus. Silakan coba lagi dalam beberapa menit.";
        }

        // Fallback: pesan generic
        return "Pesanan gagal diproses. Silakan coba lagi atau hubungi Customer Service kami.";
    }
}