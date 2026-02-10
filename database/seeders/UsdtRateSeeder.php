<?php

namespace Database\Seeders;

use App\Models\UsdtRate;
use App\Models\User;
use Illuminate\Database\Seeder;

class UsdtRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::where('email', 'admin@feepay.id')->first();

        UsdtRate::create([
            'rate' => 16000.00, // Default: 1 USDT = Rp 16,000
            'is_active' => true,
            'note' => 'Initial USDT rate',
            'created_by' => $admin?->id,
        ]);

        $this->command->info('✅ Initial USDT rate created: Rp 16,000 per USDT');
    }
}