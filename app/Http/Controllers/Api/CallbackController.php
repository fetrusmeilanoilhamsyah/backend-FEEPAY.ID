<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendOrderSuccessEmail;
use App\Mail\OrderFailed;
use App\Models\Order;
use App\Models\Product;
use App\Services\TelegramService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CallbackController extends Controller
{
    /**
     * POST /api/callback/digiflazz
     * Endpoint ini dipanggil oleh server Digiflazz, bukan oleh user.
     */
    public function digiflazz(Request $request): JsonResponse
    {
        Log::info('Digiflazz callback diterima', $request->all());

        try {
            // ── Langkah 1: Verifikasi signature ──────────────────────────────
            $username     = config('services.digiflazz.username');
            $apiKey       = config('services.digiflazz.api_key');
            $refId        = $request->input('data.ref_id');
            $expectedSign = md5($username . $apiKey . $refId);
            $receivedSign = $request->input('sign');

            if (!hash_equals($expectedSign, $receivedSign)) {
                Log::warning('Digiflazz callback: signature tidak valid', [
                    'ref_id' => $refId,
                    'ip'     => $request->ip(),
                ]);
                return response()->json(['success' => false, 'message' => 'Signature tidak valid.'], 401);
            }

            // ── Langkah 2: Cari order ─────────────────────────────────────────
            $order = Order::where('order_id', $refId)->first();

            if (!$order) {
                Log::warning('Digiflazz callback: order tidak ditemukan', ['ref_id' => $refId]);
                return response()->json(['success' => false, 'message' => 'Order tidak ditemukan.'], 404);
            }

            // Jika status sudah final, abaikan callback duplikat
            if ($order->status->isFinal()) {
                Log::info('Digiflazz callback: status sudah final, diabaikan', [
                    'order_id' => $order->order_id,
                    'status'   => $order->status->value,
                ]);
                return response()->json(['success' => true, 'message' => 'Status sudah final.'], 200);
            }

            // ── Langkah 3: Proses status ──────────────────────────────────────
            $status  = $request->input('data.status');
            $sn      = $request->input('data.sn');
            $message = $request->input('data.message');

            DB::beginTransaction();

            if ($status === 'Sukses') {
                $order->update([
                    'status' => OrderStatus::SUCCESS->value,
                    'sn'     => $sn,
                ]);
                $order->logStatusChange(OrderStatus::SUCCESS, 'Sukses via callback Digiflazz. SN: ' . $sn);

                DB::commit();

                Log::info('Order sukses via callback', ['order_id' => $order->order_id, 'sn' => $sn]);
                $this->notifyTelegram($order, 'SUKSES', $sn);
                $this->dispatchSuccessEmail($order);

            } elseif ($status === 'Gagal') {
                $order->update(['status' => OrderStatus::FAILED->value]);
                $order->logStatusChange(OrderStatus::FAILED, 'Gagal via callback Digiflazz. Alasan: ' . $message);

                DB::commit();

                Log::warning('Order gagal via callback', [
                    'order_id' => $order->order_id,
                    'message'  => $message,
                ]);
                $this->notifyTelegram($order, 'GAGAL', null, $message);
                $this->sendFailedEmail($order, $message);

            } else {
                DB::rollBack();
                Log::info('Digiflazz callback: status non-final', [
                    'order_id' => $order->order_id,
                    'status'   => $status,
                ]);
            }

            // Selalu balas 200 ke Digiflazz agar tidak retry terus
            return response()->json(['success' => true, 'message' => 'Callback diproses.'], 200);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Digiflazz callback exception', [
                'error'   => $e->getMessage(),
                'payload' => $request->all(),
            ]);

            // Tetap 200 agar Digiflazz tidak spam retry
            return response()->json(['success' => false, 'message' => 'Callback gagal diproses.'], 200);
        }
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private function notifyTelegram(Order $order, string $status, ?string $sn = null, ?string $reason = null): void
    {
        $emoji   = $status === 'SUKSES' ? '✅' : '❌';
        $nominal = number_format($order->total_price, 0, ',', '.');

        $pesan = "*NOTIFIKASI TRANSAKSI FEEPAY* {$emoji}\n" .
                 "----------------------------------\n" .
                 "*Status:* {$status}\n" .
                 "*Produk:* {$order->product_name}\n" .
                 "*Nominal:* Rp {$nominal}\n" .
                 "*Pembeli:* {$order->customer_email}\n" .
                 "*Order ID:* #{$order->order_id}";

        if ($sn) {
            $pesan .= "\n*SN:* `{$sn}`";
        }
        if ($reason) {
            $pesan .= "\n*Alasan:* {$reason}";
        }

        $pesan .= "\n----------------------------------\n_Laporan otomatis sistem FEEPAY.ID_";

        TelegramService::notify($pesan);
    }

    private function dispatchSuccessEmail(Order $order): void
    {
        try {
            $product = Product::where('sku', $order->sku)->first()
                ?? new Product([
                    'name'          => $order->product_name,
                    'sku'           => $order->sku,
                    'selling_price' => $order->total_price,
                ]);

            SendOrderSuccessEmail::dispatch($order, $product);

            Log::info('Email sukses di-dispatch ke queue', ['order_id' => $order->order_id]);

        } catch (Exception $e) {
            Log::error('Gagal dispatch email sukses', [
                'order_id' => $order->order_id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function sendFailedEmail(Order $order, ?string $rawReason = null): void
    {
        try {
            Mail::to($order->customer_email)->send(new OrderFailed($order, $this->translateReason($rawReason)));

            Log::info('Email gagal terkirim', ['order_id' => $order->order_id]);

        } catch (Exception $e) {
            Log::error('Gagal kirim email order gagal', [
                'order_id' => $order->order_id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function translateReason(?string $reason): string
    {
        if (!$reason) return 'Terjadi kesalahan saat memproses pesanan Anda.';

        $r = strtolower($reason);

        if (str_contains($r, 'saldo') || str_contains($r, 'balance') || str_contains($r, 'insufficient')) {
            return 'Layanan sedang tidak tersedia sementara. Silakan coba lagi nanti atau hubungi Customer Service.';
        }
        if (str_contains($r, 'nomor') || str_contains($r, 'number') || str_contains($r, 'destination')) {
            return 'Nomor tujuan tidak valid. Pastikan nomor yang Anda masukkan sudah benar.';
        }
        if (str_contains($r, 'sku') || str_contains($r, 'produk') || str_contains($r, 'product')) {
            return 'Produk yang Anda pesan sedang tidak tersedia. Silakan pilih produk lain.';
        }
        if (str_contains($r, 'timeout') || str_contains($r, 'server') || str_contains($r, 'connection')) {
            return 'Koneksi ke server provider terputus. Silakan coba lagi dalam beberapa menit.';
        }

        return 'Pesanan gagal diproses. Silakan coba lagi atau hubungi Customer Service kami.';
    }
}
