<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // ✅ FIXED: Password dari env, tidak hardcode
        $password = env('ADMIN_SEED_PASSWORD');

        if (!$password) {
            $this->command->error('❌ ADMIN_SEED_PASSWORD not set in .env! Seeder aborted.');
            $this->command->info('   Add ADMIN_SEED_PASSWORD=yourpassword to .env first.');
            return;
        }

        if (strlen($password) < 8) {
            $this->command->error('❌ ADMIN_SEED_PASSWORD must be at least 8 characters!');
            return;
        }

        User::updateOrCreate(
            ['email' => 'admin@feepay.id'],
            [
                'name'              => 'Admin FEEPAY',
                'password'          => Hash::make($password),
                'role'              => 'admin',
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('✅ Admin user created:');
        $this->command->info('   Email: admin@feepay.id');
        $this->command->info('   Password: (from ADMIN_SEED_PASSWORD in .env)');
        $this->command->warn('   Delete ADMIN_SEED_PASSWORD from .env after seeding!');
    }
}