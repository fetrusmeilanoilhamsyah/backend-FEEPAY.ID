<?php

namespace App\Enums;

enum UsdtNetwork: string
{
    case TRC20 = 'TRC20';
    case ERC20 = 'ERC20';
    case BEP20 = 'BEP20';
    case APTOS = 'APTOS';

    public function label(): string
    {
        return $this->value;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}