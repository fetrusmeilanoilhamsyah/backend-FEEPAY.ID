# 🏦 FEEPAY.ID — Digital Product & PPOB Platform

> Platform jual-beli produk digital lengkap: **Pulsa, Kuota Data, Token PLN, Top Up Game, dan Voucher Game**. Ditenagai oleh Digiflazz sebagai provider PPOB, Midtrans sebagai payment gateway, dengan sistem keamanan berlapis dan notifikasi real-time via Telegram.

![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Midtrans](https://img.shields.io/badge/Midtrans-Payment-003E6B?style=for-the-badge)
![Digiflazz](https://img.shields.io/badge/Digiflazz-PPOB-F7941D?style=for-the-badge)

---

## 📑 Daftar Isi

- [Gambaran Umum](#-gambaran-umum)
- [Arsitektur & Teknologi](#️-arsitektur--teknologi)
- [Fitur Lengkap](#-fitur-lengkap)
- [Keamanan Berlapis](#-keamanan-berlapis)
- [Struktur Endpoint API](#-struktur-endpoint-api)
- [Struktur Database](#-struktur-database)
- [Struktur Folder Proyek](#-struktur-folder-proyek)
- [Panduan Instalasi](#-panduan-instalasi)
- [Development vs Production](#️-development-vs-production)
- [Jadwal Auto-Sync Produk](#-jadwal-auto-sync-produk)
- [Environment Variables](#-environment-variables)
- [Security Best Practices](#-security-best-practices)
- [Kontak Pengembang](#-kontak-pengembang)

---

## 🎯 Gambaran Umum

FEEPAY.ID adalah backend REST API untuk platform toko produk digital dan PPOB (Payment Point Online Bank). Sistem menangani seluruh alur — dari pelanggan memilih produk di storefront, melakukan pembayaran via Midtrans, hingga produk otomatis dikirim ke Digiflazz — tanpa intervensi manual.

**Kategori produk yang tersedia:**
- 📱 Pulsa (semua operator)
- 📶 Kuota Data
- ⚡ Token Listrik PLN
- 🎮 Top Up Game (Free Fire, Mobile Legends, dll.)
- 🎟️ Voucher Game

---

## 🛠️ Arsitektur & Teknologi

```
┌──────────────────────────────────────────────────────────┐
│                     FEEPAY.ID                            │
│                                                          │
│  ┌─────────────┐          ┌──────────────────────────┐  │
│  │  Frontend   │◄────────►│    Laravel 11 API        │  │
│  │  (Vite)     │          │                          │  │
│  └─────────────┘          │  ┌────────┐ ┌─────────┐  │  │
│                            │  │ Queue  │ │Schedule │  │  │
│  ┌─────────────┐          │  └───┬────┘ └────┬────┘  │  │
│  │  Midtrans   │─webhook─►│      │            │       │  │
│  │  (Payment)  │          └──────┼────────────┼───────┘  │
│  └─────────────┘                 │            │           │
│                            ┌─────▼──────┐ ┌──▼────────┐  │
│  ┌─────────────┐          │ Digiflazz  │ │ Telegram  │  │
│  │    MySQL    │◄─────────│ (PPOB API) │ │  (Alert)  │  │
│  └─────────────┘          └────────────┘ └───────────┘  │
└──────────────────────────────────────────────────────────┘
```

| Komponen | Teknologi | Detail |
|---|---|---|
| **Framework** | Laravel 11 | PHP 8.2+, API-only mode |
| **Database** | MySQL | Index dioptimasi di tabel orders & products |
| **Frontend Build** | Vite | Asset bundling |
| **Auth API** | Laravel Sanctum | Token-based, expire 24 jam, single session |
| **Queue** | Database Driver | Job background: email notifikasi |
| **Admin Security** | Triple-layer | Token + PIN 6 digit + secret path |
| **Notifikasi** | Telegram Bot | Alert real-time ke admin |
| **Email** | SMTP (Brevo/Gmail) | Notifikasi order sukses/gagal ke pelanggan |

---

## 🔥 Fitur Lengkap

### 1. 🛒 Katalog Produk Digital

Endpoint publik yang menyajikan daftar produk aktif dengan filter kategori. Produk memiliki field `cost_price` (harga modal dari Digiflazz), `selling_price` (harga jual ke pelanggan), dan `profit_margin` (kalkulasi otomatis) yang hanya terlihat di sisi admin.

Produk mendukung field `type` dan `brand` untuk membedakan tampilan UI antara produk pulsa dan top up game (yang butuh Server ID/Zone ID).

---

### 2. 💳 Integrasi Midtrans (Payment Gateway)

Proses pembayaran yang aman dengan berbagai metode:
- Virtual Account (BCA, Mandiri, BNI, BRI, dll.)
- QRIS
- E-wallet (GoPay, OVO, dll.)

**Penting — harga 100% dari database:** Amount yang dikirim ke Midtrans diambil dari `products.selling_price` di database, bukan dari request pelanggan. Tidak bisa dimanipulasi.

**Alur pembayaran:**
```
POST /api/orders/create           → Order dibuat, status: pending
       ↓
POST /api/payments/midtrans/create → Snap Token dibuat dari harga di DB
       ↓
Pelanggan bayar via Midtrans UI
       ↓
POST /api/midtrans/webhook         → Notifikasi Midtrans (signature SHA-512 diverifikasi)
       ↓
Otomatis kirim ke Digiflazz        → lockForUpdate() cegah double-send
       ↓
POST /api/callback/digiflazz       → Callback status (signature MD5 diverifikasi)
       ↓
Email + Telegram notifikasi        → Email via Queue (async), Telegram real-time
```

**Snap Token reuse:** Jika pelanggan request Snap Token dua kali untuk order yang sama, sistem mengembalikan token lama (tidak buat baru) untuk menghindari error duplikat dari Midtrans.

---

### 3. ⚡ Integrasi Digiflazz (PPOB Provider)

- Sinkronisasi katalog produk manual (via admin dashboard) maupun otomatis (via scheduler)
- Pengecekan saldo Digiflazz langsung dari dashboard admin
- Eksekusi order otomatis setelah pembayaran terkonfirmasi
- Sinkronisasi status manual jika callback tidak diterima

**Proteksi harga saat sync:** Saat sync produk, jika harga modal baru dari Digiflazz lebih tinggi dari harga jual yang sudah diset admin, selling price otomatis disesuaikan (cost + default margin). Harga jual yang masih di atas harga modal **tidak akan ditimpa**.

---

### 4. 🖥️ Dashboard Admin

Diakses melalui path tersembunyi yang dikonfigurasi via `.env`. Setiap request ke endpoint admin protected membutuhkan **tiga lapis verifikasi**: Bearer Token + header `X-Admin-PIN` + secret path.

**Statistik & Monitoring:**
- Total order, pending, sukses, gagal (dengan filter rentang tanggal)
- Revenue total dan grafik revenue 7 hari terakhir
- Daftar 10 order terbaru
- Statistik produk aktif/total per kategori
- Cek saldo Digiflazz real-time

**Manajemen Order:**
- Daftar semua order (paginated 50/halaman) dengan riwayat perubahan status
- Konfirmasi manual order pending ke Digiflazz
- Sinkronisasi status manual dari Digiflazz untuk order yang statusnya stuck

**Manajemen Produk:**
- Update harga jual per produk (individual)
- Bulk update margin — set margin nominal, terapkan ke semua produk sekaligus (`selling_price = cost_price + margin`)
- Sync produk manual dari Digiflazz dengan filter kategori opsional

---

### 5. 📋 Cek Status Order Pelanggan

Pelanggan dapat mengecek status ordernya secara mandiri tanpa perlu login, cukup dengan Order ID dan email yang digunakan saat order:

```
POST /api/orders/{orderId}
Body: { "email": "pelanggan@email.com" }
```

Sistem memverifikasi kepemilikan order via email (case-insensitive) sebelum menampilkan detail.

---

### 6. 🎫 Support Tiket

Pelanggan dapat mengirim pesan support melalui:

```
POST /api/support/send
```

Sistem menyimpan pesan ke database terlebih dahulu sebelum meneruskan ke Telegram admin — data tidak hilang meskipun Telegram sedang error. Setiap tiket mendapat ID unik format `SUP000001`.

```
GET /api/support/contacts
```
Mengembalikan info kontak WhatsApp, Telegram, dan email support yang dapat dikonfigurasi via `.env`.

---

### 7. 📧 Email Notifikasi Otomatis

| Trigger | Metode | Detail |
|---|---|---|
| **Order Sukses** | Queue (async) | Job `SendOrderSuccessEmail` — retry otomatis 3x jika gagal |
| **Order Gagal** | Sinkron langsung | Dikirim segera saat status berubah ke failed |

Email sukses dikirim via Queue agar response API tetap cepat. Jika email gagal terkirim setelah 3 kali retry, kegagalan dicatat di log.

---

### 8. 📡 Telegram Admin Alerts

Notifikasi real-time ke Telegram admin untuk setiap event penting:

**Order diproses ke Digiflazz:**
```
⏳ TRANSAKSI DIPROSES
----------------------------------
Order ID: #FP3X8KMQRTWZ
Produk: Pulsa Telkomsel 50.000
Target: 08123456789
Nominal: Rp 52.000
----------------------------------
Menunggu callback sukses...
```

**Transaksi sukses (via callback Digiflazz):**
```
✅ NOTIFIKASI TRANSAKSI FEEPAY
----------------------------------
Status: SUKSES
Produk: Pulsa Telkomsel 50.000
Nominal: Rp 52.000
Pembeli: pelanggan@email.com
Order ID: #FP3X8KMQRTWZ
SN: [serial number dari provider]
----------------------------------
Laporan otomatis sistem FEEPAY.ID
```

**Transaksi gagal:**
```
❌ NOTIFIKASI TRANSAKSI FEEPAY
----------------------------------
Status: GAGAL
Produk: Pulsa XL 25.000
Nominal: Rp 26.500
Pembeli: pelanggan@email.com
Order ID: #FPABCDEFGHIJ
Alasan: Nomor tujuan tidak valid
----------------------------------
```

**Provider reject order:**
```
⚠️ DIGIFLAZZ REJECTED
----------------------------------
Order ID: #FPABCDEFGHIJ
SKU: xl-25000
Target: 08198765432
Pesan: Saldo tidak mencukupi
----------------------------------
Laporan otomatis FEEPAY.ID
```

**System error:**
```
🚨 SYSTEM ERROR — placeOrder
----------------------------------
Order ID: #FPABCDEFGHIJ
Error: Connection timeout
----------------------------------
Cek log Laravel di VPS segera!
```

**Saldo Digiflazz menipis** (otomatis saat cek saldo jika < Rp 100.000):
```
💸 WARNING: SALDO TIPIS!
----------------------------------
Sisa Saldo: Rp 87.500
----------------------------------
Segera Top Up saldo Digiflazz!
```

**Support tiket masuk dari pelanggan:**
```
🔔 SUPPORT MESSAGE BARU - FEEPAY.ID

Ticket: SUP000017
Nama: nama_pelanggan
Email: email@pelanggan.com
Platform: Telegram

Pesan:
[isi pesan dari pelanggan]

Waktu: 01 Mar 2026 16:38 WIB
```

---

### 9. 🔁 Idempotency Order

Untuk mencegah order duplikat akibat klik ganda atau request yang dikirim ulang, sistem mendukung header `X-Idempotency-Key`:

```
POST /api/orders/create
X-Idempotency-Key: unique-key-dari-frontend
```

Jika order dengan key yang sama sudah pernah dibuat dalam 24 jam terakhir, sistem mengembalikan data order yang sama tanpa membuat order baru.

---

## 🔐 Keamanan Berlapis

Sistem menerapkan *defense in depth* — multiple layer yang saling melengkapi:

| Layer | Mekanisme | Keterangan |
|---|---|---|
| **1 — HTTPS** | `ForceHttps` middleware + HSTS | `max-age=31536000; includeSubDomains; preload` (production only) |
| **2 — Security Headers** | `SecurityHeaders` middleware | CSP, X-Frame-Options DENY, X-Content-Type-Options, Referrer-Policy, Permissions-Policy. Server version fingerprint dihapus. |
| **3 — Rate Limiting** | Throttle per-endpoint | Login admin: 5/menit. Support: 5/menit. Order & payment: 20/menit. Webhook: 100/menit. Produk & cek order: 60/menit. |
| **4 — Auth Token** | Laravel Sanctum | Bearer Token, expire 24 jam, single session (token lama otomatis dihapus saat login baru) |
| **5 — Secret Path** | `ADMIN_PATH_PREFIX` | Path admin tidak pernah statis, dikonfigurasi via `.env`. Throw `RuntimeException` di production jika tidak diset. |
| **6 — Admin PIN** | `VerifyPinMiddleware` | Header `X-Admin-PIN` 6 digit. Rate limit 5 percobaan per 15 menit per IP. `hash_equals()` cegah timing attack. |
| **7 — IP Whitelist** | `AdminIpWhitelist` middleware | Tersedia dan teregistrasi. Di production, blokir semua jika `ADMIN_ALLOWED_IPS` kosong. |
| **8 — Webhook Signature** | MD5 + SHA-512 | Digiflazz: `MD5(username + api_key + ref_id)`. Midtrans: `SHA-512(order_id + status_code + gross_amount + server_key)`. Keduanya pakai `hash_equals()`. |
| **9 — Race Condition** | `lockForUpdate()` | Di `confirm()` dan `processToDigiflazz()` untuk cegah double-processing order yang sama. |
| **10 — Input Sanitize** | FormRequest + Midtrans sanitize | `$isSanitized = true`, `$is3ds = true`. Semua endpoint divalidasi via FormRequest atau `Validator`. |

---

## 📂 Struktur Endpoint API

### Public Endpoints

| Method | Endpoint | Fungsi | Rate Limit |
|---|---|---|---|
| `GET` | `/api/health` | Health check API | — |
| `GET` | `/api/products` | Daftar produk aktif (filter `?category=`) | 60/menit |
| `POST` | `/api/orders/create` | Buat order baru | 20/menit |
| `POST` | `/api/orders/{orderId}` | Cek status order (butuh `email` di body) | 60/menit |
| `POST` | `/api/payments/midtrans/create` | Buat Snap Token untuk order | 20/menit |
| `POST` | `/api/callback/digiflazz` | Webhook callback dari Digiflazz | 100/menit |
| `POST` | `/api/midtrans/webhook` | Webhook notifikasi pembayaran Midtrans | 100/menit |
| `POST` | `/api/support/send` | Kirim pesan support | 5/menit |
| `GET` | `/api/support/contacts` | Info kontak support (WA, Telegram, Email) | 5/menit |
| `POST` | `/api/admin/login` | Login admin | 5/menit |

### Admin Endpoints — Auth Token Only

| Method | Endpoint | Fungsi |
|---|---|---|
| `POST` | `/api/admin/logout` | Revoke token aktif |
| `GET` | `/api/admin/me` | Info user yang sedang login |
| `POST` | `/api/admin/refresh` | Refresh token (revoke lama, buat baru) |

### Admin Endpoints — Token + PIN + Secret Path

> Semua endpoint di bawah membutuhkan: Bearer Token + header `X-Admin-PIN` (6 digit) + `{secret}` sesuai nilai `ADMIN_PATH_PREFIX` di `.env`

| Method | Endpoint | Fungsi |
|---|---|---|
| `GET` | `/api/admin/{secret}/dashboard/stats` | Statistik order & revenue (support filter `start_date`, `end_date`) |
| `GET` | `/api/admin/{secret}/dashboard/products` | Statistik produk aktif/total per kategori |
| `GET` | `/api/admin/{secret}/dashboard/balance` | Cek saldo Digiflazz real-time |
| `GET` | `/api/admin/{secret}/orders` | Daftar semua order dengan riwayat status (paginated 50) |
| `POST` | `/api/admin/{secret}/orders/{id}/confirm` | Konfirmasi & kirim order ke Digiflazz |
| `POST` | `/api/admin/{secret}/orders/{orderId}/sync` | Sinkronisasi status order dari Digiflazz |
| `POST` | `/api/admin/{secret}/products/sync` | Sync katalog produk dari Digiflazz (opsional `?category=`) |
| `POST` | `/api/admin/{secret}/products/bulk-margin` | Set margin nominal ke semua produk (`selling_price = cost_price + margin`) |
| `PUT` | `/api/admin/{secret}/products/{id}` | Update harga jual satu produk |

---

## 🗄️ Struktur Database

### Tabel `products`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | Primary key |
| `sku` | string | SKU unik dari Digiflazz |
| `name` | string | Nama produk |
| `category` | string | Kategori (Pulsa, Data, PLN, dll.) |
| `brand` | string (nullable) | Brand/operator, penting untuk filter Game |
| `cost_price` | decimal(15,2) | Harga modal dari Digiflazz |
| `selling_price` | decimal(15,2) | Harga jual ke pelanggan |
| `status` | string | `active` / `inactive` |
| `stock` | string | Default: `unlimited` |
| `type` | string | `standard` / tipe lain untuk bedakan UI |

*Computed attribute:* `profit_margin` = `selling_price - cost_price` (tidak disimpan di DB)

### Tabel `orders`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | Primary key |
| `order_id` | string | ID unik format `FP` + 12 karakter random uppercase |
| `sku` | string | SKU produk yang dipesan |
| `product_name` | string | Nama produk (snapshot saat order) |
| `target_number` | string | Nomor HP / ID akun game |
| `zone_id` | string (nullable) | Server ID untuk game (Mobile Legends, dll.) |
| `customer_email` | string | Email pelanggan (disimpan lowercase) |
| `total_price` | decimal(15,2) | Harga dari DB saat order dibuat |
| `status` | enum | `pending` / `processing` / `success` / `failed` |
| `sn` | string (nullable) | Serial Number / Token dari provider |
| `confirmed_by` | bigint (nullable) | ID admin yang konfirmasi |
| `confirmed_at` | timestamp (nullable) | Waktu konfirmasi (juga sebagai flag anti double-send) |
| `midtrans_snap_token` | string (nullable) | Snap Token Midtrans |
| `midtrans_transaction_id` | string (nullable) | Transaction ID dari Midtrans |
| `midtrans_payment_type` | string (nullable) | Metode bayar (VA, QRIS, dll.) |
| `midtrans_transaction_status` | string (nullable) | Status dari Midtrans |
| `midtrans_transaction_time` | timestamp (nullable) | Waktu transaksi Midtrans |

### Tabel `order_status_histories`

Mencatat setiap perubahan status order secara lengkap.

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | Primary key |
| `order_id` | bigint (FK) | Relasi ke tabel orders |
| `status` | enum | Status baru: `pending` / `processing` / `success` / `failed` |
| `note` | text (nullable) | Catatan perubahan status |
| `changed_by` | bigint (nullable, FK) | ID admin yang mengubah (null jika otomatis) |

### Tabel `support_messages`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | Primary key (juga dipakai sebagai ticket ID: `SUP000001`) |
| `user_name` | string (nullable) | Nama pengirim |
| `user_email` | string (nullable) | Email pengirim |
| `message` | text | Isi pesan |
| `platform` | enum | `whatsapp` / `telegram` |
| `status` | enum | `pending` / `sent` / `failed` |
| `order_id` | string (nullable) | Order ID terkait (jika ada) |

### Tabel `users`

Tabel admin. Diisi via seeder dengan data dari `.env` (`ADMIN_EMAIL`, `ADMIN_SEED_PASSWORD`).

---

## 📁 Struktur Folder Proyek

```
backend-FEEPAY.ID/
├── app/
│   ├── Console/Commands/
│   │   └── SyncDigiflazz.php              # Artisan command: php artisan digiflazz:sync
│   ├── Enums/
│   │   └── OrderStatus.php                # Enum: pending, processing, success, failed
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── AuthController.php         # Login, logout, me, refresh token
│   │   │   ├── OrderController.php        # Buat order, konfirmasi, sync, list, cek status
│   │   │   ├── MidtransPaymentController.php  # Buat Snap Token, handle webhook Midtrans
│   │   │   ├── CallbackController.php     # Handle callback dari Digiflazz
│   │   │   ├── ProductController.php      # List produk, sync, bulk margin, update harga
│   │   │   ├── DashboardController.php    # Statistik, cek saldo, stats produk
│   │   │   └── SupportController.php      # Kirim tiket support, info kontak
│   │   ├── Middleware/
│   │   │   ├── AdminIpWhitelist.php       # Whitelist IP admin
│   │   │   ├── ForceHttps.php             # Redirect HTTP → HTTPS
│   │   │   ├── SecurityHeaders.php        # Set security headers (CSP, HSTS, dll.)
│   │   │   └── VerifyPinMiddleware.php    # Verifikasi X-Admin-PIN (6 digit, rate limited)
│   │   ├── Requests/
│   │   │   ├── AdminLoginRequest.php      # Validasi form login admin
│   │   │   ├── StoreOrderRequest.php      # Validasi form buat order
│   │   │   └── ConfirmOrderRequest.php    # Validasi form konfirmasi order
│   │   └── Kernel.php                     # Registrasi middleware
│   ├── Jobs/
│   │   └── SendOrderSuccessEmail.php      # Job queue: kirim email sukses (retry 3x, timeout 60s)
│   ├── Mail/
│   │   ├── OrderSuccess.php               # Email template order berhasil
│   │   └── OrderFailed.php                # Email template order gagal
│   ├── Models/
│   │   ├── Order.php                      # Model order dengan scopes & helpers
│   │   ├── Product.php                    # Model produk dengan computed profit_margin
│   │   ├── OrderStatusHistory.php         # Model riwayat perubahan status
│   │   ├── SupportMessage.php             # Model tiket support
│   │   └── User.php                       # Model admin
│   ├── Providers/
│   │   └── AppServiceProvider.php
│   └── Services/
│       ├── DigiflazzService.php           # Wrapper API Digiflazz (price list, placeOrder, checkStatus, getBalance)
│       ├── MidtransService.php            # Wrapper Midtrans (createSnapToken, verifySignature, getNotification)
│       └── TelegramService.php            # Static notify() — kirim alert ke admin
├── config/
│   ├── feepay.php                         # Config custom: margin, admin_path, admin_pin, support contacts
│   ├── midtrans.php                       # Config Midtrans
│   └── ...
├── database/
│   ├── migrations/                        # Skema: products, orders, order_status_histories, support_messages, users, dll.
│   └── seeders/
│       ├── AdminSeeder.php                # Seed akun admin dari .env
│       └── DatabaseSeeder.php
├── resources/views/
│   ├── emails/
│   │   ├── order-success.blade.php        # Template email order sukses
│   │   └── order-failed.blade.php         # Template email order gagal
│   └── payment/
│       └── checkout.blade.php             # Halaman checkout (Midtrans Snap)
├── routes/
│   ├── api.php                            # Semua definisi route API
│   └── web.php                            # Route web (redirect login ke API)
├── .env.example                           # Template konfigurasi lengkap
└── artisan
```

---

## 🚀 Panduan Instalasi

### Prasyarat

| Kebutuhan | Versi Minimum |
|---|---|
| PHP | 8.2+ |
| Composer | 2.x |
| Node.js | 18+ |
| MySQL | 8.0+ |

---

### Langkah 1 — Clone & Install

```bash
git clone https://github.com/fetrusmeilanoilhamsyah/backend-FEEPAY.ID.git
cd backend-FEEPAY.ID

# Install PHP dependencies
composer install

# Install Node dependencies
npm install
```

### Langkah 2 — Konfigurasi Environment

```bash
cp .env.example .env
# Edit .env dan isi semua nilai yang diperlukan (lihat bagian Environment Variables)
```

### Langkah 3 — Generate Key & Migrasi

```bash
php artisan key:generate
php artisan migrate --seed
```

> Seeder akan membuat akun admin dari `ADMIN_EMAIL` dan `ADMIN_SEED_PASSWORD` di `.env`.

### Langkah 4 — Jalankan Queue Worker

```bash
php artisan queue:work
```

---

## 🖥️ Development vs Production

### Mode Development — 5 Terminal

```bash
# Terminal 1 — Backend API
php artisan serve

# Terminal 2 — Frontend Hot Reload
npm run dev

# Terminal 3 — Tunnel untuk Webhook Midtrans & Telegram
ngrok http 8000

# Terminal 4 — Scheduler (auto-sync produk)
php artisan schedule:work

# Terminal 5 — Queue Worker (email notifikasi)
php artisan queue:work
```

> Setelah ngrok aktif, set URL tunnel sebagai Webhook URL di dashboard Midtrans.

### Mode Production — Server

**1. Build frontend:**
```bash
npm run build
```

**2. Cron Job (scheduler):**
```bash
crontab -e
# Tambahkan:
* * * * * cd /var/www/feepay && php artisan schedule:run >> /dev/null 2>&1
```

**3. Supervisor** — agar queue worker jalan 24/7 dan auto-restart:

Buat `/etc/supervisor/conf.d/feepay-worker.conf`:
```ini
[program:feepay-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/feepay/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/feepay/storage/logs/worker.log
```
```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start feepay-queue:*
```

**4. Nginx:**
```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/feepay/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Ringkasan Perbedaan

| Aspek | Development | Production |
|---|---|---|
| Server | `php artisan serve` | Nginx / Apache |
| Frontend | `npm run dev` | `npm run build` |
| Webhook | ngrok tunnel | Domain HTTPS langsung |
| Scheduler | `php artisan schedule:work` | Cron Job |
| Queue | Terminal manual | Supervisor (Auto-Restart) |
| Debug | `APP_DEBUG=true` | `APP_DEBUG=false` ⚠️ |

---

## ⚙️ Jadwal Auto-Sync Produk

Produk dari Digiflazz disinkronkan otomatis **4x sehari** via Laravel Scheduler:

| Jadwal | Keterangan |
|---|---|
| 00:00 | Sync tengah malam |
| 06:00 | Sync pagi |
| 12:00 | Sync siang |
| 18:00 | Sync sore |

**Proteksi harga:** Harga jual yang masih di atas harga modal **tidak akan ditimpa**. Hanya produk baru yang mendapat harga default (`cost_price + FEEPAY_MARGIN`). Jika harga modal naik melebihi harga jual, selling price otomatis disesuaikan.

**Trigger manual via terminal:**
```bash
php artisan digiflazz:sync
# Opsional filter kategori:
php artisan digiflazz:sync --category="Pulsa"
```

**Trigger manual via API:** `POST /api/admin/{secret}/products/sync` (memerlukan auth admin + PIN)

---

## 🔧 Environment Variables

```env
# ─── Aplikasi ────────────────────────────────────────────────
APP_NAME="FEEPAY.ID"
APP_ENV=production
APP_KEY=base64:GENERATE_DENGAN_php_artisan_key:generate
APP_DEBUG=false             # WAJIB false di production
APP_URL=https://api.feepay.web.id

# ─── Database ────────────────────────────────────────────────
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=feepay_db
DB_USERNAME=feepay_user
DB_PASSWORD=PASSWORD_KUAT_MIN_16_KARAKTER

# ─── Keamanan Admin ──────────────────────────────────────────
ADMIN_PATH_PREFIX=STRING_ACAK_PANJANG_MIN_12_KARAKTER  # WAJIB diset. Throw error di production jika kosong.
FEEPAY_ADMIN_PIN=6_DIGIT_BUKAN_TANGGAL_LAHIR           # WAJIB diset.
ADMIN_ALLOWED_IPS=IP_VPS_KAMU,IP_RUMAH_KAMU            # Whitelist IP admin

# ─── Digiflazz ───────────────────────────────────────────────
DIGIFLAZZ_USERNAME=username_digiflazz_anda
DIGIFLAZZ_API_KEY=api_key_dari_dashboard_digiflazz
DIGIFLAZZ_BASE_URL=https://api.digiflazz.com/v1

# ─── Midtrans ────────────────────────────────────────────────
MIDTRANS_SERVER_KEY=Mid-server-xxxxxxxxxxxxxxxxxxxx
MIDTRANS_CLIENT_KEY=Mid-client-xxxxxxxxxxxxxxxxxxxx
MIDTRANS_IS_PRODUCTION=true   # Ganti false saat development/sandbox

# ─── Telegram ────────────────────────────────────────────────
TELEGRAM_BOT_TOKEN=1234567890:AAFxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TELEGRAM_CHAT_ID=987654321

# ─── Email ───────────────────────────────────────────────────
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com   # Atau smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=email_smtp_anda
MAIL_PASSWORD=password_smtp_anda
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="email@domain.com"
MAIL_FROM_NAME="FEEPAY.ID"

# ─── Support Contacts ────────────────────────────────────────
SUPPORT_WHATSAPP=62XXXXXXXXXX
SUPPORT_TELEGRAM=@USERNAME_TELEGRAM
SUPPORT_EMAIL=support@feepay.id

# ─── Margin Default ──────────────────────────────────────────
FEEPAY_MARGIN=2000   # Margin default (Rp) untuk produk baru saat sync

# ─── Queue & Cache ───────────────────────────────────────────
QUEUE_CONNECTION=database
CACHE_DRIVER=file

# ─── Session & Sanctum ───────────────────────────────────────
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_DOMAIN=.feepay.web.id
SANCTUM_STATEFUL_DOMAINS=feepay.web.id,api.feepay.web.id
SANCTUM_TOKEN_EXPIRATION=1440   # Token expire 24 jam

# ─── Seeder ──────────────────────────────────────────────────
ADMIN_EMAIL=emailkamu@domain.com
ADMIN_SEED_PASSWORD=PASSWORD_KUAT_UNTUK_AKUN_ADMIN

# ─── Logging ─────────────────────────────────────────────────
LOG_CHANNEL=single
LOG_LEVEL=error   # Di production cukup error saja
```

---

## 🛡️ Security Best Practices

**1. Wajib Set `ADMIN_PATH_PREFIX` dan `FEEPAY_ADMIN_PIN`**
Aplikasi akan throw `RuntimeException` di production jika keduanya tidak diset. Gunakan string acak panjang untuk path prefix.

**2. `APP_DEBUG=false` di Production**
Jika `true`, Laravel menampilkan stack trace dan konfigurasi server kepada publik — celah serius.

**3. Set `ADMIN_ALLOWED_IPS`**
Middleware IP whitelist sudah tersedia. Di production, set IP VPS dan IP admin agar endpoint admin tidak bisa diakses dari IP sembarang.

**4. Gunakan App Password untuk Gmail**
Jangan pakai password Gmail biasa. Buat App Password khusus:
```
https://myaccount.google.com/apppasswords
```

**5. Permission Folder yang Benar:**
```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

**6. Gunakan HTTPS**
Semua webhook (Midtrans) memerlukan HTTPS. Gunakan Let's Encrypt:
```bash
sudo certbot --nginx -d yourdomain.com
```

**7. Rotasi `ADMIN_PATH_PREFIX` Berkala**
Ganti path prefix secara berkala, terutama jika ada indikasi kebocoran URL admin.

**8. Naikkan Threshold Alert Saldo**
Default alert saldo menipis adalah Rp 100.000. Untuk traffic lebih tinggi, pertimbangkan menaikkan nilai ini sesuai rata-rata transaksi harian.

---

## 📞 Kontak Pengembang

| Platform | Kontak |
|---|---|
| **Telegram** | [@FEE999888](https://t.me/FEE999888) |
| **Email** | fetrusmeilanoilham@gmail.com |
| **GitHub** | [fetrusmeilanoilhamsyah](https://github.com/fetrusmeilanoilhamsyah) |

---

<div align="center">

**FEEPAY.ID** — Solusi Digital Marketplace & PPOB Terpercaya

*Dibuat dengan ❤️ oleh Fetrus Meilano Ilhamsyah (Fee)*

</div>