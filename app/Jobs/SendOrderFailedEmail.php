<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendOrderFailedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 60;
    public $backoff = [30, 180];

    protected Order $order;

    /**
     * Create a new job instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $order = $this->order->load(['user', 'product']);

            // Kirim email ke customer
            Mail::send('emails.order-failed', [
                'order' => $order,
                'user' => $order->user,
                'product' => $order->product,
            ], function ($message) use ($order) {
                $message->to($order->user->email, $order->user->name)
                    ->subject('Order Gagal - ' . $order->trx_id . ' (Saldo Dikembalikan)');
            });

            Log::info('Failed email sent', [
                'order_id' => $order->id,
                'trx_id' => $order->trx_id,
                'email' => $order->user->email
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send failure email', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Failed email job failed completely', [
            'order_id' => $this->order->id,
            'error' => $exception->getMessage()
        ]);
    }
}