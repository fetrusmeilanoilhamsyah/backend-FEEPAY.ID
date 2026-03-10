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

class SendOrderSuccessEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 60;
    public $backoff = [30, 180]; // 30 detik, 3 menit

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
            Mail::send('emails.order-success', [
                'order' => $order,
                'user' => $order->user,
                'product' => $order->product,
            ], function ($message) use ($order) {
                $message->to($order->user->email, $order->user->name)
                    ->subject('Order Berhasil - ' . $order->trx_id);
            });

            Log::info('Success email sent', [
                'order_id' => $order->id,
                'trx_id' => $order->trx_id,
                'email' => $order->user->email
            ]);

            // Opsional: Kirim notifikasi Telegram/WhatsApp juga
            // $this->sendTelegramNotification($order);

        } catch (\Exception $e) {
            Log::error('Failed to send success email', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

            if ($this->attempts() >= $this->tries) {
                Log::error('Success email permanently failed', [
                    'order_id' => $this->order->id,
                    'email' => $this->order->user->email
                ]);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Success email job failed completely', [
            'order_id' => $this->order->id,
            'email' => $this->order->user->email,
            'error' => $exception->getMessage()
        ]);
    }

    /**
     * Opsional: Kirim notifikasi via Telegram
     */
    private function sendTelegramNotification(Order $order): void
    {
        // Implementasi Telegram notification jika diperlukan
        // Contoh menggunakan Telegram Bot API
        /*
        $botToken = config('services.telegram.bot_token');
        $chatId = $order->user->telegram_chat_id;

        if ($chatId) {
            $message = "✅ *Transaksi Berhasil*\n\n";
            $message .= "Order ID: `{$order->trx_id}`\n";
            $message .= "Produk: {$order->product->name}\n";
            $message .= "SN: `{$order->sn}`\n";
            $message .= "Total: Rp " . number_format($order->total_price, 0, ',', '.');

            Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown'
            ]);
        }
        */
    }
}