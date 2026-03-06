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

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public Order   $order,
        public Product $product
    ) {}

    public function handle(): void
    {
        try {
            Mail::to($this->order->customer_email)
                ->send(new OrderSuccess($this->order, $this->product));

            Log::info('Email sukses terkirim via queue', [
                'order_id' => $this->order->order_id,
                'email'    => $this->order->customer_email,
            ]);

        } catch (\Exception $e) {
            Log::error('Gagal kirim email sukses via queue', [
                'order_id' => $this->order->order_id,
                'error'    => $e->getMessage(),
            ]);
            throw $e; // Trigger retry
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Email sukses gagal permanen setelah semua retry', [
            'order_id' => $this->order->order_id,
            'error'    => $exception->getMessage(),
        ]);
    }
}
