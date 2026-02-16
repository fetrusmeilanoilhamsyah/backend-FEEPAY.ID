# 🏦 FEEPAY.ID - Payment Gateway & Digital Product Engine

Sistem manajemen transaksi top-up otomatis, integrasi PPOB Digiflazz, dan konversi USDT. Dibangun dengan fokus pada keamanan tingkat tinggi, performa antrean (queue), dan kemudahan monitoring via Telegram.

---

## 🛠️ Arsitektur & Teknologi

FEEPAY.ID menggunakan arsitektur modern untuk memastikan setiap transaksi diproses secara atomik dan aman.

* **Framework:** [Laravel 11](https://laravel.com) (Latest Stable)
* **Database:** MySQL dengan optimasi indexing pada tabel transaksi.
* **Security:** * **Sanctum Authentication** untuk komunikasi API frontend.
    * **Route Obfuscation** untuk menyembunyikan endpoint administratif.
    * **Double-Verification PIN** untuk persetujuan transaksi manual.
* **Background Processing:** Laravel Queue (Database Driver) untuk menangani webhook dan email.



---

## 🔥 Fitur Unggulan

### 1. Integrasi Digiflazz (Real-time)
Otomatisasi penuh mulai dari pengambilan daftar produk, pengecekan saldo, hingga eksekusi orderan. Dilengkapi dengan fitur *retry logic* jika terjadi kegagalan sistem provider.

### 2. Custom Security Layer
Bukan sekadar `/admin`. Alamat portal admin dikontrol sepenuhnya melalui variabel `.env`:
- `ADMIN_PATH_PREFIX`: Mengubah pintu masuk API agar bot scraper tidak bisa menemukan celah login.
- `ADMIN_PIN`: Keamanan lapis kedua sebelum sistem melakukan *broadcast* atau persetujuan dana keluar.

### 3. Telegram Command Center
Integrasi dua arah dengan Bot Telegram:
- **Notification:** Update real-time transaksi masuk, stok menipis, dan laporan harian.
- **Alerting:** Notifikasi instan jika terjadi error kritis pada server.

---

## 📂 Struktur Endpoint (API)

| Method | Endpoint | Fungsi | Akses |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/products` | List semua produk aktif | Public |
| `POST` | `/api/checkout` | Pembuatan invoice transaksi | Public |
| `POST` | `/api/{prefix}/login` | Login Admin (Hidden Path) | Secret |
| `POST` | `/api/{prefix}/approve` | Konfirmasi transaksi manual | Admin Only |

---

## 🚀 Panduan Instalasi (Development)

Pastikan lingkungan lokal Anda memenuhi syarat: **PHP 8.2+**, **Composer**, dan **MySQL**.

1. **Clone & Install**
   ```bash
   git clone [https://github.com/fetrusmeilanoilhamsyah/backend-FEEPAY.ID.git](https://github.com/fetrusmeilanoilhamsyah/backend-FEEPAY.ID.git)
   cd backend-FEEPAY.ID && composer install
2.Environment Setup Salin .env.example menjadi .env dan lengkapi konfigurasi berikut:

    Database Credentials

    Digiflazz API Key & Username

    Telegram Bot Token & Chat ID

    ADMIN_PATH_PREFIX (Pilih string acak untuk keamanan)
    
3.Database Migration

    php artisan key:generate
   
    hp artisan migrate --seed
   
4.Service Workers Jalankan worker untuk memproses notifikasi dan transaksi di background: 

    php artisan queue:work

  🛡️ Security Best Practices
Selalu gunakan APP_DEBUG=false di lingkungan produksi.

Ganti ADMIN_PATH_PREFIX secara berkala jika terindikasi kebocoran URL.

Pastikan folder storage dan bootstrap/cache memiliki izin akses yang tepat.

📞 Hubungi Pengembang
Punya saran fitur atau menemukan bug? Hubungi saya melalui jalur resmi:

Developer: Fetrus Meilano Ilhamsyah

Telegram: @FEE999888

# FEEPAY.ID - Catatan Deployment & Development

## 🖥️ Development (Local) - Terminal yang Dibutuhkan

Buka 5 terminal terpisah:

```bash
# Terminal 1 - Backend Laravel
cd backend
php artisan serve

# Terminal 2 - Frontend Vue
cd frontend
npm run dev

# Terminal 3 - Tunnel (opsional, buat expose ke internet)
ngrok http 8000

# Terminal 4 - Scheduler (auto sync produk tiap 6 jam)
cd backend
php artisan schedule:work

# Terminal 5 - Queue Worker (buat kirim email notifikasi)
cd backend
php artisan queue:work
```

---

## 🚀 Production (VPS/Server) - Cukup 1 Cron Job!

### 1. Build Frontend (sekali aja)
```bash
cd frontend
npm run build
```

### 2. Setup Cron Job (gantiin schedule:work)
Jalankan `crontab -e` lalu tambahkan:
```
* * * * * cd /path/ke/project/backend && php artisan schedule:run >> /dev/null 2>&1
```
Ini otomatis sync produk tiap 6 jam. Ga perlu terminal!

### 3. Setup Supervisor (gantiin queue:work)
Supervisor otomatis jalankan queue worker di background, restart kalau crash.

Install supervisor:
```bash
apt install supervisor
```

Buat config `/etc/supervisor/conf.d/feepay-worker.conf`:
```ini
[program:feepay-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/ke/project/backend/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/path/ke/project/backend/storage/logs/worker.log
```

Lalu jalankan:
```bash
supervisorctl reread
supervisorctl update
supervisorctl start feepay-worker:*
```

### 4. Web Server (gantiin php artisan serve)
Pakai Nginx atau Apache. Tidak perlu `php artisan serve` di production!

---

## 📋 Ringkasan

| Development | Production |
|-------------|------------|
| 5 terminal manual | Cukup 1 cron job |
| `php artisan serve` | Nginx/Apache |
| `npm run dev` | `npm run build` (sekali) |
| `php artisan schedule:work` | Cron job |
| `php artisan queue:work` | Supervisor |
| `ngrok` | Domain sendiri |

---

## ⚙️ Jadwal Auto-Sync Produk
- Sync dari Digiflazz tiap **6 jam sekali**
- Jam: 00.00, 06.00, 12.00, 18.00
- Harga jual yang sudah diedit admin **tidak akan ditimpa**
- Test manual: `php artisan app:sync-products`

---

## 🔐 Environment Variables Penting (.env)
```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...

ADMIN_PATH_PREFIX=yQIhhAOQ
ADMIN_ALLOWED_IPS=IP_KAMU_DI_SINI

SANCTUM_TOKEN_EXPIRATION=1440

DIGIFLAZZ_USERNAME=...
DIGIFLAZZ_API_KEY=...

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=email@gmail.com
MAIL_PASSWORD=app_password_gmail
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=email@gmail.com
MAIL_FROM_NAME=FEEPAY.ID
```
