<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING    = 'pending';
    case PROCESSING = 'processing';
    case SUCCESS    = 'success';
    case FAILED     = 'failed';

    public function label(): string
    {
        return match($this) {
            self::PENDING    => 'Menunggu Pembayaran',
            self::PROCESSING => 'Sedang Diproses',
            self::SUCCESS    => 'Berhasil',
            self::FAILED     => 'Gagal',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::PENDING    => 'yellow',
            self::PROCESSING => 'blue',
            self::SUCCESS    => 'green',
            self::FAILED     => 'red',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::SUCCESS, self::FAILED]);
    }
}
