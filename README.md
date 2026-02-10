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

Email: fetrusmeilanoilham@gmail.com
