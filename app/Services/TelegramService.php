<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    /**
     * Kirim notifikasi teks ke Telegram Admin.
     */
    public static function notify($message)
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        if (!$token || !$chatId) {
            Log::error("Telegram Notif Gagal: Token atau Chat ID belum disetting di .env");
            return;
        }

        try {
            Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text'    => $message,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Exception $e) {
            Log::error("Telegram Error: " . $e->getMessage());
        }
    }
}