<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Success - FEEPAY.ID</title>
    <style>
        /* CSS tetap sama dengan yang kamu berikan karena sudah bagus */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; background-color: #f5f5f5; padding: 20px; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); }
        .email-header { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #ffffff; padding: 40px 30px; text-align: center; }
        .email-header h1 { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .email-body { padding: 40px 30px; }
        .success-badge { display: inline-block; background-color: #10b981; color: #ffffff; padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 600; margin-bottom: 24px; }
        .order-details { background-color: #f9fafb; border-radius: 8px; padding: 24px; margin-bottom: 24px; }
        .detail-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #e5e7eb; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { font-size: 14px; color: #6b7280; font-weight: 500; }
        .detail-value { font-size: 14px; color: #111827; font-weight: 600; text-align: right; }
        .sn-box { background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: #ffffff; padding: 20px; border-radius: 8px; text-align: center; margin: 24px 0; }
        .sn-label { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; margin-bottom: 8px; }
        .sn-value { font-size: 24px; font-weight: 700; letter-spacing: 2px; font-family: 'Courier New', monospace; }
        .email-footer { background-color: #f9fafb; padding: 30px; text-align: center; border-top: 1px solid #e5e7eb; }
        .footer-text { font-size: 13px; color: #9ca3af; margin-bottom: 8px; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>🎉 Pesanan Berhasil!</h1>
            <p>Terima kasih, pesanan Anda telah sukses diproses</p>
        </div>

        <div class="email-body">
            <span class="success-badge">✓ Sukses</span>

            <p style="font-size: 14px; color: #6b7280; margin-bottom: 16px;">
                Halo! Transaksi Anda telah berhasil. Berikut adalah rincian pesanan Anda:
            </p>

            <div class="order-details">
                <div class="detail-row">
                    <span class="detail-label">Order ID</span>
                    <span class="detail-value">#{{ $order->order_id }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Produk</span>
                    <span class="detail-value">{{ $order->product_name }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Tujuan</span>
                    <span class="detail-value">{{ $order->target_number }}{{ $order->zone_id ? ' ('.$order->zone_id.')' : '' }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total Harga</span>
                    <span class="detail-value">Rp {{ number_format($order->total_price, 0, ',', '.') }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Waktu</span>
                    <span class="detail-value">{{ $order->created_at->format('d M Y, H:i') }}</span>
                </div>
            </div>

            @if($order->sn)
            <div class="sn-box">
                <div class="sn-label">SN / TOKEN / KODE VOUCHER</div>
                <div class="sn-value">{{ $order->sn }}</div>
            </div>
            @endif

            <p style="font-size: 14px; color: #6b7280; margin-bottom: 16px;">
                Produk telah berhasil dikirimkan ke nomor tujuan. Jika ada kendala, silakan hubungi Customer Service kami.
            </p>
        </div>

        <div class="email-footer">
            <p class="footer-text">© {{ date('Y') }} <strong>FEEPAY.ID</strong>. All rights reserved.</p>
            <p class="footer-text">Platform Topup Game & PPOB Terpercaya</p>
        </div>
    </div>
</body>
</html>