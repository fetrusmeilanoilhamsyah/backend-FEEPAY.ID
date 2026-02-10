<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class SupportController extends Controller
{
    /**
     * Send support message
     * 
     * POST /api/support/send
     */
    public function send(Request $request)
    {
        $request->validate([
            'name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:100',
            'message' => 'required|string|max:1000',
            'platform' => 'nullable|in:whatsapp,telegram',
            'order_id' => 'nullable|string|max:50',
        ]);

        try {
            // Save to database
            $support = SupportMessage::create([
                'user_name' => $request->name ?? 'Guest',
                'user_email' => $request->email ?? 'no-email@feepay.id',
                'message' => $request->message,
                'platform' => $request->platform ?? 'whatsapp',
                'order_id' => $request->order_id,
                'status' => 'pending',
            ]);

            // Log message
            Log::info('Support message received', [
                'id' => $support->id,
                'name' => $request->name,
                'email' => $request->email,
                'platform' => $request->platform,
                'ip' => $request->ip(),
            ]);

            // Send Telegram notification to admin
            try {
                $this->sendTelegramNotification($support);
                $support->update(['status' => 'sent']);
            } catch (\Exception $e) {
                Log::error('Failed to send Telegram notification', [
                    'error' => $e->getMessage(),
                    'support_id' => $support->id,
                ]);
                $support->update(['status' => 'failed']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Pesan berhasil dikirim. Tim kami akan segera merespons.',
                'data' => [
                    'ticket_id' => 'SUP' . str_pad($support->id, 6, '0', STR_PAD_LEFT),
                    'sent_at' => now()->toIso8601String(),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Support send failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim pesan. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * Send Telegram notification to admin
     * 
     * @param SupportMessage $support
     * @return array
     * @throws \Exception
     */
    private function sendTelegramNotification(SupportMessage $support)
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        if (!$botToken || !$chatId) {
            throw new \Exception('Telegram configuration missing');
        }

        $ticketId = 'SUP' . str_pad($support->id, 6, '0', STR_PAD_LEFT);
        $platformEmoji = $support->platform === 'telegram' ? '✈️' : '💬';

        $message = "🔔 *SUPPORT MESSAGE BARU - FEEPAY\\.ID*\n\n" .
                   "📋 *Ticket:* `{$ticketId}`\n" .
                   "👤 *Nama:* " . $this->escapeMarkdown($support->user_name) . "\n" .
                   "📧 *Email:* " . $this->escapeMarkdown($support->user_email) . "\n" .
                   "{$platformEmoji} *Platform:* " . ucfirst($support->platform) . "\n\n" .
                   "💬 *Pesan:*\n" . $this->escapeMarkdown($support->message) . "\n\n" .
                   "🕒 *Waktu:* " . $support->created_at->format('d M Y H:i') . " WIB";

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        
        $response = Http::timeout(10)->post($url, [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'MarkdownV2',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Telegram API error: ' . $response->body());
        }

        Log::info('Telegram notification sent', [
            'ticket_id' => $ticketId,
            'chat_id' => $chatId,
        ]);

        return $response->json();
    }

    /**
     * Escape special characters for Telegram MarkdownV2
     * 
     * @param string $text
     * @return string
     */
    private function escapeMarkdown($text)
    {
        $specialChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        
        foreach ($specialChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        
        return $text;
    }

    /**
     * Get contact information
     * 
     * GET /api/support/contacts
     */
    public function getContacts()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'whatsapp' => [
                    'number' => env('SUPPORT_WHATSAPP', '6281234567890'),
                    'url' => 'https://wa.me/' . env('SUPPORT_WHATSAPP', '6281234567890'),
                    'label' => 'WhatsApp Support',
                ],
                'telegram' => [
                    'username' => env('SUPPORT_TELEGRAM', 'feepay_support'),
                    'url' => 'https://t.me/' . env('SUPPORT_TELEGRAM', 'feepay_support'),
                    'label' => 'Telegram Support',
                ],
                'email' => env('SUPPORT_EMAIL', 'support@feepay.id'),
            ]
        ], 200);
    }
}