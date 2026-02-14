{{--
    resources/views/payment/checkout.blade.php

    Halaman ini menampilkan tombol bayar dengan Midtrans Snap.
    Setelah user klik "Bayar Sekarang", popup Snap akan muncul
    dengan pilihan: QRIS, GoPay, Transfer Bank, dll.
--}}

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembayaran - FEEPAY.ID</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Snap.js dari Midtrans - WAJIB ada di <head> --}}
    <script src="{{ $snapJsUrl }}"
            data-client-key="{{ $clientKey }}">
    </script>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f0f4f8;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        }
        .logo { font-size: 22px; font-weight: 700; color: #1a56db; margin-bottom: 24px; }
        .label { font-size: 13px; color: #6b7280; margin-bottom: 4px; }
        .value { font-size: 15px; color: #111827; font-weight: 500; margin-bottom: 16px; }
        .amount {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 600;
            background: #fef3c7;
            color: #92400e;
            margin-bottom: 24px;
        }
        .divider {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 20px 0;
        }
        .methods {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .method-tag {
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 500;
        }
        .btn-pay {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #1a56db, #1e40af);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .btn-pay:hover { opacity: 0.9; }
        .btn-pay:disabled { opacity: 0.6; cursor: not-allowed; }
        .note {
            text-align: center;
            font-size: 12px;
            color: #9ca3af;
            margin-top: 16px;
        }
        #status-area {
            display: none;
            text-align: center;
            padding: 16px;
            border-radius: 10px;
            margin-top: 16px;
            font-weight: 500;
        }
        .status-success { background: #d1fae5; color: #065f46; }
        .status-pending  { background: #fef3c7; color: #92400e; }
        .status-failed   { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

<div class="card">
    <div class="logo">💳 FEEPAY.ID</div>

    <div class="label">Produk</div>
    <div class="value">{{ $productName }}</div>

    <div class="label">Total Pembayaran</div>
    <div class="amount">Rp {{ number_format($amount, 0, ',', '.') }}</div>

    <span class="badge" id="status-badge">⏳ Menunggu Pembayaran</span>

    <hr class="divider">

    <div class="label" style="margin-bottom: 8px">Metode Tersedia</div>
    <div class="methods">
        <span class="method-tag">🏦 Transfer Bank</span>
        <span class="method-tag">⚡ QRIS</span>
        <span class="method-tag">💚 GoPay</span>
        <span class="method-tag">🟣 OVO</span>
        <span class="method-tag">🔵 Dana</span>
    </div>

    <button class="btn-pay" id="btn-pay" onclick="openSnap()">
        Bayar Sekarang
    </button>

    <div class="note">🔒 Pembayaran aman diproses oleh Midtrans</div>

    <div id="status-area"></div>
</div>

<script>
    // ─── Data dari PHP ─────────────────────────────────────────
    const SNAP_TOKEN = "{{ $snapToken }}";
    const ORDER_ID   = "{{ $orderId }}";

    // ─── Buka Popup Midtrans Snap ───────────────────────────────
    function openSnap() {
        const btn = document.getElementById('btn-pay');
        btn.disabled = true;
        btn.textContent = 'Memuat...';

        window.snap.pay(SNAP_TOKEN, {

            // Dipanggil saat pembayaran BERHASIL
            onSuccess: function(result) {
                console.log('[Snap] Success:', result);
                showStatus('✅ Pembayaran Berhasil! Terima kasih.', 'success');
                updateBadge('✅ Lunas');

                // Redirect ke halaman sukses setelah 2 detik
                setTimeout(() => {
                    window.location.href = `/payment/success?order_id=${ORDER_ID}`;
                }, 2000);
            },

            // Dipanggil saat pembayaran masih PENDING
            onPending: function(result) {
                console.log('[Snap] Pending:', result);
                showStatus('⏳ Menunggu konfirmasi pembayaran...', 'pending');
                startPolling(); // Mulai cek status setiap 5 detik
            },

            // Dipanggil saat pembayaran GAGAL
            onError: function(result) {
                console.error('[Snap] Error:', result);
                showStatus('❌ Pembayaran gagal. Silakan coba lagi.', 'failed');
                btn.disabled = false;
                btn.textContent = 'Coba Lagi';
            },

            // Dipanggil saat user MENUTUP popup
            onClose: function() {
                console.log('[Snap] Popup ditutup');
                btn.disabled = false;
                btn.textContent = 'Bayar Sekarang';
            }
        });
    }

    // ─── Polling Status (fallback jika webhook lambat) ──────────
    let pollingInterval = null;

    function startPolling() {
        if (pollingInterval) return; // Jangan dobel

        pollingInterval = setInterval(async () => {
            try {
                const res  = await fetch(`/api/payment/status/${ORDER_ID}`);
                const data = await res.json();

                if (data.is_paid) {
                    clearInterval(pollingInterval);
                    showStatus('✅ Pembayaran Dikonfirmasi!', 'success');
                    setTimeout(() => {
                        window.location.href = `/payment/success?order_id=${ORDER_ID}`;
                    }, 1500);
                } else if (['expire', 'cancel', 'deny', 'failure'].includes(data.status)) {
                    clearInterval(pollingInterval);
                    showStatus('❌ Pembayaran ' + data.status + '. Silakan buat order baru.', 'failed');
                }
            } catch (err) {
                console.error('[Polling] Error:', err);
            }
        }, 5000); // Cek tiap 5 detik
    }

    // ─── UI Helpers ─────────────────────────────────────────────
    function showStatus(message, type) {
        const el = document.getElementById('status-area');
        el.style.display = 'block';
        el.className     = 'status-' + type;
        el.textContent   = message;
    }

    function updateBadge(text) {
        document.getElementById('status-badge').textContent = text;
    }
</script>
</body>
</html>