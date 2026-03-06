<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pesanan Berhasil — FEEPAY.ID</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; color: #1a1a1a; }
        .container { max-width: 580px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
        .header { background: linear-gradient(135deg, #10b981, #059669); color: #fff; padding: 40px 32px; text-align: center; }
        .header .icon { font-size: 48px; margin-bottom: 12px; }
        .header h1 { font-size: 26px; font-weight: 800; margin-bottom: 6px; }
        .header p { font-size: 14px; opacity: 0.9; }
        .body { padding: 32px; }
        .greeting { font-size: 15px; color: #4b5563; margin-bottom: 24px; line-height: 1.6; }
        .detail-box { background: #f9fafb; border-radius: 10px; padding: 20px; margin-bottom: 24px; }
        .detail-row { display: flex; justify-content: space-between; align-items: flex-start; padding: 10px 0; border-bottom: 1px solid #e5e7eb; font-size: 14px; gap: 12px; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: #6b7280; flex-shrink: 0; }
        .detail-value { font-weight: 600; color: #111827; text-align: right; word-break: break-all; }
        .sn-box { background: linear-gradient(135deg, #059669, #10b981); color: #fff; padding: 20px; border-radius: 10px; text-align: center; margin: 24px 0; }
        .sn-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.85; margin-bottom: 10px; }
        .sn-value { font-size: 22px; font-weight: 800; letter-spacing: 2px; font-family: 'Courier New', monospace; word-break: break-all; }
        .footer { background: #f9fafb; padding: 24px 32px; text-align: center; border-top: 1px solid #e5e7eb; }
        .footer p { font-size: 12px; color: #9ca3af; line-height: 1.8; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon">🎉</div>
            <h1>Pesanan Berhasil!</h1>
            <p>Transaksi Anda telah sukses diproses oleh FEEPAY.ID</p>
        </div>

        <div class="body">
            <p class="greeting">
                Halo! Terima kasih telah berbelanja di FEEPAY.ID.<br>
                Berikut rincian pesanan Anda:
            </p>

            <div class="detail-box">
                <div class="detail-row">
                    <span class="detail-label">Order ID</span>
                    <span class="detail-value" style="font-family: monospace; font-size: 13px;">#{{ $order->order_id }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Produk</span>
                    <span class="detail-value">{{ $order->product_name }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Tujuan</span>
                    <span class="detail-value">
                        {{ $order->target_number }}
                        @if($order->zone_id) ({{ $order->zone_id }})@endif
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total Harga</span>
                    <span class="detail-value" style="color: #059669;">Rp {{ number_format($order->total_price, 0, ',', '.') }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Waktu</span>
                    <span class="detail-value">{{ $order->created_at->format('d M Y, H:i') }} WIB</span>
                </div>
            </div>

            @if($order->sn && $order->sn !== '-')
            <div class="sn-box">
                <div class="sn-label">SN / Token / Kode Voucher</div>
                <div class="sn-value">{{ $order->sn }}</div>
            </div>
            @endif

            <p style="font-size: 14px; color: #6b7280; line-height: 1.7;">
                Produk telah berhasil dikirimkan ke nomor/akun tujuan. Simpan email ini sebagai bukti transaksi.<br><br>
                Jika ada kendala, hubungi Customer Service kami dengan menyertakan <strong>Order ID</strong> di atas.
            </p>
        </div>

        <div class="footer">
            <p>
                © {{ date('Y') }} <strong>FEEPAY.ID</strong> — Platform Topup Game & PPOB Terpercaya<br>
                Email ini dikirim otomatis · Jangan membalas email ini
            </p>
        </div>
    </div>
</body>
</html>
