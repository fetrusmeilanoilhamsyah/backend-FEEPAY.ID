<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\DigiflazzService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessOrderTransaction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Jumlah percobaan retry
     */
    public $tries = 3;

    /**
     * Timeout dalam detik
     */
    public $timeout = 120;

    /**
     * Backoff time untuk retry (dalam detik)
     * Retry 1: 1 menit, Retry 2: 5 menit, Retry 3: 15 menit
     */
    public $backoff = [60, 300, 900];

    /**
     * Order yang akan diproses
     */
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
    public function handle(DigiflazzService $digiflazz): void
    {
        try {
            Log::info('Processing order transaction', [
                'order_id' => $this->order->id,
                'trx_id' => $this->order->trx_id,
                'attempt' => $this->attempts()
            ]);

            // Cek apakah order masih pending
            $currentOrder = Order::find($this->order->id);
            
            if (!$currentOrder) {
                Log::warning('Order not found', ['order_id' => $this->order->id]);
                return;
            }

            if (!in_array($currentOrder->status, ['pending', 'processing'])) {
                Log::info('Order already processed, skipping', [
                    'order_id' => $currentOrder->id,
                    'status' => $currentOrder->status
                ]);
                return;
            }

            // Update status jadi processing
            DB::table('orders')
                ->where('id', $this->order->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'processing',
                    'updated_at' => now()
                ]);

            // Hit API Digiflazz
            $result = $digiflazz->purchaseProduct(
                $this->order->product->buyer_sku_code,
                $this->order->customer_id,
                $this->order->trx_id
            );

            DB::beginTransaction();

            if (isset($result['data']['status']) && $result['data']['status'] === 'Sukses') {
                // Transaction success
                $this->order->update([
                    'status' => 'success',
                    'sn' => $result['data']['sn'] ?? null,
                    'provider_response' => json_encode($result),
                ]);

                DB::commit();

                // Kirim notifikasi sukses
                SendOrderSuccessEmail::dispatch($this->order);

                Log::info('Order transaction successful', [
                    'order_id' => $this->order->id,
                    'trx_id' => $this->order->trx_id,
                    'sn' => $result['data']['sn'] ?? null
                ]);

            } elseif (isset($result['data']['status']) && $result['data']['status'] === 'Gagal') {
                // Transaction failed
                $this->order->update([
                    'status' => 'failed',
                    'provider_response' => json_encode($result),
                ]);

                // Refund balance
                DB::table('users')
                    ->where('id', $this->order->user_id)
                    ->update([
                        'balance' => DB::raw('balance + ' . $this->order->total_price),
                        'updated_at' => now()
                    ]);

                DB::commit();

                // Kirim notifikasi gagal
                SendOrderFailedEmail::dispatch($this->order);

                Log::warning('Order transaction failed by provider', [
                    'order_id' => $this->order->id,
                    'trx_id' => $this->order->trx_id,
                    'message' => $result['data']['message'] ?? 'Unknown error'
                ]);

            } else {
                // Status pending atau unknown
                $this->order->update([
                    'provider_response' => json_encode($result),
                ]);

                DB::commit();

                Log::info('Order transaction pending', [
                    'order_id' => $this->order->id,
                    'trx_id' => $this->order->trx_id,
                    'status' => $result['data']['status'] ?? 'unknown'
                ]);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Order transaction processing failed', [
                'order_id' => $this->order->id,
                'trx_id' => $this->order->trx_id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString()
            ]);

            // Jika sudah retry maksimal, mark sebagai failed dan refund
            if ($this->attempts() >= $this->tries) {
                DB::beginTransaction();
                try {
                    $this->order->update(['status' => 'failed']);
                    
                    // Refund balance
                    DB::table('users')
                        ->where('id', $this->order->user_id)
                        ->update([
                            'balance' => DB::raw('balance + ' . $this->order->total_price),
                            'updated_at' => now()
                        ]);

                    DB::commit();

                    SendOrderFailedEmail::dispatch($this->order);

                    Log::error('Order marked as failed after max retries', [
                        'order_id' => $this->order->id,
                        'refund_amount' => $this->order->total_price
                    ]);

                } catch (\Exception $refundError) {
                    DB::rollBack();
                    Log::critical('Failed to refund after max retries', [
                        'order_id' => $this->order->id,
                        'error' => $refundError->getMessage()
                    ]);
                }
            }

            // Re-throw exception untuk trigger retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Order processing job completely failed', [
            'order_id' => $this->order->id,
            'trx_id' => $this->order->trx_id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Kirim notifikasi ke admin atau monitoring system
        // Bisa pakai Slack, Telegram, atau email ke admin
    }
}