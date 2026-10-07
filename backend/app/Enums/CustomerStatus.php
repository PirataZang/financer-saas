<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CustomerStatus: string
{
    use HasOptions;

    case Lead = 'lead';
    case WarmingUp = 'warming_up';
    case Customer = 'customer';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Lead',
            self::WarmingUp => 'Em aquecimento',
            self::Customer => 'Cliente',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * Design-system tone (frontend maps it to --color-{tone} / --color-{tone}-soft).
     * Red (danger) stays reserved for errors and destructive actions, so cancelled is neutral.
     */
    public function color(): string
    {
        return match ($this) {
            self::Lead => 'info',
            self::WarmingUp => 'warning',
            self::Customer => 'success',
            self::Cancelled => 'neutral',
        };
    }

    /** Font Awesome icon class. */
    public function icon(): string
    {
        return match ($this) {
            self::Lead => 'fa-solid fa-seedling',
            self::WarmingUp => 'fa-solid fa-fire',
            self::Customer => 'fa-solid fa-handshake',
            self::Cancelled => 'fa-solid fa-ban',
        };
    }
}
