<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Computed by GoalService::progress, never stored. Red stays reserved for errors, so missed is warning. */
enum GoalStatus: string
{
    use HasOptions;

    case InProgress = 'in_progress';
    case Reached = 'reached';
    case Missed = 'missed';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'Em andamento',
            self::Reached => 'Atingida',
            self::Missed => 'Não atingida',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::InProgress => 'info',
            self::Reached => 'success',
            self::Missed => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::InProgress => 'fa-solid fa-hourglass-half',
            self::Reached => 'fa-solid fa-trophy',
            self::Missed => 'fa-solid fa-circle-exclamation',
        };
    }
}
