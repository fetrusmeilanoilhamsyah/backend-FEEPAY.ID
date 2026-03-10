<?php

namespace App\Jobs;

use App\Mail\OrderSuccess;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderSuccessEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Coba 2x — retry 30 detik, lalu 3 menit
    public int $tries   = 2;
    public int $timeout = 60;
    public array $backoff = [30, 180];

    public function __construct(
        protected Order $order
    ) {}

    public function handle(): void
    {
        // Sistem tanpa login — tidak ada relasi user
        // Email & data diambil langsung dari order
        $email       = $this->order->customer_email;
        $productName = $this->order->product_name;

        if (empty($email)) {
            Log::error('SendOrderSuccessEmail: customer_email kosong', [
                'order_id' => $this->order->order_id,
            ]);
            return; // Jangan throw — tidak ada gunanya retry kalau email memang kosong
        }

        // Ambil product untuk data tambahan di template email
        // Kalau tidak ketemu, buat dummy dari data order agar email tetap terkirim
        $product = Product::where('sku', $this->order->sku)->first()
            ?? new Product([
                'name'          => $productName,
                'sku'           => $this->order->sku,
                'selling_price' => $this->order->total_price,
            ]);

        try {
            Mail::to($email)->send(new OrderSuccess($this->order, $product));

            Log::info('Email sukses terkirim', [
                'order_id' => $this->order->order_id,
                'email'    => $email,
                'sn'       => $this->order->sn,
            ]);

        } catch (\Exception $e) {
            Log::error('SendOrderSuccessEmail: gagal kirim', [
                'order_id' => $this->order->order_id,
                'email'    => $email,
                'attempt'  => $this->attempts(),
                'error'    => $e->getMessage(),
            ]);

            // Throw agar queue retry sesuai $backoff
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('SendOrderSuccessEmail: semua retry gagal', [
            'order_id' => $this->order->order_id,
            'email'    => $this->order->customer_email,
            'error'    => $exception->getMessage(),
        ]);
    }
}