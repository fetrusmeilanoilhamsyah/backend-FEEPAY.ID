<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    /**
     * Kirim notifikasi teks ke Telegram admin.
     * Menggunakan Markdown biasa (bukan MarkdownV2) agar konsisten
     * dengan semua bagian kode yang memanggil method ini.
     */
    public static function notify(string $message): void
    {
        $token  = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (!$token || !$chatId) {
            Log::warning('TelegramService: bot_token atau chat_id belum dikonfigurasi di .env');
            return;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'Markdown',
            ]);

            if ($response->failed()) {
                Log::error('TelegramService: API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            // Jangan sampai error Telegram menghentikan alur utama
            Log::error('TelegramService: exception', ['error' => $e->getMessage()]);
        }
    }
}
