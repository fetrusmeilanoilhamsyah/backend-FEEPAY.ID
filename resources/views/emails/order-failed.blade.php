<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Pesanan Gagal</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; color: #1a1a1a; }
    .wrapper { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #ef4444, #dc2626); padding: 40px 32px; text-align: center; }
    .header .icon { width: 72px; height: 72px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 32px; }
    .header h1 { color: #fff; font-size: 24px; font-weight: 800; margin-bottom: 6px; }
    .header p { color: rgba(255,255,255,0.85); font-size: 14px; }
    .body { padding: 32px; }
    .notice { background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 16px; margin-bottom: 24px; }
    .notice p { font-size: 14px; color: #dc2626; line-height: 1.6; }
    .detail-box { background: #f9fafb; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
    .detail-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
    .detail-row:last-child { border-bottom: none; }
    .detail-row .label { color: #6b7280; }
    .detail-row .value { font-weight: 600; color: #111827; text-align: right; max-width: 60%; }
    .detail-row .value.price { color: #ef4444; font-size: 18px; font-weight: 800; }
    .refund-box { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 12px; padding: 16px; margin-bottom: 24px; }
    .refund-box p { font-size: 13px; color: #92400e; line-height: 1.6; }
    .refund-box strong { color: #78350f; }
    .btn { display: block; width: 100%; padding: 14px; background: #ef4444; color: #fff; text-align: center; border-radius: 10px; font-weight: 700; font-size: 15px; text-decoration: none; margin-bottom: 12px; }
    .btn-outline { display: block; width: 100%; padding: 14px; background: transparent; color: #374151; text-align: center; border-radius: 10px; font-weight: 700; font-size: 15px; text-decoration: none; border: 2px solid #e5e7eb; }
    .footer { padding: 24px 32px; background: #f9fafb; text-align: center; border-top: 1px solid #e5e7eb; }
    .footer p { font-size: 12px; color: #9ca3af; line-height: 1.8; }
  </style>
</head>
<body>
  <div class="wrapper">

    <div class="header">
      <div class="icon">✕</div>
      <h1>Pesanan Gagal Diproses</h1>
      <p>Kami mohon maaf atas ketidaknyamanan ini</p>
    </div>

    <div class="body">

      <div class="notice">
        <p><strong>Penyebab:</strong> {{ $reason }}</p>
      </div>

      <div class="detail-box">
        <div class="detail-row">
          <span class="label">Order ID</span>
          <span class="value" style="font-family: monospace; font-size: 13px;">{{ $order->order_id }}</span>
        </div>
        <div class="detail-row">
          <span class="label">Produk</span>
          <span class="value">{{ $order->product_name }}</span>
        </div>
        <div class="detail-row">
          <span class="label">Nomor Tujuan</span>
          <span class="value">{{ $order->target_number }}</span>
        </div>
        <div class="detail-row">
          <span class="label">Total Bayar</span>
          <span class="value price">Rp {{ number_format($order->total_price, 0, ',', '.') }}</span>
        </div>
      </div>

      <div class="refund-box">
        <p>
          <strong>Informasi Pengembalian Dana:</strong><br>
          Jika Anda sudah melakukan pembayaran, dana akan dikembalikan secara otomatis dalam <strong>1-3 hari kerja</strong>.
          Jika belum kembali setelah 3 hari, segera hubungi Customer Service kami.
        </p>
      </div>

      <a href="https://feepay.id" class="btn">Coba Pesan Lagi</a>
      <a href="https://wa.me/{{ env('CS_WHATSAPP', '6281234567890') }}" class="btn-outline">Hubungi Customer Service</a>

    </div>

    <div class="footer">
      <p>
        Email ini dikirim otomatis oleh sistem FEEPAY.ID<br>
        Jangan membalas email ini · <a href="https://feepay.id" style="color: #6b7280;">feepay.id</a>
      </p>
    </div>

  </div>
</body>
</html>