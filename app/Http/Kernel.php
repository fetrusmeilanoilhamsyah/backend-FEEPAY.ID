<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

/**
 * Laravel 12 tidak menggunakan Kernel.php lagi.
 * Semua middleware sudah didaftarkan di bootstrap/app.php.
 * File ini hanya dipertahankan agar autoload tidak error.
 * Tidak ada yang perlu diubah di sini.
 */
class Kernel extends HttpKernel
{
    //
}