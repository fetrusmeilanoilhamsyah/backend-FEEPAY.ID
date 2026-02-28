<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public static function notify($message)
    {
        // Menggunakan config lebih stabil daripada env langsung
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (!$token || !$chatId) {
            Log::error("Telegram Notif Gagal: Token/ID belum diatur di config/services.php");
            return;
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text'    => $message,
                'parse_mode' => 'Markdown',
            ]);

            if ($response->failed()) {
                Log::error("Telegram API Error: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("Telegram Exception: " . $e->getMessage());
        }
    }
}