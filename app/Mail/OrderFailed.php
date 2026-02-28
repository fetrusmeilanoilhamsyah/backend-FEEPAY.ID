<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderFailed extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     * Variabel $order dan $reason otomatis tersedia di template Blade.
     */
    public function __construct(
        public Order $order,
        public string $reason = 'Terjadi kesalahan saat memproses pesanan Anda.'
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Pesanan Gagal - ' . $this->order->order_id,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-failed',
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}