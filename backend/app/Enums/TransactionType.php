<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TransactionType: string
{
    use HasOptions;

    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Receita',
            self::Expense => 'Despesa',
        };
    }

    /** Red (danger) is reserved for errors/destructive actions, so expenses are warning. */
    public function color(): string
    {
        return match ($this) {
            self::Income => 'success',
            self::Expense => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Income => 'fa-solid fa-arrow-trend-up',
            self::Expense => 'fa-solid fa-arrow-trend-down',
        };
    }
}
