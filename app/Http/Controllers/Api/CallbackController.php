<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Enums\OrderStatus;
use App\Jobs\SendOrderSuccessEmail;
use App\Mail\OrderFailed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Exception;

class CallbackController extends Controller
{
    /**
     * Handle Digiflazz Callback
     * POST /api/callback/digiflazz
     */
    public function digiflazz(Request $request)
    {
        try {
            Log::info("Digiflazz callback received", $request->all());

            // ============================================
            // STEP 1: VERIFIKASI SIGNATURE
            // ============================================
            $username     = config("services.digiflazz.username");
            $apiKey       = config("services.digiflazz.api_key");
            $refId        = $request->input("data.ref_id");
            $expectedSign = md5($username . $apiKey . $refId);
            $receivedSign = $request->input("sign");

            if ($expectedSign !== $receivedSign) {
                Log::warning("Invalid Digiflazz callback signature", [
                    "expected" => $expectedSign,
                    "received" => $receivedSign,
                    "ref_id"   => $refId,
                    "ip"       => $request->ip(),
                ]);
                return response()->json(['success' => false, 'message' => 'Invalid signature'], 401);
            }

            // ============================================
            // STEP 2: CARI ORDER
            // ============================================
            $order = Order::where("order_id", $refId)->first();

            if (!$order) {
                Log::warning("Order not found in Digiflazz callback", ["ref_id" => $refId]);
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            // ============================================
            // STEP 3: AMBIL DATA CALLBACK
            // ============================================
            $status  = $request->input("data.status");
            $sn      = $request->input("data.sn");
            $message = $request->input("data.message");

            // ============================================
            // STEP 4: HANDLE STATUS SUKSES
            // ✅ FIX B2: Ganti Mail::send() langsung ke dispatch(job)
            // Alasan: Mail::send() sync bisa timeout → Digiflazz retry berkali-kali
            // SendOrderSuccessEmail job: tries=3, timeout=60, implements ShouldQueue
            // ============================================
            if ($status === "Sukses") {
                $order->update([
                    "status" => OrderStatus::SUCCESS->value,
                    "sn"     => $sn,
                ]);

                $order->logStatusChange(
                    OrderStatus::SUCCESS,
                    "Order completed via Digiflazz callback. SN: " . $sn
                );

                Log::info("Order marked as success via Digiflazz callback", [
                    "order_id" => $order->order_id,
                    "sn"       => $sn,
                ]);

                // ✅ FIX B2: Dispatch ke queue, bukan Mail::send() langsung
                // Job akan retry 3x kalau gagal (Brevo limit, timeout, dll)
                $this->dispatchSuccessEmail($order);

            // ============================================
            // STEP 5: HANDLE STATUS GAGAL
            // ============================================
            } elseif ($status === "Gagal") {
                $order->update(["status" => OrderStatus::FAILED->value]);

                $order->logStatusChange(
                    OrderStatus::FAILED,
                    "Order failed via Digiflazz callback. Reason: " . $message
                );

                Log::warning("Order marked as failed via Digiflazz callback", [
                    "order_id" => $order->order_id,
                    "message"  => $message,
                ]);

                // Email gagal tetap sync karena lebih ringan dan tidak blocking
                $this->sendFailedEmail($order, $message);

            // ============================================
            // STEP 6: HANDLE STATUS PENDING
            // ============================================
            } else {
                Log::info("Digiflazz callback with non-final status", [
                    "order_id" => $order->order_id,
                    "status"   => $status,
                    "message"  => $message,
                ]);
            }

            return response()->json(['success' => true, 'message' => 'Callback processed'], 200);

        } catch (Exception $e) {
            Log::error("Digiflazz callback processing failed", [
                "error"   => $e->getMessage(),
                "trace"   => $e->getTraceAsString(),
                "payload" => $request->all(),
            ]);

            // Tetap return 200 supaya Digiflazz tidak retry
            return response()->json(['success' => false, 'message' => 'Callback processing failed'], 200);
        }
    }

    /**
     * ✅ FIX B2: Dispatch email sukses ke queue menggunakan job yang sudah ada
     *
     * Sebelumnya: Mail::to()->send() — sync, bisa timeout, tidak ada retry
     * Sekarang:   SendOrderSuccessEmail::dispatch() — async, retry 3x, tidak blocking
     *
     * Pastikan queue worker berjalan: php artisan queue:work
     * Atau pakai supervisor di production untuk auto-restart worker
     */
    private function dispatchSuccessEmail(Order $order): void
    {
        try {
            $product = Product::where("sku", $order->sku)->first();

            // Kalau produk sudah dihapus, buat mock dari data order
            if (!$product) {
                Log::warning("Product not found for email, using order data", [
                    "order_id" => $order->order_id,
                    "sku"      => $order->sku,
                ]);
                $product = new \App\Models\Product([
                    "name"          => $order->product_name,
                    "sku"           => $order->sku,
                    "selling_price" => $order->total_price,
                ]);
            }

            // ✅ Dispatch ke queue — tidak blocking, retry 3x kalau gagal
            SendOrderSuccessEmail::dispatch($order, $product);

            Log::info("Success email dispatched to queue", [
                "order_id" => $order->order_id,
                "email"    => $order->customer_email,
                "sn"       => $order->sn,
            ]);

        } catch (Exception $e) {
            // Kalau dispatch gagal (queue tidak jalan), fallback ke sync
            Log::error("Failed to dispatch success email to queue, falling back to sync", [
                "order_id" => $order->order_id,
                "error"    => $e->getMessage(),
            ]);

            // Fallback: kirim langsung tapi wrapped try-catch
            try {
                $product = $product ?? new \App\Models\Product([
                    "name"          => $order->product_name,
                    "sku"           => $order->sku,
                    "selling_price" => $order->total_price,
                ]);
                Mail::to($order->customer_email)
                    ->send(new \App\Mail\OrderSuccess($order, $product));
            } catch (Exception $mailEx) {
                Log::error("Sync fallback email also failed", [
                    "order_id" => $order->order_id,
                    "error"    => $mailEx->getMessage(),
                    "note"     => "Order status is still SUCCESS. Only email failed.",
                ]);
            }
        }
    }

    /**
     * Send Failed Email — tetap sync karena ringan dan jarang terjadi
     */
    private function sendFailedEmail(Order $order, ?string $rawReason = null): void
    {
        try {
            $userFriendlyReason = $this->translateFailedReason($rawReason);
            Mail::to($order->customer_email)
                ->send(new OrderFailed($order, $userFriendlyReason));

            Log::info("Failed email sent to customer", [
                "order_id" => $order->order_id,
                "email"    => $order->customer_email,
            ]);
        } catch (Exception $mailException) {
            Log::error("Failed to send failed email", [
                "order_id" => $order->order_id,
                "error"    => $mailException->getMessage(),
                "note"     => "Order status is still FAILED. Only email failed.",
            ]);
        }
    }

    private function translateFailedReason(?string $reason): string
    {
        if (!$reason) return 'Terjadi kesalahan saat memproses pesanan Anda.';

        $reason = strtolower($reason);

        if (str_contains($reason, 'saldo') || str_contains($reason, 'balance') || str_contains($reason, 'insufficient')) {
            return 'Layanan sedang tidak tersedia untuk sementara. Silakan coba lagi nanti atau hubungi Customer Service.';
        }
        if (str_contains($reason, 'nomor') || str_contains($reason, 'number') || str_contains($reason, 'destination')) {
            return 'Nomor tujuan tidak valid. Pastikan nomor yang Anda masukkan sudah benar.';
        }
        if (str_contains($reason, 'sku') || str_contains($reason, 'produk') || str_contains($reason, 'product')) {
            return 'Produk yang Anda pesan sedang tidak tersedia. Silakan pilih produk lain.';
        }
        if (str_contains($reason, 'timeout') || str_contains($reason, 'server') || str_contains($reason, 'connection')) {
            return 'Koneksi ke server provider terputus. Silakan coba lagi dalam beberapa menit.';
        }

        return 'Pesanan gagal diproses. Silakan coba lagi atau hubungi Customer Service kami.';
    }
}