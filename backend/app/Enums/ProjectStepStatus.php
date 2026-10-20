<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ProjectStepStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::InProgress => 'Em andamento',
            self::Done => 'Concluída',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::InProgress => 'info',
            self::Done => 'success',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'fa-regular fa-circle',
            self::InProgress => 'fa-solid fa-circle-half-stroke',
            self::Done => 'fa-solid fa-circle-check',
        };
    }
}
