<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing'; // <--- TAMBAHKAN INI
    case SUCCESS = 'success';
    case FAILED = 'failed';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing', // <--- TAMBAHKAN INI
            self::SUCCESS => 'Success',
            self::FAILED => 'Failed',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::PENDING => 'yellow',
            self::PROCESSING => 'blue', // <--- TAMBAHKAN INI (Warna Biru)
            self::SUCCESS => 'green',
            self::FAILED => 'red',
        };
    }
}