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
        User::create([
            'name' => 'Admin FEEPAY',
            'email' => 'admin@feepay.id',
            'password' => Hash::make('admin123456'), // CHANGE THIS IN PRODUCTION!
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->command->info('✅ Admin user created:');
        $this->command->info('   Email: admin@feepay.id');
        $this->command->warn('   Password: admin123456 (PLEASE CHANGE IN PRODUCTION!)');
    }
}