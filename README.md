# 🏦 FEEPAY.ID — Digital Product & PPOB Platform

<div align="center">

![FEEPAY.ID Banner](https://img.shields.io/badge/FEEPAY.ID-Platform%20Digital%20Terpercaya-16a34a?style=for-the-badge&logoColor=white)

**Platform jual-beli produk digital lengkap berbasis REST API.**
Pulsa • Kuota Data • Token PLN • Top Up Game • Voucher Game

[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mysql.com)
[![Midtrans](https://img.shields.io/badge/Midtrans-Payment%20Gateway-003E6B?style=for-the-badge)](https://midtrans.com)
[![Digiflazz](https://img.shields.io/badge/Digiflazz-PPOB%20Provider-F7941D?style=for-the-badge)](https://digiflazz.com)
[![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)](LICENSE)
[![Status](https://img.shields.io/badge/Status-Production%20Ready-brightgreen?style=for-the-badge)]()

</div>

---

## 📑 Daftar Isi

- [Gambaran Umum](#-gambaran-umum)
- [Arsitektur Sistem](#️-arsitektur-sistem)
- [Stack Teknologi](#-stack-teknologi)
- [Fitur Lengkap](#-fitur-lengkap)
  - [Katalog Produk Digital](#1--katalog-produk-digital)
  - [Alur Pembayaran Midtrans](#2--integrasi-midtrans-payment-gateway)
  - [Integrasi Digiflazz](#3--integrasi-digiflazz-ppob-provider)
  - [Dashboard Admin](#4--dashboard-admin)
  - [Cek Status Order](#5--cek-status-order-tanpa-login)
  - [Support Tiket](#6--sistem-support-tiket)
  - [Email Notifikasi](#7--email-notifikasi-otomatis)
  - [Telegram Admin Alerts](#8--telegram-admin-alerts-real-time)
  - [Idempotency Order](#9--idempotency-anti-order-duplikat)
  - [Queue Worker](#10--queue-worker-background-jobs)
- [Keamanan Berlapis (10 Layer)](#-keamanan-berlapis-10-layer)
- [Struktur Endpoint API](#-struktur-endpoint-api)
- [Alur Transaksi End-to-End](#-alur-transaksi-end-to-end)
- [Struktur Database](#️-struktur-database)
- [Struktur Folder Proyek](#-struktur-folder-proyek)
- [Panduan Instalasi](#-panduan-instalasi)
- [Development vs Production](#️-development-vs-production)
- [Auto-Sync Produk](#-jadwal-auto-sync-produk)
- [Environment Variables](#-environment-variables-lengkap)
- [Security Best Practices](#-security-best-practices)
- [Troubleshooting](#-troubleshooting)
- [Kontak Pengembang](#-kontak-pengembang)

---

## 🎯 Gambaran Umum

FEEPAY.ID adalah backend REST API untuk platform toko produk digital dan PPOB *(Payment Point Online Bank)*. Sistem menangani **seluruh alur transaksi secara otomatis** — dari pelanggan memilih produk, melakukan pembayaran via berbagai metode Midtrans, hingga produk otomatis dikirim ke Digiflazz dan SN/token dikirim ke email pelanggan — **tanpa intervensi manual dari admin**.

### Kenapa FEEPAY.ID berbeda?

| Fitur | FEEPAY.ID | Platform biasa |
|---|:---:|:---:|
| Harga 100% dari database, tidak bisa dimanipulasi | ✅ | ❌ |
| Anti order duplikat (Idempotency Key) | ✅ | ❌ |
| Anti double-processing (Race Condition Guard) | ✅ | ❌ |
| Admin triple-layer auth (Token + PIN + Secret Path) | ✅ | ❌ |
| IP Whitelist untuk akses admin | ✅ | ❌ |
| Webhook signature verification (Midtrans + Digiflazz) | ✅ | ❌ |
| Email queue dengan auto-retry | ✅ | ❌ |
| Telegram alert real-time | ✅ | ❌ |
| Soft delete — data tidak pernah hilang permanen | ✅ | ❌ |
| Audit trail setiap perubahan status order | ✅ | ❌ |

### Kategori Produk

| Kategori | Contoh | Provider |
|---|---|---|
| 📱 **Pulsa** | Telkomsel, XL, Indosat, Tri, Smartfren | Digiflazz |
| 📶 **Kuota Data** | Paket internet semua operator | Digiflazz |
| ⚡ **Token PLN** | Token listrik prabayar semua daya | Digiflazz |
| 🎮 **Top Up Game** | Free Fire, Mobile Legends, Genshin Impact | Digiflazz |
| 🎟️ **Voucher Game** | Voucher Steam, Google Play, UniPin | Digiflazz |

---

## 🛠️ Arsitektur Sistem

```
┌─────────────────────────────────────────────────────────────────────┐
│                          FEEPAY.ID ECOSYSTEM                        │
│                                                                     │
│  ┌──────────────┐   REST API    ┌─────────────────────────────────┐ │
│  │   Frontend   │◄────────────►│       Laravel 11 API            │ │
│  │  Vue 3 SPA   │              │                                 │ │
│  └──────────────┘              │  ┌──────────┐  ┌─────────────┐  │ │
│                                │  │  Queue   │  │  Scheduler  │  │ │
│  ┌──────────────┐  webhook     │  │ (2 jobs) │  │  (4x/hari)  │  │ │
│  │   Midtrans   │─────────────►│  └────┬─────┘  └──────┬──────┘  │ │
│  │  (Payment)   │              │       │                │         │ │
│  └──────────────┘              └───────┼────────────────┼─────────┘ │
│                                        │                │            │
│  ┌──────────────┐  callback    ┌───────▼──────┐  ┌─────▼─────────┐ │
│  │  Digiflazz   │─────────────►│  Digiflazz   │  │   Scheduler   │ │
│  │  (Provider)  │◄────────────│  Service     │  │  Sync Produk  │ │
│  └──────────────┘  purchase   └───────┬──────┘  └───────────────┘ │
│                                        │                            │
│  ┌──────────────┐              ┌───────▼──────┐  ┌───────────────┐ │
│  │    MySQL     │◄────────────│   Database   │  │   Telegram    │ │
│  │  (Storage)   │             │   (Orders,   │  │    Alerts     │ │
│  └──────────────┘             │   Products)  │  └───────────────┘ │
│                                └─────────────┘                     │
│  ┌──────────────┐              ┌─────────────┐                     │
│  │  SMTP Email  │◄────────────│    Queue    │                     │
│  │  (Customer)  │             │   Worker    │                     │
│  └──────────────┘             └─────────────┘                     │
└─────────────────────────────────────────────────────────────────────┘
```

### Komponen Utama

| Komponen | Peran |
|---|---|
| **Laravel 11 API** | Core backend, routing, business logic, ORM |
| **MySQL** | Penyimpanan data order, produk, histori status |
| **Midtrans** | Payment gateway — VA, QRIS, e-wallet |
| **Digiflazz** | Provider PPOB — eksekutor pengiriman produk digital |
| **Queue Worker** | Background job: email notifikasi pelanggan |
| **Supervisor** | Process manager — pastikan queue worker jalan 24/7 |
| **Telegram Bot** | Alert real-time ke admin untuk setiap event |
| **SMTP** | Email notifikasi sukses/gagal ke pelanggan |

---

## 💻 Stack Teknologi

| Layer | Teknologi | Versi | Keterangan |
|---|---|---|---|
| **Framework** | Laravel | 11.x | PHP 8.2+, API-only mode |
| **Language** | PHP | 8.2+ | Enum, Fibers, Named Arguments |
| **Database** | MySQL | 8.0+ | InnoDB, index dioptimasi |
| **Auth** | Laravel Sanctum | 4.x | Token-based, expire 24 jam |
| **Queue** | Database Driver | — | Job retry dengan backoff eksponensial |
| **Process Manager** | Supervisor | 4.x | 2 worker instance, auto-restart |
| **Web Server** | Nginx | Latest | PHP-FPM 8.3 |
| **SSL** | Let's Encrypt | — | Auto-renew via Certbot |
| **Payment** | Midtrans Snap | — | VA, QRIS, GoPay, Dana, ShopeePay, Akulaku, Kredivo |
| **PPOB** | Digiflazz API | v1 | Pulsa, Data, PLN, Game, Voucher |
| **Notification** | Telegram Bot API | — | Real-time alert ke admin |
| **Email** | SMTP (Brevo/Gmail) | — | Queue-based dengan retry |

---

## 🔥 Fitur Lengkap

### 1. 🛒 Katalog Produk Digital

Endpoint publik yang menyajikan seluruh produk aktif dengan kemampuan filter kategori. Sistem memisahkan `cost_price` (harga modal dari Digiflazz) dan `selling_price` (harga jual ke pelanggan) — admin dapat mengatur margin keuntungan secara fleksibel.

**Computed attribute `profit_margin`** dikalkulasi otomatis di model PHP, tidak disimpan di database, sehingga selalu akurat mengikuti harga terkini:

```php
// Model Product — profit_margin selalu fresh
public function getProfitMarginAttribute(): float
{
    return $this->selling_price - $this->cost_price;
}
```

**Filter kategori** tersedia via query parameter:
```
GET /api/products?category=Pulsa
GET /api/products?category=Games
GET /api/products?category=PLN
```

Produk yang ditampilkan ke publik **tidak menyertakan** `cost_price` maupun margin — hanya data yang relevan untuk pelanggan. Field sensitif hanya tampil di endpoint admin.

**Perlindungan harga saat sync:**
Saat admin mensinkronkan produk dari Digiflazz, sistem cerdas melindungi harga yang sudah diset:
- Jika harga modal naik melebihi harga jual → `selling_price` otomatis disesuaikan (cost + margin default)
- Jika harga jual masih di atas harga modal → **tidak ditimpa**, margin tetap aman

---

### 2. 💳 Integrasi Midtrans (Payment Gateway)

Mendukung seluruh metode pembayaran populer Indonesia melalui satu integrasi Midtrans Snap:

| Kategori | Metode |
|---|---|
| **Virtual Account** | BNI, BCA, BRI, Mandiri (Echannel), Permata |
| **E-Wallet** | GoPay, DANA, ShopeePay |
| **QR Code** | QRIS |
| **PayLater** | Akulaku, Kredivo |

**Keunggulan implementasi:**

**① Harga 100% dari database:**
```php
// Amount diambil dari DB — tidak bisa dimanipulasi dari request
$amount = (int) $product->selling_price;
$snapToken = $this->midtransService->createSnapToken(
    $order->order_id,
    $amount,           // ← dari DB, bukan $request->amount
    $order->customer_email,
    $order->product_name
);
```

**② Snap Token Reuse:**
Jika pelanggan request token dua kali untuk order yang sama (misalnya reload halaman), sistem mengembalikan token lama tanpa membuat baru. Ini mencegah error `duplicate order_id` dari Midtrans:
```php
if ($order->midtrans_snap_token) {
    return response()->json(['data' => ['snap_token' => $order->midtrans_snap_token]]);
}
```

**③ Webhook Signature Verification (SHA-512):**
Setiap notifikasi pembayaran dari Midtrans diverifikasi keasliannya sebelum diproses. Menggunakan `hash_equals()` untuk mencegah timing attack:
```php
$expectedSignature = hash('sha512',
    $orderId . $statusCode . $grossAmount . $serverKey
);
$isValid = hash_equals($expectedSignature, $receivedSignature);
```

**④ Penanganan status lengkap:**
```
capture/settlement + fraud=accept → PROCESSING → kirim ke Digiflazz
deny/expire/cancel               → FAILED → notifikasi pelanggan
pending                          → diabaikan (normal untuk VA)
```

**⑤ Payment type normalisasi:**
Midtrans mengirim `payment_type: "bank_transfer"` yang generik. Sistem mengekstrak nama bank spesifik dari `va_numbers[0].bank` untuk ditampilkan ke pelanggan:
```php
// bank_transfer + bni → bni_va
// bank_transfer + bca → bca_va
if ($paymentType === 'bank_transfer' && isset($notificationData['va_numbers'][0])) {
    $vaData = $notificationData['va_numbers'][0];
    $paymentType   = $vaData['bank'] . '_va';
    $transactionId = $vaData['va_number']; // Nomor VA yang ditampilkan ke user
}
```

---

### 3. ⚡ Integrasi Digiflazz (PPOB Provider)

Digiflazz adalah provider PPOB yang mengeksekusi pengiriman produk digital setelah pembayaran terkonfirmasi.

**Alur otomatis setelah bayar:**
```
Midtrans webhook (settlement) 
  → processToDigiflazz()
    → lockForUpdate() → cek confirmed_at
    → set confirmed_at = now()      ← flag anti double-send
    → purchaseProduct()             ← kirim ke Digiflazz API
    → tunggu callback               ← Digiflazz kirim SN via webhook
```

**Anti double-processing dengan `confirmed_at`:**
Field `confirmed_at` di tabel orders berfungsi sebagai flag. Diset **sebelum** API call ke Digiflazz:
```php
// Set dulu sebelum call API
$locked->update(['confirmed_at' => now()]);

// Kalau sudah terisi, skip — order sudah pernah dikirim
if ($locked->confirmed_at !== null) {
    return; // Anti double-send
}
```

**Rollback otomatis jika gagal:**
Jika Digiflazz menolak order, sistem langsung:
1. Reset `confirmed_at` ke null
2. Set status order ke `failed`
3. Kirim email gagal ke pelanggan
4. Alert Telegram ke admin

**Sinkronisasi status manual:**
Jika callback dari Digiflazz tidak diterima (network issue, server down), admin dapat trigger sync manual:
```
POST /api/admin/{secret}/orders/{orderId}/sync
```

Sistem akan query status langsung ke Digiflazz API dan update order accordingly.

---

### 4. 🖥️ Dashboard Admin

Panel admin yang komprehensif, diproteksi dengan sistem keamanan tiga lapis. Setiap akses wajib menyertakan: **Bearer Token + X-Admin-PIN + Secret Path**.

#### 📊 Statistik & Monitoring

```json
GET /api/admin/{secret}/dashboard/stats?start_date=2026-01-01&end_date=2026-03-31

Response:
{
  "total_orders": 1247,
  "pending": 3,
  "processing": 12,
  "success": 1198,
  "failed": 34,
  "total_revenue": 47850000,
  "revenue_chart": [
    { "date": "2026-03-10", "revenue": 2340000 },
    ...
  ],
  "recent_orders": [...]
}
```

Filter tanggal opsional — jika tidak disertakan, menampilkan statistik all-time.

#### 📦 Manajemen Order

**Daftar order dengan histori status:**
```json
GET /api/admin/{secret}/orders

Response:
{
  "data": [
    {
      "order_id": "FPJGGPQ8M7NTBV",
      "product_name": "Pulsa Telkomsel 50.000",
      "status": "success",
      "sn": "TOKEN_PLN_1234567890",
      "statusHistories": [
        { "status": "pending",    "note": "Order dibuat oleh pelanggan.",          "created_at": "..." },
        { "status": "processing", "note": "Pembayaran sukses via Midtrans.",        "created_at": "..." },
        { "status": "processing", "note": "Order dikirim ke Digiflazz.",            "created_at": "..." },
        { "status": "success",    "note": "Sukses via callback Digiflazz. SN: ...", "created_at": "..." }
      ]
    }
  ]
}
```

**Konfirmasi manual (jika otomatis gagal):**
Admin dapat mengonfirmasi ulang order pending secara manual via:
```
POST /api/admin/{secret}/orders/{id}/confirm
```

#### 🛍️ Manajemen Produk

**Bulk Update Margin** — Update harga jual seluruh produk sekaligus:
```
POST /api/admin/{secret}/products/bulk-margin
Body: { "margin": 2500 }

→ selling_price = cost_price + 2500 (untuk semua produk aktif)
```

**Individual Update** — Update satu produk spesifik:
```
PUT /api/admin/{secret}/products/{id}
Body: { "selling_price": 55000 }
```

**Sync Produk Manual dengan filter:**
```
POST /api/admin/{secret}/products/sync
Body: { "category": "Pulsa" }   ← opsional
```

---

### 5. 📋 Cek Status Order Tanpa Login

Sistem ini tidak menggunakan login pelanggan. Pelanggan dapat mengecek status ordernya kapan saja hanya dengan Order ID dan email — sistem memverifikasi kepemilikan sebelum menampilkan data:

```
POST /api/orders/{orderId}
Body: { "email": "pelanggan@email.com" }
```

Perbandingan email dilakukan **case-insensitive** (`strtolower`) agar pelanggan tidak frustrasi karena huruf kapital:
```php
if (strtolower($request->email) !== strtolower($order->customer_email)) {
    return response()->json(['message' => 'Pesanan tidak ditemukan.'], 404);
}
```

Response menyertakan seluruh field Midtrans (nomor VA, payment type, status pembayaran) sehingga frontend dapat menampilkan informasi pembayaran lengkap.

---

### 6. 🎫 Sistem Support Tiket

Pelanggan dapat mengirim pesan bantuan melalui platform pilihan:

```
POST /api/support/send
Body: {
  "user_name": "Budi Santoso",
  "user_email": "budi@email.com",
  "message": "Token PLN saya belum masuk sejak 2 jam lalu",
  "platform": "telegram",
  "order_id": "FPJGGPQ8M7NTBV"
}
```

**Keunggulan:**
- Pesan **disimpan ke database terlebih dahulu** sebelum diteruskan ke Telegram
- Jika Telegram error/down, pesan tetap tersimpan dan tidak hilang
- Setiap tiket mendapat ID unik format `SUP000001`, `SUP000002`, dst.
- Notifikasi langsung ke admin via Telegram Bot

---

### 7. 📧 Email Notifikasi Otomatis

Pelanggan menerima email otomatis untuk dua kondisi:

#### Email Order Sukses (via Queue)

Dikirim melalui sistem antrian (Queue) agar response API tetap cepat — tidak menunggu SMTP selesai. Job `SendOrderSuccessEmail` berjalan di background:

```
Digiflazz callback sukses
  → DB update (sn, status: success)
  → SendOrderSuccessEmail::dispatch($order)  ← masuk queue
  → Queue worker process job (background)
  → SMTP kirim email ke customer_email
```

Konfigurasi retry:
```php
public int $tries   = 2;       // Maksimal 2 kali percobaan
public int $timeout = 60;      // Timeout per percobaan: 60 detik
public array $backoff = [30, 180]; // Retry ke-1: 30 detik, retry ke-2: 3 menit
```

Template email menyertakan:
- Order ID, nama produk, nomor tujuan
- **SN / Token PLN / Kode Voucher** dalam kotak hijau yang menonjol
- Total pembayaran, waktu transaksi
- Instruksi penyimpanan sebagai bukti transaksi

#### Email Order Gagal (sinkron)

Dikirim langsung (tidak via queue) agar pelanggan segera mengetahui kegagalan. Pesan kesalahan diterjemahkan dari bahasa teknis ke bahasa yang ramah pelanggan:

```php
// "saldo tidak mencukupi" → pesan yang lebih friendly
if (str_contains($r, 'saldo') || str_contains($r, 'balance')) {
    return 'Layanan sedang tidak tersedia sementara. Silakan coba lagi nanti.';
}
```

---

### 8. 📡 Telegram Admin Alerts Real-Time

Setiap event penting langsung dikirim ke Telegram admin. Sistem menggunakan `TelegramService::notify()` sebagai static helper:

#### 📦 Order Dikirim ke Digiflazz
```
📦 ORDER DIKIRIM KE DIGIFLAZZ
----------------------------------
Order ID: #FPJGGPQ8M7NTBV
Produk: Pulsa Telkomsel 50.000
Target: 08123456789
Nominal: Rp 52.000
----------------------------------
Menunggu konfirmasi provider...
```

#### ✅ Transaksi Sukses
```
✅ NOTIFIKASI TRANSAKSI FEEPAY
----------------------------------
Status: SUKSES
Produk: Token PLN 100.000
Nominal: Rp 103.500
Pembeli: budi@email.com
Order ID: #FPJGGPQ8M7NTBV
SN: 1234567890123456789012
----------------------------------
Laporan otomatis sistem FEEPAY.ID
```

#### ❌ Transaksi Gagal
```
❌ NOTIFIKASI TRANSAKSI FEEPAY
----------------------------------
Status: GAGAL
Produk: Pulsa XL 25.000
Nominal: Rp 26.500
Pembeli: siti@email.com
Order ID: #FPABCDEFGHIJ
Alasan: Nomor tujuan tidak valid
----------------------------------
```

#### ⚠️ Digiflazz Reject Order
```
⚠️ TRANSAKSI GAGAL
----------------------------------
Order ID: #FPABCDEFGHIJ
Produk: Pulsa Telkomsel 100.000
Target: 08199999999
Pesan: Saldo tidak mencukupi
----------------------------------
Cek saldo Digiflazz!
```

#### 🚨 System Error
```
🚨 SYSTEM ERROR: Gagal konfirmasi order #123.
```

#### 💸 Saldo Digiflazz Menipis (< Rp 100.000)
```
💸 WARNING: SALDO TIPIS!
----------------------------------
Sisa Saldo: Rp 87.500
----------------------------------
Segera Top Up saldo Digiflazz!
```

#### 🔔 Support Tiket Masuk
```
🔔 SUPPORT MESSAGE BARU - FEEPAY.ID

Ticket: SUP000017
Nama: Budi Santoso
Email: budi@email.com
Platform: Telegram

Pesan:
Token PLN saya belum masuk sejak 2 jam lalu

Waktu: 01 Mar 2026 16:38 WIB
```

---

### 9. 🔁 Idempotency — Anti Order Duplikat

Masalah umum: pelanggan klik tombol "Beli" dua kali karena loading lambat → dua order terbuat → dua kali bayar.

**Solusi FEEPAY.ID:** Header `X-Idempotency-Key` opsional dari frontend. Jika disertakan, sistem menyimpan hasil request ke cache selama 24 jam. Request identik yang dikirim ulang mendapat response yang sama tanpa membuat order baru:

```
POST /api/orders/create
X-Idempotency-Key: checkout-session-abc123-user-12345
Body: { "sku": "TSEL50", "target_number": "08123456789", ... }

→ Request pertama: order dibuat, disimpan ke cache
→ Request kedua (key sama): ambil dari cache, return response sama
```

```php
$cacheKey = 'idempotency:order:' . hash('sha256', $idempotencyKey);
$cached   = Cache::get($cacheKey);

if ($cached) {
    return response()->json(['data' => $cached]); // Return data lama
}
```

Hanya data minimal (non-sensitif) yang disimpan di cache: order_id, product_name, total_price, status.

---

### 10. ⚙️ Queue Worker (Background Jobs)

Sistem menggunakan database queue dengan **2 worker instance** yang dikelola Supervisor. Konfigurasi:

```ini
[program:feepay-worker]
command=php /var/www/feepay/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=2
```

**Kenapa 2 worker?**
- Worker 1: memproses email order sukses
- Worker 2: stand-by untuk traffic tinggi atau jika worker 1 sedang busy
- Jika salah satu crash, Supervisor otomatis restart

**Status queue worker:**
```bash
supervisorctl status
# feepay-worker:feepay-worker_00   RUNNING   pid 107067, uptime 0:19:29
# feepay-worker:feepay-worker_01   RUNNING   pid 107062, uptime 0:19:32
```

---

## 🔐 Keamanan Berlapis (10 Layer)

FEEPAY.ID menerapkan prinsip *Defense in Depth* — setiap layer independen, sehingga jika satu layer berhasil ditembus, layer berikutnya masih melindungi sistem.

### Layer 1 — HTTPS Enforcement

```php
// ForceHttps Middleware
if (!$request->secure() && config('app.env') === 'production') {
    return redirect()->secure($request->getRequestUri(), 301);
}
```

**Hanya aktif di production** — development tidak terganggu. Semua akses HTTP otomatis di-redirect ke HTTPS dengan status code 301 (permanent).

---

### Layer 2 — Security Headers

```php
// SecurityHeaders Middleware
$response->headers->set('X-Content-Type-Options',   'nosniff');
$response->headers->set('X-Frame-Options',           'DENY');
$response->headers->set('X-XSS-Protection',          '1; mode=block');
$response->headers->set('Referrer-Policy',            'strict-origin-when-cross-origin');
$response->headers->set('Permissions-Policy',         'geolocation=(), microphone=(), camera=()');
$response->headers->set('Content-Security-Policy',    "default-src 'self'");

// Production only
$response->headers->set('Strict-Transport-Security',
    'max-age=31536000; includeSubDomains; preload'
);

// Sembunyikan fingerprint server
$response->headers->remove('X-Powered-By');
$response->headers->remove('Server');
```

Header yang diset:

| Header | Fungsi |
|---|---|
| `X-Content-Type-Options: nosniff` | Cegah MIME sniffing attack |
| `X-Frame-Options: DENY` | Cegah clickjacking via iframe |
| `X-XSS-Protection: 1; mode=block` | Aktifkan XSS filter browser |
| `Content-Security-Policy` | Batasi sumber resource |
| `Strict-Transport-Security` | HSTS — paksa HTTPS 1 tahun |
| `Referrer-Policy` | Kontrol info yang dikirim ke referer |
| `Permissions-Policy` | Blokir akses geolokasi/kamera/mic |

---

### Layer 3 — Rate Limiting Per Endpoint

Setiap endpoint memiliki batas request yang disesuaikan dengan risikonya:

```php
// routes/api.php — throttle berbeda per kelompok
Route::middleware('throttle:5,1')   // Login admin: 5x/menit
Route::middleware('throttle:5,1')   // Support: 5x/menit
Route::middleware('throttle:20,1')  // Order & payment: 20x/menit
Route::middleware('throttle:100,1') // Webhook: 100x/menit (provider harus bisa masuk)
Route::middleware('throttle:60,1')  // Read-only publik: 60x/menit
```

**Rate limiter tambahan di AppServiceProvider** — berbasis email, bukan hanya IP:
```php
RateLimiter::for('login', function (Request $request) {
    $email = $request->input('email');
    $key   = $email ? 'login:' . $email : 'login:' . $request->ip();
    return Limit::perMinutes(5, 5)->by($key);
});
```

Ini mencegah brute force bahkan jika attacker menggunakan IP berbeda tapi email yang sama.

---

### Layer 4 — Laravel Sanctum Token Auth

```
Token expire : 24 jam
Single session: Token lama otomatis dihapus saat login baru
```

```php
// AuthController — single session enforcement
$user->tokens()->delete(); // Hapus semua token lama
$token = $user->createToken('admin', ['*'], now()->addHours(24));
```

Token direvoke saat logout:
```php
$request->user()->currentAccessToken()->delete();
```

Refresh token membuat token baru sekaligus menghapus yang lama — session tidak pernah tumpang tindih.

---

### Layer 5 — Secret Admin Path

Path admin tidak pernah statis. Dikonfigurasi via `.env` dan **tidak ada fallback** di production:

```php
$adminPath = config('feepay.admin_path');

if (empty($adminPath)) {
    if (config('app.env') === 'production') {
        throw new \RuntimeException(
            'ADMIN_PATH_PREFIX belum diset! Wajib diisi sebelum deploy.'
        );
    }
}

Route::prefix("admin/{$adminPath}")->group(function () {
    // Endpoint admin
});
```

Tanpa tahu `ADMIN_PATH_PREFIX`, attacker tidak bisa menemukan endpoint admin meskipun memiliki token valid.

---

### Layer 6 — Admin PIN Verification

Header `X-Admin-PIN` wajib disertakan untuk seluruh protected admin endpoint:

```php
// VerifyPinMiddleware
$pin         = $request->header('X-Admin-Pin');
$correctPin  = config('feepay.admin_pin');

// Rate limit: 5 percobaan salah per 15 menit per IP
$rateLimitKey = 'pin_attempts:' . $request->ip();
if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
    return response()->json(['message' => 'Terlalu banyak percobaan PIN.'], 429);
}

// hash_equals() mencegah timing attack
if (!hash_equals((string) $correctPin, (string) $pin)) {
    RateLimiter::hit($rateLimitKey, 900); // Catat percobaan gagal
    return response()->json(['message' => 'PIN tidak valid.'], 403);
}
```

`hash_equals()` memastikan perbandingan string membutuhkan waktu yang sama terlepas dari berapa karakter yang cocok — ini mencegah *timing attack* di mana attacker bisa menebak PIN karakter per karakter berdasarkan response time.

---

### Layer 7 — IP Whitelist Admin

```php
// AdminIpWhitelist Middleware
$allowedIps = array_filter(
    array_map('trim', explode(',', config('app.admin_allowed_ips', '')))
);

// Production: blokir semua jika ADMIN_ALLOWED_IPS kosong
if (empty($allowedIps)) {
    if (config('app.env') === 'production') {
        Log::critical('ADMIN_ALLOWED_IPS belum diset di production!');
        return response()->json(['message' => 'Akses ditolak.'], 403);
    }
}

if (!in_array($request->ip(), $allowedIps)) {
    Log::warning('Akses admin ditolak dari IP tidak dikenal', [
        'ip' => $request->ip()
    ]);
    return response()->json(['message' => 'Akses ditolak dari IP ini.'], 403);
}
```

Konfigurasi di `.env`:
```
ADMIN_ALLOWED_IPS=103.x.x.x,180.x.x.x
```

Bahkan dengan token valid + PIN benar + path benar, akses dari IP tidak terdaftar tetap ditolak.

---

### Layer 8 — Webhook Signature Verification

**Midtrans — SHA-512:**
```php
$expectedSignature = hash('sha512',
    $orderId . $statusCode . $grossAmount . $serverKey
);
$isValid = hash_equals($expectedSignature, $receivedSignature);
```

**Digiflazz — MD5:**
```php
$expectedSign = md5($username . $apiKey . $refId);
if (!hash_equals($expectedSign, (string) $receivedSign)) {
    return response()->json(['message' => 'Signature tidak valid.'], 401);
}
```

Keduanya juga memverifikasi:
- Signature tidak boleh kosong
- `ref_id` / `order_id` tidak boleh kosong
- IP address Digiflazz harus terdaftar di `DIGIFLAZZ_ALLOWED_IPS`

---

### Layer 9 — Race Condition Guard

Masalah: Midtrans bisa mengirim webhook yang sama dua kali bersamaan (network retry). Tanpa proteksi, satu order bisa diproses dua kali ke Digiflazz → pelanggan kena charge dua kali ke saldo Digiflazz.

**Solusi: `lockForUpdate()` + `confirmed_at` flag:**
```php
DB::transaction(function () use ($order) {
    // Kunci row di database — request lain harus tunggu
    $locked = Order::lockForUpdate()->find($order->id);

    // Jika sudah ada confirmed_at, order sudah pernah dikirim — skip
    if ($locked->confirmed_at !== null) {
        return; // Request duplikat diabaikan
    }

    // Set flag SEBELUM call API eksternal
    $locked->update(['confirmed_at' => now()]);

    // Baru call Digiflazz
    $digiflazzService->purchaseProduct(...);
});
```

Dengan pola ini, bahkan jika dua webhook settlement datang bersamaan dalam milisekon yang sama, hanya satu yang akan berhasil `set confirmed_at` — yang lain akan melihat field sudah terisi dan skip.

---

### Layer 10 — Input Sanitization & Validation

**FormRequest dengan custom rules:**
```php
// StoreOrderRequest
'target_number' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9\-]+$/'],
'zone_id'       => ['nullable', 'string', 'max:20', 'regex:/^\d+$/'],
```

`target_number` hanya boleh mengandung huruf, angka, dan tanda minus. Karakter spesial seperti `'`, `"`, `;`, `--` (SQL injection patterns) langsung ditolak.

**Midtrans sanitize:**
```php
Config::$isSanitized = true; // Midtrans sanitize input
Config::$is3ds       = true; // Wajib 3DS untuk kartu kredit
```

---

### Ringkasan Semua Layer

| # | Layer | Middleware / Mekanisme | Target Serangan |
|---|---|---|---|
| 1 | HTTPS | `ForceHttps` | Man-in-the-middle |
| 2 | Security Headers | `SecurityHeaders` | XSS, clickjacking, MIME sniffing |
| 3 | Rate Limiting | `throttle:N,M` per endpoint | Brute force, DDoS |
| 4 | Token Auth | `auth:sanctum` | Unauthorized access |
| 5 | Secret Path | `ADMIN_PATH_PREFIX` | Path enumeration |
| 6 | Admin PIN | `VerifyPinMiddleware` | Stolen token attack |
| 7 | IP Whitelist | `AdminIpWhitelist` | Access from unknown location |
| 8 | Webhook Signature | SHA-512 + MD5 + `hash_equals` | Fake webhook injection |
| 9 | Race Condition | `lockForUpdate()` + flag | Double-processing |
| 10 | Input Sanitize | FormRequest + Midtrans sanitize | SQL injection, XSS input |

---

## 📂 Struktur Endpoint API

### 🌐 Public Endpoints

| Method | Endpoint | Fungsi | Rate Limit |
|---|---|---|---|
| `GET` | `/api/health` | Health check — cek API aktif | — |
| `GET` | `/api/products` | Daftar produk aktif (opsional `?category=`) | 60/menit |
| `POST` | `/api/orders/create` | Buat order baru | 20/menit |
| `POST` | `/api/orders/{orderId}` | Cek status order (butuh `email` di body) | 60/menit |
| `GET` | `/api/payment/status/{orderId}` | Polling status pembayaran (untuk halaman checkout) | 60/menit |
| `POST` | `/api/payments/midtrans/create` | Buat Snap Token Midtrans | 20/menit |
| `POST` | `/api/midtrans/webhook` | Notifikasi pembayaran dari Midtrans | 100/menit |
| `POST` | `/api/callback/digiflazz` | Callback status order dari Digiflazz | 100/menit |
| `POST` | `/api/support/send` | Kirim pesan support | 5/menit |
| `GET` | `/api/support/contacts` | Info kontak support | 5/menit |
| `POST` | `/api/admin/login` | Login admin | 5/menit |

### 🔑 Admin Endpoints — Token Only

| Method | Endpoint | Fungsi |
|---|---|---|
| `POST` | `/api/admin/logout` | Revoke token aktif |
| `GET` | `/api/admin/me` | Data admin yang sedang login |
| `POST` | `/api/admin/refresh` | Refresh token (hapus lama, buat baru) |

### 🛡️ Admin Endpoints — Token + PIN + Secret Path

> Semua endpoint berikut memerlukan:
> - Header `Authorization: Bearer {token}`
> - Header `X-Admin-PIN: {6_digit_pin}`
> - Path `{secret}` = nilai `ADMIN_PATH_PREFIX` di `.env`

| Method | Endpoint | Fungsi |
|---|---|---|
| `GET` | `/api/admin/{secret}/dashboard/stats` | Statistik order & revenue (support filter tanggal) |
| `GET` | `/api/admin/{secret}/dashboard/products` | Statistik produk aktif/total per kategori |
| `GET` | `/api/admin/{secret}/dashboard/balance` | Cek saldo Digiflazz real-time |
| `GET` | `/api/admin/{secret}/orders` | Daftar semua order + histori status (paginated 50) |
| `POST` | `/api/admin/{secret}/orders/{id}/confirm` | Konfirmasi manual & kirim order ke Digiflazz |
| `POST` | `/api/admin/{secret}/orders/{orderId}/sync` | Sinkronisasi status order dari Digiflazz |
| `POST` | `/api/admin/{secret}/products/sync` | Sync katalog produk dari Digiflazz |
| `POST` | `/api/admin/{secret}/products/bulk-margin` | Set margin ke semua produk sekaligus |
| `PUT` | `/api/admin/{secret}/products/{id}` | Update harga jual satu produk |

---

## 🔄 Alur Transaksi End-to-End

Berikut adalah alur lengkap dari pelanggan order hingga SN diterima:

```
┌─────────────────────────────────────────────────────────────────────┐
│                   ALUR TRANSAKSI FEEPAY.ID                          │
│                                                                     │
│  1. Pelanggan pilih produk                                          │
│     GET /api/products                                               │
│     ↓                                                               │
│  2. Pelanggan buat order                                            │
│     POST /api/orders/create                                         │
│     → Validasi SKU & email                                          │
│     → Harga diambil dari DB (tidak dari request)                    │
│     → Order dibuat, status: PENDING                                 │
│     → Idempotency key dicek (cegah double order)                    │
│     ↓                                                               │
│  3. Pelanggan request Snap Token                                     │
│     POST /api/payments/midtrans/create                              │
│     → Token lama reuse jika sudah ada                               │
│     → Harga dari DB dikirim ke Midtrans (bukan dari user)           │
│     → Snap Token dikembalikan ke frontend                           │
│     ↓                                                               │
│  4. Pelanggan bayar via Midtrans UI                                 │
│     (VA BNI/BCA/BRI, GoPay, QRIS, dll.)                            │
│     ↓                                                               │
│  5. Midtrans kirim webhook ke server                                │
│     POST /api/midtrans/webhook                                      │
│     → Signature SHA-512 diverifikasi                                │
│     → payment_type dinormalisasi (bank_transfer → bni_va)           │
│     → Jika settlement/capture + fraud=accept:                       │
│       → Status order → PROCESSING                                   │
│       → processToDigiflazz() dipanggil                              │
│     ↓                                                               │
│  6. Order dikirim ke Digiflazz                                      │
│     → lockForUpdate() cegah double-send                             │
│     → confirmed_at diset sebelum API call                           │
│     → purchaseProduct() dipanggil                                   │
│     → Telegram alert: "📦 Order dikirim ke Digiflazz"              │
│     ↓                                                               │
│  7. Digiflazz proses & kirim callback                               │
│     POST /api/callback/digiflazz                                    │
│     → Signature MD5 diverifikasi                                    │
│     → IP Digiflazz diverifikasi                                     │
│     → Jika status Sukses:                                           │
│       → SN disimpan ke order                                        │
│       → Status order → SUCCESS                                      │
│       → Telegram alert: "✅ Transaksi sukses"                       │
│       → SendOrderSuccessEmail::dispatch() → masuk queue             │
│     ↓                                                               │
│  8. Queue Worker kirim email ke pelanggan                           │
│     → Email berisi SN/Token                                         │
│     → Retry otomatis jika gagal (2x, backoff 30s/3min)             │
│     ↓                                                               │
│  9. Pelanggan cek riwayat transaksi                                 │
│     POST /api/orders/{orderId}                                      │
│     → SN tersedia di response                                       │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 🗄️ Struktur Database

### Tabel `products`

| Kolom | Tipe | Index | Keterangan |
|---|---|---|---|
| `id` | bigint UNSIGNED | PRIMARY | Primary key |
| `sku` | varchar(100) | UNIQUE | SKU unik dari Digiflazz |
| `name` | varchar(255) | — | Nama produk |
| `category` | varchar(100) | INDEX | Kategori untuk filter |
| `brand` | varchar(100) | — | Brand/operator |
| `type` | varchar(50) | — | `standard` / tipe lain |
| `cost_price` | decimal(15,2) | — | Harga modal dari Digiflazz |
| `selling_price` | decimal(15,2) | — | Harga jual ke pelanggan |
| `status` | enum | INDEX | `active` / `inactive` |
| `stock` | varchar(50) | — | Default: `unlimited` |
| `created_at` | timestamp | — | — |
| `updated_at` | timestamp | — | — |

*Computed (tidak di DB):* `profit_margin = selling_price - cost_price`

---

### Tabel `orders`

| Kolom | Tipe | Index | Keterangan |
|---|---|---|---|
| `id` | bigint UNSIGNED | PRIMARY | Primary key |
| `order_id` | varchar(20) | UNIQUE | Format: `FP` + 12 karakter random uppercase |
| `sku` | varchar(100) | INDEX | SKU produk yang dipesan |
| `product_name` | varchar(255) | — | Snapshot nama produk saat order |
| `target_number` | varchar(50) | — | Nomor HP / ID akun game |
| `zone_id` | varchar(20) | — | Server ID game (Mobile Legends, dll.) |
| `customer_email` | varchar(255) | INDEX | Email pelanggan (disimpan lowercase) |
| `total_price` | decimal(15,2) | — | Harga dari DB saat order dibuat |
| `status` | enum | INDEX | `pending` / `processing` / `success` / `failed` |
| `sn` | text | — | Serial Number / Token / Kode Voucher |
| `payment_id` | varchar(100) | — | Payment ID opsional |
| `confirmed_by` | bigint | FK | ID admin yang konfirmasi (nullable) |
| `confirmed_at` | timestamp | — | Flag anti double-send (nullable) |
| `midtrans_snap_token` | varchar(255) | — | Snap Token Midtrans |
| `midtrans_transaction_id` | varchar(100) | — | Nomor VA atau transaction UUID |
| `midtrans_payment_type` | varchar(50) | — | `bni_va`, `gopay`, `qris`, dll. |
| `midtrans_transaction_status` | varchar(50) | — | `pending`, `settlement`, dll. |
| `midtrans_transaction_time` | timestamp | — | Waktu transaksi Midtrans |
| `deleted_at` | timestamp | — | Soft delete (data tidak hilang) |
| `created_at` | timestamp | INDEX | Dipakai untuk filter statistik |
| `updated_at` | timestamp | — | — |

---

### Tabel `order_status_histories`

Audit trail lengkap — setiap perubahan status order tercatat permanen.

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | Primary key |
| `order_id` | bigint (FK) | Relasi ke `orders.id` |
| `status` | enum | Status baru: `pending` / `processing` / `success` / `failed` |
| `note` | text | Catatan perubahan (contoh: "Sukses via callback Digiflazz. SN: 123...") |
| `changed_by` | bigint (nullable, FK) | ID admin yang mengubah (null jika otomatis via webhook) |
| `created_at` | timestamp | Waktu perubahan |

---

### Tabel `support_messages`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | Primary key (dipakai sebagai ticket ID: `SUP000001`) |
| `user_name` | varchar(255) | Nama pengirim |
| `user_email` | varchar(255) | Email pengirim |
| `message` | text | Isi pesan |
| `platform` | enum | `whatsapp` / `telegram` |
| `status` | enum | `pending` / `sent` / `failed` |
| `order_id` | varchar(20) | Order ID terkait (nullable) |
| `created_at` | timestamp | — |

---

### Tabel `users`

Tabel khusus admin. Diisi via seeder dari nilai `.env`.

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | Primary key |
| `name` | varchar(255) | Nama admin |
| `email` | varchar(255) | Email admin (UNIQUE) |
| `password` | varchar(255) | Bcrypt hash |
| `created_at` | timestamp | — |

---

## 📁 Struktur Folder Proyek

```
backend-FEEPAY.ID/
│
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       └── SyncDigiflazz.php           # Artisan: php artisan digiflazz:sync [--category=]
│   │
│   ├── Enums/
│   │   └── OrderStatus.php                 # Enum PHP 8.1: PENDING, PROCESSING, SUCCESS, FAILED
│   │                                       # Method: label(), color(), isFinal()
│   │
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── AuthController.php          # Login, logout, me, refresh (single session)
│   │   │   ├── OrderController.php         # store, confirm, sync, index, show
│   │   │   ├── MidtransPaymentController.php  # createPayment, handleNotification, processToDigiflazz
│   │   │   ├── CallbackController.php      # Digiflazz callback handler
│   │   │   ├── ProductController.php       # index, sync, bulkUpdateMargin, update
│   │   │   ├── DashboardController.php     # stats, productStats, getBalance
│   │   │   └── SupportController.php       # send, getContacts
│   │   │
│   │   ├── Middleware/
│   │   │   ├── AdminIpWhitelist.php        # Layer 7: IP whitelist untuk admin
│   │   │   ├── ForceHttps.php              # Layer 1: Redirect HTTP → HTTPS
│   │   │   ├── SecurityHeaders.php         # Layer 2: CSP, HSTS, X-Frame, dll.
│   │   │   └── VerifyPinMiddleware.php     # Layer 6: X-Admin-PIN + rate limiting
│   │   │
│   │   ├── Requests/
│   │   │   ├── AdminLoginRequest.php       # Validasi form login admin
│   │   │   ├── StoreOrderRequest.php       # Validasi buat order + custom zone_id rule
│   │   │   └── ConfirmOrderRequest.php     # Validasi konfirmasi order manual
│   │   │
│   │   └── Kernel.php                      # Registrasi alias middleware
│   │
│   ├── Jobs/
│   │   └── SendOrderSuccessEmail.php       # Queue job: email sukses (tries=2, backoff=[30,180])
│   │
│   ├── Mail/
│   │   ├── OrderSuccess.php                # Mailable: email order berhasil + SN
│   │   └── OrderFailed.php                 # Mailable: email order gagal + alasan
│   │
│   ├── Models/
│   │   ├── Order.php                       # Fillable lengkap, SoftDeletes, scopes, helpers
│   │   ├── Product.php                     # Computed profit_margin, updateOrCreate
│   │   ├── OrderStatusHistory.php          # Audit trail status order
│   │   ├── SupportMessage.php              # Pesan support pelanggan
│   │   └── User.php                        # Model admin
│   │
│   ├── Providers/
│   │   └── AppServiceProvider.php          # Rate limiter: login, api, transactions, topup, webhook
│   │
│   └── Services/
│       ├── DigiflazzService.php            # purchaseProduct, getPriceList, checkStatus, getBalance, syncProducts
│       ├── MidtransService.php             # createSnapToken, verifySignature
│       └── TelegramService.php             # Static notify() — kirim pesan ke Telegram
│
├── config/
│   ├── feepay.php                          # margin, admin_path, admin_pin, support contacts
│   ├── midtrans.php                        # server_key, client_key, is_production
│   ├── cors.php                            # Whitelist origin: feepay.web.id, feepay.id
│   ├── queue.php                           # Driver: database
│   └── ...
│
├── database/
│   ├── migrations/
│   │   ├── ..._create_products_table.php
│   │   ├── ..._create_orders_table.php
│   │   ├── ..._create_order_status_histories_table.php
│   │   ├── ..._create_support_messages_table.php
│   │   ├── ..._create_users_table.php
│   │   ├── ..._create_jobs_table.php       # Queue jobs table
│   │   ├── ..._create_cache_table.php
│   │   └── ..._add_soft_deletes_and_indexes_to_orders.php
│   │
│   └── seeders/
│       ├── AdminSeeder.php                 # Seed admin dari ADMIN_EMAIL + ADMIN_SEED_PASSWORD
│       └── DatabaseSeeder.php
│
├── resources/views/
│   ├── emails/
│   │   ├── order-success.blade.php         # Template email sukses dengan SN prominent
│   │   └── order-failed.blade.php          # Template email gagal dengan alasan
│   └── payment/
│       └── checkout.blade.php              # Halaman checkout Midtrans Snap
│
├── routes/
│   ├── api.php                             # Semua route API dengan rate limiting
│   └── web.php                             # Web routes (minimal)
│
├── .env.example                            # Template .env lengkap dengan dokumentasi
└── artisan
```

---

## 🚀 Panduan Instalasi

### Prasyarat

| Kebutuhan | Versi Minimum | Cek |
|---|---|---|
| PHP | 8.2+ | `php -v` |
| Composer | 2.x | `composer -V` |
| MySQL | 8.0+ | `mysql --version` |
| Node.js | 18+ | `node -v` |
| Supervisor | 4.x | `supervisorctl version` |

---

### Langkah 1 — Clone Repository

```bash
git clone https://github.com/fetrusmeilanoilhamsyah/backend-FEEPAY.ID.git
cd backend-FEEPAY.ID
```

---

### Langkah 2 — Install Dependencies

```bash
# PHP dependencies
composer install --optimize-autoloader --no-dev

# Node dependencies (untuk asset build)
npm install
```

---

### Langkah 3 — Konfigurasi Environment

```bash
cp .env.example .env
nano .env
```

Isi minimal yang wajib:
```env
APP_KEY=              # Diisi oleh php artisan key:generate
APP_URL=https://api.feepay.web.id
DB_PASSWORD=          # Password MySQL kuat
ADMIN_PATH_PREFIX=    # String acak panjang min 12 karakter — WAJIB
FEEPAY_ADMIN_PIN=     # 6 digit — WAJIB
DIGIFLAZZ_USERNAME=
DIGIFLAZZ_API_KEY=
MIDTRANS_SERVER_KEY=
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
MAIL_USERNAME=
MAIL_PASSWORD=
```

---

### Langkah 4 — Generate Key & Migrasi

```bash
php artisan key:generate
php artisan migrate --seed
```

Seeder membuat akun admin dari `ADMIN_EMAIL` dan `ADMIN_SEED_PASSWORD` di `.env`.

---

### Langkah 5 — Set Permission

```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

---

### Langkah 6 — Konfigurasi Supervisor

Buat file `/etc/supervisor/conf.d/feepay-worker.conf`:

```ini
[program:feepay-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/feepay/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/feepay/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start feepay-worker:*
sudo supervisorctl status
```

---

### Langkah 7 — Konfigurasi Nginx

```nginx
server {
    listen 80;
    server_name api.feepay.web.id;
    root /var/www/feepay/public;
    index index.php;

    # Security: sembunyikan dot files
    location ~ /\. {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Cegah akses ke file sensitif
    location ~ /\.env {
        deny all;
    }
}
```

```bash
sudo certbot --nginx -d api.feepay.web.id
sudo nginx -t && sudo systemctl reload nginx
```

---

### Langkah 8 — Setup Cron Job

```bash
crontab -e
```

Tambahkan:
```
* * * * * cd /var/www/feepay && php artisan schedule:run >> /dev/null 2>&1
```

---

### Langkah 9 — Sync Produk Pertama Kali

```bash
php artisan digiflazz:sync
```

---

### Langkah 10 — Konfigurasi Webhook

1. Login ke dashboard Midtrans
2. Set **Notification URL** ke: `https://api.feepay.web.id/api/midtrans/webhook`
3. Login ke dashboard Digiflazz
4. Set **Callback URL** ke: `https://api.feepay.web.id/api/callback/digiflazz`

---

## 🖥️ Development vs Production

### Mode Development — 5 Terminal

```bash
# Terminal 1 — Backend API
php artisan serve

# Terminal 2 — Frontend Hot Reload
npm run dev

# Terminal 3 — Tunnel untuk Webhook (Midtrans butuh HTTPS publik)
ngrok http 8000
# Setelah ngrok aktif, set URL tunnel ke dashboard Midtrans & Digiflazz

# Terminal 4 — Scheduler (auto-sync produk tiap jam)
php artisan schedule:work

# Terminal 5 — Queue Worker (email notifikasi)
php artisan queue:work
```

### Mode Production

| Aspek | Development | Production |
|---|---|---|
| Server | `php artisan serve` | Nginx + PHP-FPM |
| Frontend | `npm run dev` | `npm run build` |
| Webhook | ngrok tunnel | Domain HTTPS langsung |
| Scheduler | `php artisan schedule:work` | Crontab `* * * * *` |
| Queue | Terminal manual | Supervisor (2 worker, auto-restart) |
| Debug | `APP_DEBUG=true` | `APP_DEBUG=false` ⚠️ |
| Log level | `debug` | `error` |
| HTTPS | Opsional | Wajib (diforce redirect) |

### Script Deploy Cepat

Simpan sebagai `/var/www/feepay/deploy.sh`:

```bash
#!/bin/bash
set -e

echo "🚀 Starting deployment..."

cd /var/www/feepay

git pull origin main

echo "📦 Clearing cache..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo "🔄 Running migrations..."
php artisan migrate --force

echo "⚙️ Restarting workers..."
supervisorctl restart feepay-worker:*

echo "✅ Deployment complete!"
supervisorctl status feepay-worker:*
```

```bash
chmod +x deploy.sh
bash deploy.sh
```

---

## 🔄 Jadwal Auto-Sync Produk

Produk dari Digiflazz disinkronkan **4x sehari** secara otomatis via Laravel Scheduler:

| Waktu | Tujuan |
|---|---|
| **00:00** | Sync malam — tangkap perubahan harga akhir hari |
| **06:00** | Sync pagi — update sebelum traffic tinggi |
| **12:00** | Sync siang — update tengah hari |
| **18:00** | Sync sore — update sebelum peak hour malam |

**Proteksi harga:**
```php
// Saat sync — harga jual yang masih profitable tidak ditimpa
if ($product->selling_price > $newCostPrice) {
    // Margin masih aman — skip update selling_price
} else {
    // Harga modal naik melebihi harga jual — auto-adjust
    $product->selling_price = $newCostPrice + config('feepay.margin');
}
```

**Trigger manual:**
```bash
# Sync semua kategori
php artisan digiflazz:sync

# Sync satu kategori saja
php artisan digiflazz:sync --category="Pulsa"
php artisan digiflazz:sync --category="PLN"
php artisan digiflazz:sync --category="Games"
```

**Via API (admin):**
```
POST /api/admin/{secret}/products/sync
Body: { "category": "Pulsa" }   ← opsional
```

---

## 🔧 Environment Variables Lengkap

```env
# ═══════════════════════════════════════════════════════════
# APLIKASI
# ═══════════════════════════════════════════════════════════
APP_NAME="FEEPAY.ID"
APP_ENV=production
APP_KEY=base64:GENERATE_DENGAN_php_artisan_key_generate
APP_DEBUG=false                    # WAJIB false di production — exposing stack trace
APP_URL=https://api.feepay.web.id
APP_TIMEZONE=Asia/Jakarta

# ═══════════════════════════════════════════════════════════
# DATABASE
# ═══════════════════════════════════════════════════════════
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=feepay_db
DB_USERNAME=feepay_user
DB_PASSWORD=PASSWORD_KUAT_MIN_16_KARAKTER_GABUNGKAN_HURUF_ANGKA_SIMBOL

# ═══════════════════════════════════════════════════════════
# KEAMANAN ADMIN — WAJIB DISET, AKAN THROW ERROR JIKA KOSONG
# ═══════════════════════════════════════════════════════════
ADMIN_PATH_PREFIX=STRING_ACAK_PANJANG_MIN_12_KARAKTER
# Contoh: xk9mq2wz8vbr
# JANGAN pakai: admin, dashboard, manage, panel, cms

FEEPAY_ADMIN_PIN=6DIGITANGKA
# Contoh: 847291
# JANGAN pakai: 123456, 000000, tanggal lahir

ADMIN_ALLOWED_IPS=IP_VPS_KAMU,IP_RUMAH_KAMU
# Contoh: 103.28.xx.xx,180.244.xx.xx
# Pisahkan dengan koma jika lebih dari satu IP
# Kosongkan untuk allow all (tidak disarankan di production)

# ═══════════════════════════════════════════════════════════
# DIGIFLAZZ
# ═══════════════════════════════════════════════════════════
DIGIFLAZZ_USERNAME=username_dari_dashboard_digiflazz
DIGIFLAZZ_API_KEY=api_key_dari_dashboard_digiflazz
DIGIFLAZZ_BASE_URL=https://api.digiflazz.com/v1
DIGIFLAZZ_ALLOWED_IPS=                             # IP server Digiflazz (opsional)
DIGIFLAZZ_TIMEOUT=30                               # Timeout API call dalam detik

# ═══════════════════════════════════════════════════════════
# MIDTRANS
# ═══════════════════════════════════════════════════════════
MIDTRANS_SERVER_KEY=Mid-server-xxxxxxxxxxxxxxxxxxxx
MIDTRANS_CLIENT_KEY=Mid-client-xxxxxxxxxxxxxxxxxxxx
MIDTRANS_IS_PRODUCTION=true                        # false untuk sandbox/testing

# ═══════════════════════════════════════════════════════════
# TELEGRAM (NOTIFIKASI ADMIN)
# ═══════════════════════════════════════════════════════════
TELEGRAM_BOT_TOKEN=1234567890:AAFxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TELEGRAM_CHAT_ID=987654321                         # Chat ID admin (bisa grup atau personal)

# ═══════════════════════════════════════════════════════════
# EMAIL
# ═══════════════════════════════════════════════════════════
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com                     # Atau: smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=email_smtp_anda@domain.com
MAIL_PASSWORD=app_password_bukan_password_biasa
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@feepay.id"
MAIL_FROM_NAME="FEEPAY.ID"

# ═══════════════════════════════════════════════════════════
# SUPPORT CONTACTS
# ═══════════════════════════════════════════════════════════
SUPPORT_WHATSAPP=62812XXXXXXXX                     # Nomor WA tanpa +, dengan kode negara
SUPPORT_TELEGRAM=@USERNAME_TELEGRAM
SUPPORT_EMAIL=support@feepay.id

# ═══════════════════════════════════════════════════════════
# BISNIS
# ═══════════════════════════════════════════════════════════
FEEPAY_MARGIN=2000                                 # Margin default Rp untuk produk baru (saat sync)

# ═══════════════════════════════════════════════════════════
# QUEUE & CACHE
# ═══════════════════════════════════════════════════════════
QUEUE_CONNECTION=database
CACHE_DRIVER=file
CACHE_PREFIX=feepay

# ═══════════════════════════════════════════════════════════
# SESSION & SANCTUM
# ═══════════════════════════════════════════════════════════
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_DOMAIN=.feepay.web.id
SANCTUM_STATEFUL_DOMAINS=feepay.web.id,api.feepay.web.id
SANCTUM_TOKEN_EXPIRATION=1440                      # Token expire 24 jam (dalam menit)

# ═══════════════════════════════════════════════════════════
# SEEDER (dipakai sekali saat php artisan migrate --seed)
# ═══════════════════════════════════════════════════════════
ADMIN_EMAIL=emailadmin@domain.com
ADMIN_SEED_PASSWORD=PASSWORD_KUAT_UNTUK_AKUN_ADMIN

# ═══════════════════════════════════════════════════════════
# LOGGING
# ═══════════════════════════════════════════════════════════
LOG_CHANNEL=single
LOG_LEVEL=error                                    # Di production: hanya error, bukan debug
```

---

## 🛡️ Security Best Practices

### ① Wajib Set Sebelum Deploy ke Production

```bash
# Cek semua env wajib sudah terisi
php artisan config:show feepay
```

Dua variabel yang akan menyebabkan `RuntimeException` jika kosong di production:
- `ADMIN_PATH_PREFIX`
- `FEEPAY_ADMIN_PIN`

---

### ② APP_DEBUG Harus False di Production

```env
APP_DEBUG=false   # ← WAJIB
```

Jika `true`, Laravel menampilkan full stack trace, config values, dan environment variables kepada siapa saja yang mengakses URL yang error. Ini adalah celah keamanan yang sangat serius.

---

### ③ Gunakan App Password untuk Gmail

Jangan pernah pakai password Gmail biasa di `.env`. Buat App Password khusus:
```
https://myaccount.google.com/apppasswords
```

Atau gunakan Brevo (ex-Sendinblue) yang lebih stabil untuk transactional email.

---

### ④ Rotasi Credentials Berkala

| Credential | Rotasi |
|---|---|
| `ADMIN_PATH_PREFIX` | Setiap 3 bulan atau jika dicurigai bocor |
| `FEEPAY_ADMIN_PIN` | Setiap bulan |
| `APP_KEY` | Jangan dirotasi kecuali terjadi kebocoran (session akan invalid) |
| `MIDTRANS_SERVER_KEY` | Sesuai kebijakan Midtrans |

---

### ⑤ Monitor Log Secara Berkala

```bash
# Log error terbaru
tail -f /var/www/feepay/storage/logs/laravel.log

# Cari akses mencurigakan
grep "Akses admin ditolak" /var/www/feepay/storage/logs/laravel.log
grep "signature TIDAK VALID" /var/www/feepay/storage/logs/laravel.log
grep "IP ASING" /var/www/feepay/storage/logs/laravel.log
```

---

### ⑥ Backup Database Rutin

```bash
# Simpan di crontab
0 2 * * * mysqldump -u feepay_user -p feepay_db > /backup/feepay_$(date +\%Y\%m\%d).sql
```

---

## 🔍 Troubleshooting

### ❌ Webhook Midtrans tidak masuk

```bash
# Cek log
grep "Midtrans webhook" /var/www/feepay/storage/logs/laravel.log

# Kemungkinan penyebab:
# 1. APP_URL bukan HTTPS
# 2. Signature key salah di .env
# 3. Rate limit terlampaui (> 100/menit)
```

---

### ❌ Email tidak terkirim

```bash
# Cek queue worker jalan
supervisorctl status feepay-worker:*

# Cek failed jobs
php artisan queue:failed

# Retry semua failed jobs
php artisan queue:retry all

# Cek log
grep "SendOrderSuccessEmail" /var/www/feepay/storage/logs/laravel.log
```

---

### ❌ Order stuck di "processing"

```bash
# Sinkronisasi manual via admin endpoint
POST /api/admin/{secret}/orders/{orderId}/sync

# Atau cek log Digiflazz callback
grep "Digiflazz callback" /var/www/feepay/storage/logs/laravel.log
```

---

### ❌ git pull gagal karena perubahan lokal

```bash
git stash
git pull origin main
php artisan config:clear && php artisan cache:clear
supervisorctl restart feepay-worker:*
```

---

### ❌ git push error SSL (Windows)

```bash
git config --global http.sslBackend openssl
git push origin main
git config --global http.sslVerify true
```

---

### ❌ Admin tidak bisa akses dashboard

```bash
# Cek IP whitelist
grep "Akses admin ditolak" storage/logs/laravel.log

# Tambah IP ke .env
ADMIN_ALLOWED_IPS=IP_LAMA,IP_BARU

php artisan config:clear
```

---

## 📞 Kontak Pengembang

<div align="center">

**Fetrus Meilano Ilhamsyah**
*Backend Developer — FEEPAY.ID*

| Platform | Kontak |
|---|---|
| 💬 **Telegram** | [@FEE999888](https://t.me/FEE999888) |
| 📧 **Email** | fetrusmeilanoilham@gmail.com |
| 🐙 **GitHub** | [fetrusmeilanoilhamsyah](https://github.com/fetrusmeilanoilhamsyah) |
| 🌐 **Website** | [feepay.web.id](https://feepay.web.id) |

---

*"Platform ini dibangun dari nol, dari riset mandiri, dengan semangat belajar yang tidak pernah padam."*

**FEEPAY.ID** — Solusi Digital Marketplace & PPOB Terpercaya

*Dibuat dengan ❤️ dan 20+ jam debug oleh Fetrus Meilano Ilhamsyah*

![Visitor](https://img.shields.io/badge/Project%20Type-Production%20Backend-16a34a?style=flat-square)
![Made with](https://img.shields.io/badge/Made%20with-Laravel%2011-FF2D20?style=flat-square&logo=laravel)
![Security](https://img.shields.io/badge/Security-10%20Layers-blue?style=flat-square)

</div>