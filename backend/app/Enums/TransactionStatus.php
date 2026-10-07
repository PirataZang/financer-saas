<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TransactionStatus: string
{
    use HasOptions;

    case Paid = 'paid';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Efetivada',
            self::Pending => 'Pendente',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Pending => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Paid => 'fa-solid fa-circle-check',
            self::Pending => 'fa-solid fa-clock',
        };
    }
}
