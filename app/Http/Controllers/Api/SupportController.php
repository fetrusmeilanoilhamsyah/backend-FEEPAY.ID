<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupportController extends Controller
{
    /**
     * POST /api/support/send
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => 'nullable|string|max:100',
            'email'    => 'nullable|email|max:100',
            'message'  => 'required|string|max:1000',
            'platform' => 'nullable|in:whatsapp,telegram',
            'order_id' => 'nullable|string|max:50',
        ]);

        try {
            $support = SupportMessage::create([
                'user_name'  => $request->name     ?? 'Guest',
                'user_email' => $request->email    ?? 'no-email@feepay.id',
                'message'    => $request->message,
                'platform'   => $request->platform ?? 'whatsapp',
                'order_id'   => $request->order_id,
                'status'     => 'pending',
            ]);

            Log::info('Support message diterima', [
                'id'       => $support->id,
                'platform' => $request->platform,
                'ip'       => $request->ip(),
            ]);

            try {
                $this->sendTelegramNotification($support);
                $support->update(['status' => 'sent']);
            } catch (\Exception $e) {
                Log::error('Gagal kirim notifikasi Telegram support', [
                    'error'      => $e->getMessage(),
                    'support_id' => $support->id,
                ]);
                $support->update(['status' => 'failed']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Pesan berhasil dikirim. Tim kami akan segera merespons.',
                'data'    => [
                    'ticket_id' => 'SUP' . str_pad($support->id, 6, '0', STR_PAD_LEFT),
                    'sent_at'   => now()->toIso8601String(),
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('SupportController::send gagal', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim pesan. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * GET /api/support/contacts
     */
    public function getContacts(): JsonResponse
    {
        $wa       = config('feepay.support_whatsapp', '6281234567890');
        $telegram = config('feepay.support_telegram', 'feepay_support');
        $email    = config('feepay.support_email', 'support@feepay.id');

        return response()->json([
            'success' => true,
            'data'    => [
                'whatsapp' => [
                    'number' => $wa,
                    'url'    => 'https://wa.me/' . $wa,
                    'label'  => 'WhatsApp Support',
                ],
                'telegram' => [
                    'username' => $telegram,
                    'url'      => 'https://t.me/' . $telegram,
                    'label'    => 'Telegram Support',
                ],
                'email' => $email,
            ],
        ], 200);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function sendTelegramNotification(SupportMessage $support): void
    {
        $botToken = config('services.telegram.bot_token');
        $chatId   = config('services.telegram.chat_id');

        if (!$botToken || !$chatId) {
            throw new \Exception('Konfigurasi Telegram belum diset.');
        }

        $ticketId      = 'SUP' . str_pad($support->id, 6, '0', STR_PAD_LEFT);
        $platformEmoji = $support->platform === 'telegram' ? '✈️' : '💬';

        // Escape untuk MarkdownV2
        $escapedTicket   = $this->escapeMarkdown($ticketId);
        $escapedName     = $this->escapeMarkdown($support->user_name);
        $escapedEmail    = $this->escapeMarkdown($support->user_email);
        $escapedPlatform = $this->escapeMarkdown(ucfirst($support->platform));
        $escapedMessage  = $this->escapeMarkdown($support->message);
        $escapedTime     = $this->escapeMarkdown($support->created_at->format('d M Y H:i') . ' WIB');

        $text = "🔔 *SUPPORT MESSAGE BARU \\- FEEPAY\\.ID*\n\n" .
                "📋 *Ticket:* `{$escapedTicket}`\n" .
                "👤 *Nama:* {$escapedName}\n" .
                "📧 *Email:* {$escapedEmail}\n" .
                "{$platformEmoji} *Platform:* {$escapedPlatform}\n\n" .
                "💬 *Pesan:*\n{$escapedMessage}\n\n" .
                "🕒 *Waktu:* {$escapedTime}";

        $response = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'MarkdownV2',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Telegram API error: ' . $response->body());
        }
    }

    private function escapeMarkdown(string $text): string
    {
        if (empty($text)) return '';

        // Escape backslash dulu sebelum karakter lain
        $text = str_replace('\\', '\\\\', $text);

        $specialChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        foreach ($specialChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }

        return $text;
    }
}
