<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Validasi password ────────────────────────────────────────────────
        $password = env('ADMIN_SEED_PASSWORD');

        if (!$password) {
            $this->command->error('❌ ADMIN_SEED_PASSWORD belum diset di .env! Seeder dibatalkan.');
            $this->command->info('   Tambahkan ADMIN_SEED_PASSWORD=passwordkuat ke .env dulu.');
            return;
        }

        if (strlen($password) < 8) {
            $this->command->error('❌ ADMIN_SEED_PASSWORD minimal 8 karakter!');
            return;
        }

        // ─── Fix PROD-03: Email dari .env, tidak hardcode ─────────────────────
        // Sebelumnya email di updateOrCreate adalah 'admin@feepay.id'
        // tapi pesan info menampilkan email lain — menyebabkan login gagal.
        $email = env('ADMIN_EMAIL', 'admin@feepay.id');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'              => 'Admin FEEPAY',
                'password'          => Hash::make($password),
                'role'              => 'admin',
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('✅ Akun admin berhasil dibuat:');
        $this->command->info("   Email   : {$email}");
        $this->command->info('   Password: (dari ADMIN_SEED_PASSWORD di .env)');
        $this->command->warn('⚠️  Hapus ADMIN_SEED_PASSWORD dari .env setelah seeding selesai!');
    }
}
