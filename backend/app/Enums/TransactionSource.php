<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Entry point a transaction came from. The finance core treats every source the same;
 * a new integration (CSV, another bank feed) is a new case here, nothing else.
 */
enum TransactionSource: string
{
    use HasOptions;

    case Manual = 'manual';
    case Telegram = 'telegram';
    case Import = 'import';
    case OpenFinance = 'open_finance';
    // Configurações › Integrações (read-only sync, App\Service\MercadoPagoService).
    case MercadoPago = 'mercado_pago';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Telegram => 'Telegram',
            self::Import => 'Importação',
            self::OpenFinance => 'Open Finance',
            self::MercadoPago => 'Mercado Pago',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Manual => 'neutral',
            self::Telegram => 'info',
            self::Import => 'neutral',
            self::OpenFinance => 'info',
            self::MercadoPago => 'info',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Manual => 'fa-solid fa-keyboard',
            self::Telegram => 'fa-brands fa-telegram',
            self::Import => 'fa-solid fa-file-import',
            self::OpenFinance => 'fa-solid fa-building-columns',
            self::MercadoPago => 'fa-solid fa-handshake',
        };
    }
}
