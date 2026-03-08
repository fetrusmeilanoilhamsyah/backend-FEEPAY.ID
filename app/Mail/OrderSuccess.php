<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderSuccess extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order   $order,
        public Product $product
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '✅ Pesanan Berhasil — ' . $this->order->order_id . ' | FEEPAY.ID',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-success',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}