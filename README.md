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
- [Tampilan Aplikasi](#-tampilan-aplikasi)
- [Arsitektur & Teknologi](#️-arsitektur--teknologi)
- [Fitur Lengkap](#-fitur-lengkap)
- [Telegram CS Bot](#-telegram-cs-bot)
- [Struktur Endpoint API](#-struktur-endpoint-api)
- [Panduan Instalasi](#-panduan-instalasi)
- [Development vs Production](#️-development-vs-production)
- [Jadwal Auto-Sync Produk](#-jadwal-auto-sync-produk)
- [Environment Variables](#-environment-variables)
- [Security Best Practices](#-security-best-practices)
- [Kontak Pengembang](#-kontak-pengembang)

---

## 🎯 Gambaran Umum

FEEPAY.ID adalah full-stack platform untuk toko produk digital dan PPOB (Payment Point Online Bank). Sistem menangani seluruh alur — dari pelanggan memilih produk di storefront, melakukan pembayaran, hingga produk otomatis terkirim — tanpa intervensi manual.

**Kategori produk yang tersedia:**
- 📱 Pulsa (semua operator)
- 📶 Kuota Data
- ⚡ Token Listrik PLN
- 🎮 Top Up Game (Free Fire, Mobile Legends, dll.)
- 🎟️ Voucher Game

---

## 🖥️ Tampilan Aplikasi

### Halaman Beranda (Storefront)
Tampilan toko yang bersih dengan kategori produk, banner promosi, dan panduan cara transaksi. Mendukung **Dark Mode**.

**Navigasi member:**
| Halaman | Fungsi |
|---|---|
| **Beranda** | Katalog produk & kategori layanan |
| **Riwayat** | Pantau status semua pesanan |
| **Profil** | Kelola data akun |
| **Dashboard** | Panel admin (khusus admin) |

### Halaman Riwayat Transaksi
Member dapat memantau semua pesanan dengan filter status:

```
[ Semua ]  [ Menunggu ]  [ Diproses ]  [ Berhasil ]  [ Gagal ]
```

Dilengkapi fitur **pencarian** berdasarkan Order ID, nama produk, atau nomor tujuan.

### Dashboard Admin
Panel lengkap untuk mengelola seluruh operasi toko:

```
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│  TOTAL PESANAN  │  │     PENDING     │  │  TOTAL REVENUE  │
│       42        │  │        3        │  │   Rp 4.250.000  │
└─────────────────┘  └─────────────────┘  └─────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  SALDO DIGIFLAZZ                        [ Refresh Saldo ]  │
│  Rp 1.250.000                                               │
└─────────────────────────────────────────────────────────────┘
```

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
│  │    MySQL    │◄─────────│ (PPOB API) │ │ (CS Bot)  │  │
│  └─────────────┘          └────────────┘ └───────────┘  │
└──────────────────────────────────────────────────────────┘
```

| Komponen | Teknologi | Detail |
|---|---|---|
| **Framework** | Laravel 11 | PHP 8.2+, latest stable |
| **Database** | MySQL | Indexing dioptimasi di tabel transaksi |
| **Frontend Build** | Vite | Asset bundling cepat |
| **Auth API** | Laravel Sanctum | Token-based, aman untuk SPA |
| **Queue** | Database Driver | Job background: webhook, email, order |
| **Admin Security** | Route Obfuscation + PIN | Path tersembunyi + PIN verifikasi |
| **CS Support** | Telegram Bot | Terima & kelola tiket support member |
| **Email** | SMTP (Gmail) | Notifikasi order sukses/gagal ke member |

---

## 🔥 Fitur Lengkap

### 1. 🛒 Storefront Produk Digital
Halaman belanja yang lengkap dengan tampilan produk berdasarkan kategori:

- **Pulsa** — Semua operator (Telkomsel, Indosat, XL, Axis, Tri, dll.)
- **Kuota Data** — Paket internet semua operator
- **Token PLN** — Pembelian token listrik prabayar
- **Top Up Game** — Free Fire, Mobile Legends, dan game lainnya
- **Voucher Game** — Berbagai voucher game digital

Setiap produk menampilkan harga jual yang sudah termasuk margin admin. Member hanya perlu memasukkan nomor HP / ID akun game.

---

### 2. 💳 Integrasi Midtrans (Payment Gateway)
Proses pembayaran yang aman dengan beragam pilihan metode:

- Virtual Account (BCA, Mandiri, BNI, BRI, dll.)
- QRIS
- E-wallet (GoPay, OVO, dll.)

**Alur pembayaran lengkap:**
```
Member checkout → Invoice dibuat → Link pembayaran Midtrans digenerate
       ↓
Member bayar via metode pilihan
       ↓
Midtrans kirim webhook ke server FEEPAY.ID
       ↓
Laravel Queue memproses secara background (non-blocking)
       ↓
Order dikirim otomatis ke Digiflazz
       ↓
Produk (SN / Token / Pulsa) terkirim → Email konfirmasi ke member
```

---

### 3. ⚡ Integrasi Digiflazz (PPOB Provider)
Eksekusi order produk digital secara real-time:

- Sinkronisasi katalog produk otomatis dari Digiflazz
- Pengecekan saldo Digiflazz langsung dari Dashboard Admin
- Eksekusi pembelian (deposit ke nomor/ID tujuan) otomatis setelah pembayaran terkonfirmasi
- **Retry logic** — Jika terjadi timeout/error sementara di sisi Digiflazz, sistem mencoba ulang otomatis

---

### 4. 🖥️ Dashboard Admin
Panel administrasi lengkap yang diakses melalui path tersembunyi.

**Tab Produk:**
- Tampilan produk berdasarkan kategori (Aktivasi Perdana, Aktivasi Voucher, Pulsa, Token PLN, dll.)
- Lihat **Harga Modal**, **Harga Jual**, dan **Margin** tiap produk secara transparan
- **Edit Harga** — Ubah harga jual per produk secara individual
- **Set Margin Global** — Input nominal margin, terapkan ke semua produk sekaligus dengan satu klik tombol **Terapkan**
- **Sync Products** — Sinkronisasi manual katalog terbaru dari Digiflazz

**Tab Pesanan:**
- Lihat semua transaksi secara real-time
- **Approve Transaksi Manual** — Untuk edge-case yang memerlukan konfirmasi admin
- Statistik ringkas: Total Pesanan, Pending, Total Revenue

**Widget Saldo Digiflazz:**
- Cek saldo Digiflazz langsung dari dashboard
- Tombol **Refresh Saldo** — memerlukan verifikasi PIN Admin sebelum saldo ditampilkan

---

### 5. 📋 Riwayat Transaksi Member
Member dapat memantau semua pesanannya secara mandiri tanpa perlu menghubungi admin:

- **Pencarian** berdasarkan Order ID, nama produk, atau nomor tujuan
- **Filter status**: Semua / Menunggu / Diproses / Berhasil / Gagal
- Detail lengkap setiap transaksi (produk, nomor tujuan, waktu, status)

---

### 6. 🔐 Custom Security Layer (Anti-Scraper)

**Route Obfuscation:**
Path admin dikontrol penuh lewat `.env`. Bot scraper tidak bisa menemukan endpoint login admin karena URL-nya tidak pernah statis.

```env
# Path login jadi: /api/xK9mQR/login — hanya Anda yang tahu
ADMIN_PATH_PREFIX=xK9mQR
```

**Double-Verification PIN:**
Setiap aksi sensitif di Dashboard Admin (Refresh Saldo, Approve Transaksi) memerlukan PIN sebagai konfirmasi kedua. Jika PIN salah, sistem langsung menolak:

```
✗ Gagal cek saldo: PIN Admin Salah
```

---

### 7. 📧 Email Notifikasi Otomatis
Template email responsif dikirim ke member secara otomatis via Laravel Queue:

| Trigger | Isi Email |
|---|---|
| **Order Berhasil** | Detail produk, SN/Token/Pulsa, waktu transaksi |
| **Order Gagal** | Alasan kegagalan + instruksi refund otomatis |

---

### 8. 🌙 Dark Mode
Antarmuka mendukung mode gelap. Member dapat beralih antara Light Mode dan Dark Mode kapan saja melalui ikon di pojok kanan atas.

---

## 🤖 Telegram CS Bot

Bot Telegram berfungsi sebagai **sistem tiket support otomatis** untuk menangani keluhan dan pertanyaan member. Setiap pesan yang dikirim member ke bot akan dikonversi menjadi tiket support dan langsung diteruskan ke admin.

### Cara Kerja

```
Member kirim pesan ke bot Telegram FEEPAY.ID
             ↓
Sistem membuat tiket support (ID: SUP000XXX)
             ↓
Notifikasi tiket lengkap dikirim ke Telegram admin
             ↓
Admin membalas member secara manual
```

### Format Notifikasi Tiket (yang Diterima Admin)

```
🔔 SUPPORT MESSAGE BARU - FEEPAY.ID

🗒️ Ticket  : SUP000017
👤 Nama    : nama_member
📧 Email   : email@member.com
✈️ Platform: Telegram

💬 Pesan   :
[isi pesan dari member]

🕐 Waktu   : 26 Feb 2026 16:38 WIB
```

Setiap tiket memiliki ID unik yang dapat digunakan untuk melacak percakapan support.

### Cara Setup Bot Telegram

**Langkah 1 — Buat Bot via BotFather:**
```
1. Buka Telegram → cari @BotFather
2. Kirim: /newbot
3. Ikuti instruksi (beri nama & username bot)
4. Salin Bot Token yang diberikan
```

**Langkah 2 — Dapatkan Chat ID:**
```
1. Buka bot Anda, kirim sembarang pesan
2. Akses di browser:
   https://api.telegram.org/bot<TOKEN>/getUpdates
3. Salin nilai "id" dari bagian "chat" di respons JSON
```

**Langkah 3 — Isi .env:**
```env
TELEGRAM_BOT_TOKEN=1234567890:AAFxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TELEGRAM_CHAT_ID=987654321
```

**Langkah 4 — Daftarkan Webhook (Production):**
```bash
curl -X POST https://api.telegram.org/bot<TOKEN>/setWebhook \
     -d url=https://yourdomain.com/api/telegram/webhook
```

---

## 📂 Struktur Endpoint API

| Method | Endpoint | Fungsi | Akses |
|---|---|---|---|
| `GET` | `/api/products` | List semua produk aktif + kategori | Public |
| `POST` | `/api/checkout` | Buat invoice transaksi baru | Auth Member |
| `GET` | `/api/transactions` | Riwayat transaksi member | Auth Member |
| `POST` | `/api/webhook/midtrans` | Terima notifikasi pembayaran Midtrans | Midtrans Server |
| `POST` | `/api/telegram/webhook` | Terima pesan tiket support dari Telegram | Telegram Server |
| `POST` | `/api/{prefix}/login` | Login Admin (path tersembunyi) | Secret |
| `GET` | `/api/{prefix}/dashboard` | Statistik: pesanan, pending, revenue | Admin Only |
| `GET` | `/api/{prefix}/products` | Kelola produk + harga + margin | Admin Only |
| `POST` | `/api/{prefix}/products/margin` | Set margin global semua produk | Admin Only |
| `POST` | `/api/{prefix}/products/sync` | Trigger sync produk dari Digiflazz | Admin Only |
| `GET` | `/api/{prefix}/orders` | Semua transaksi masuk | Admin Only |
| `POST` | `/api/{prefix}/approve` | Approve transaksi manual | Admin Only |
| `GET` | `/api/{prefix}/balance` | Cek saldo Digiflazz (butuh PIN) | Admin Only |

> `{prefix}` = nilai `ADMIN_PATH_PREFIX` di `.env`. Contoh: `ADMIN_PATH_PREFIX=xK9mQR` → login admin di `/api/xK9mQR/login`.

---

## 📁 Struktur Folder Proyek

```
backend-FEEPAY.ID/
├── app/
│   ├── Console/Commands/
│   │   └── SyncProducts.php          # Artisan: sync produk dari Digiflazz
│   ├── Http/Controllers/
│   │   ├── ProductController.php     # CRUD & sinkronisasi produk
│   │   ├── CheckoutController.php    # Pembuatan invoice & integrasi Midtrans
│   │   ├── TransactionController.php # Riwayat & status transaksi member
│   │   ├── AdminController.php       # Dashboard, approve, cek saldo
│   │   ├── WebhookController.php     # Handler webhook Midtrans
│   │   └── TelegramController.php    # Handler tiket CS via Telegram
│   ├── Http/Middleware/
│   │   └── AdminPinMiddleware.php    # Verifikasi PIN untuk aksi sensitif
│   ├── Jobs/
│   │   ├── ProcessOrderJob.php       # Eksekusi order ke Digiflazz (background)
│   │   └── SendEmailJob.php          # Kirim email notifikasi ke member
│   ├── Models/
│   │   ├── Transaction.php
│   │   ├── Product.php
│   │   └── SupportTicket.php
│   └── Services/
│       ├── DigiflazzService.php      # Wrapper API Digiflazz
│       ├── MidtransService.php       # Wrapper Payment Gateway Midtrans
│       └── TelegramService.php       # Kirim & terima pesan Telegram
├── database/
│   ├── migrations/                   # Skema: transactions, products, tickets
│   └── seeders/                      # Data awal produk & akun admin
├── frontend/                         # Source code frontend (Vite)
├── resources/views/emails/           # Template email order sukses & gagal
├── routes/api.php                    # Semua definisi route API
├── .env.example                      # Template konfigurasi
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
# Edit .env dan isi semua nilai yang diperlukan
```

### Langkah 3 — Generate Key & Migrasi

```bash
php artisan key:generate
php artisan migrate --seed
```

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

# Terminal 4 — Scheduler
php artisan schedule:work

# Terminal 5 — Queue Worker
php artisan queue:work
```

> Setelah ngrok aktif, set URL tunnel sebagai Webhook URL di dashboard Midtrans dan Telegram.

### Mode Production — Server

**1. Build frontend:**
```bash
npm run build
```

**2. Cron Job:**
```bash
crontab -e
# Tambahkan:
* * * * * cd /var/www/feepay && php artisan schedule:run >> /dev/null 2>&1
```

**3. Supervisor** — agar queue jalan 24/7:

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

Produk dari Digiflazz disinkronkan otomatis **4x sehari**:

| Jadwal | Keterangan |
|---|---|
| 00:00 | Sync tengah malam |
| 06:00 | Sync pagi |
| 12:00 | Sync siang |
| 18:00 | Sync sore |

> ⚠️ Harga jual yang sudah diedit manual admin **tidak akan ditimpa** oleh proses sync. Hanya produk baru dan status aktif/nonaktif yang diperbarui.

**Trigger manual via terminal:**
```bash
php artisan app:sync-products
```
**Trigger manual via UI:** Klik tombol **Sync Products** di Dashboard Admin (memerlukan verifikasi PIN).

---

## 🔐 Environment Variables

```env
# ─── Aplikasi ────────────────────────────────────────────────
APP_NAME="FEEPAY.ID"
APP_ENV=production
APP_DEBUG=false             # WAJIB false di server live
APP_URL=https://yourdomain.com

# ─── Database ────────────────────────────────────────────────
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=feepay_db
DB_USERNAME=root
DB_PASSWORD=

# ─── Keamanan Admin ──────────────────────────────────────────
ADMIN_PATH_PREFIX=ganti_ini_string_acak   # Ganti berkala!
ADMIN_PIN=123456                           # PIN verifikasi aksi sensitif

# ─── Digiflazz ───────────────────────────────────────────────
DIGIFLAZZ_USERNAME=username_digiflazz_anda
DIGIFLAZZ_API_KEY=api_key_production_dari_digiflazz

# ─── Midtrans ────────────────────────────────────────────────
MIDTRANS_SERVER_KEY=Mid-server-xxxxxxxxxxxxxxxxxxxx
MIDTRANS_CLIENT_KEY=Mid-client-xxxxxxxxxxxxxxxxxxxx
MIDTRANS_IS_PRODUCTION=false   # Ganti true saat go-live

# ─── Telegram CS Bot ─────────────────────────────────────────
TELEGRAM_BOT_TOKEN=1234567890:AAFxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TELEGRAM_CHAT_ID=987654321

# ─── Email ───────────────────────────────────────────────────
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=emailanda@gmail.com
MAIL_PASSWORD=app_password_gmail   # Buat di: myaccount.google.com/apppasswords
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=emailanda@gmail.com
MAIL_FROM_NAME="FEEPAY.ID"

# ─── Queue ────────────────────────────────────────────────────
QUEUE_CONNECTION=database
```

---

## 🛡️ Security Best Practices

**1. Sembunyikan & Rotasi Path Admin**
Ganti `ADMIN_PATH_PREFIX` dengan string acak yang kuat. Rotasi berkala jika ada indikasi kebocoran URL.

**2. APP_DEBUG Wajib false di Production**
Jika `true`, Laravel menampilkan stack trace dan konfigurasi server kepada publik — celah serius.

**3. Gunakan App Password untuk Gmail**
Jangan pakai password Gmail biasa. Buat App Password khusus:
`https://myaccount.google.com/apppasswords`

**4. Permission Folder yang Benar:**
```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

**5. Gunakan HTTPS**
Semua webhook (Midtrans & Telegram) memerlukan HTTPS. Gunakan Let's Encrypt:
```bash
sudo certbot --nginx -d yourdomain.com
```

**6. Ganti PIN Admin Berkala**
PIN melindungi aksi paling sensitif. Jangan gunakan PIN yang mudah ditebak.

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