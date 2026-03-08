<?php

namespace App\Jobs;

use App\Mail\OrderFailed;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderFailedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 30;
    public $backoff = [60, 300, 900];

    public function __construct(
        public Order $order,
        public string $reason
    ) {}

    public function handle(): void
    {
        try {
            Mail::to($this->order->customer_email)->send(new OrderFailed($this->order, $this->reason));
            
            Log::info('Email gagal terkirim (via queue)', [
                'order_id' => $this->order->order_id,
                'email'    => $this->order->customer_email,
            ]);
        } catch (\Exception $e) {
            Log::error('Gagal kirim email order gagal (via queue)', [
                'order_id' => $this->order->order_id,
                'error'    => $e->getMessage(),
            ]);
            
            $this->fail($e);
        }
    }
}