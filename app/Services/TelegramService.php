<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public static function notify($message)
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        // Cek apakah data di .env terbaca
        if (!$token || !$chatId) {
            Log::error("Telegram Notif Gagal: Variabel .env tidak ditemukan.");
            return;
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text'    => $message,
                'parse_mode' => 'Markdown',
            ]);

            // Catat hasil balasan dari Telegram ke log
            if ($response->failed()) {
                Log::error("Telegram API Error: " . $response->body());
            } else {
                Log::info("Telegram Notif Berhasil Terkirim!");
            }
            
        } catch (\Exception $e) {
            Log::error("Telegram Exception: " . $e->getMessage());
        }
    }
}