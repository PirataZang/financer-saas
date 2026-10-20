<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ProjectStatus: string
{
    use HasOptions;

    case Planning = 'planning';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planejamento',
            self::InProgress => 'Em andamento',
            self::Paused => 'Pausado',
            self::Done => 'Concluído',
            self::Cancelled => 'Cancelado',
        };
    }

    /** Design-system tone; red stays reserved for errors, so cancelled is neutral. */
    public function color(): string
    {
        return match ($this) {
            self::Planning => 'neutral',
            self::InProgress => 'info',
            self::Paused => 'warning',
            self::Done => 'success',
            self::Cancelled => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Planning => 'fa-solid fa-compass-drafting',
            self::InProgress => 'fa-solid fa-person-digging',
            self::Paused => 'fa-solid fa-pause',
            self::Done => 'fa-solid fa-flag-checkered',
            self::Cancelled => 'fa-solid fa-ban',
        };
    }
}
