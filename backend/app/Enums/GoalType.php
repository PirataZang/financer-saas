<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Saving: money put aside until it reaches the target (aporte = expense, resgate = income).
 * Spending: a ceiling — met while the expenses in the period stay at or under the target.
 */
enum GoalType: string
{
    use HasOptions;

    case Saving = 'saving';
    case Spending = 'spending';

    public function label(): string
    {
        return match ($this) {
            self::Saving => 'Investimento',
            self::Spending => 'Teto de gastos',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Saving => 'success',
            self::Spending => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Saving => 'fa-solid fa-piggy-bank',
            self::Spending => 'fa-solid fa-gauge-high',
        };
    }

    /** The type of the category the goal creates. */
    public function categoryType(): CategoryType
    {
        return match ($this) {
            self::Saving => CategoryType::Both,
            self::Spending => CategoryType::Expense,
        };
    }
}
