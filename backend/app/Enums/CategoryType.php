<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Which transaction types a category accepts. */
enum CategoryType: string
{
    use HasOptions;

    case Income = 'income';
    case Expense = 'expense';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Receita',
            self::Expense => 'Despesa',
            self::Both => 'Ambos',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Income => 'success',
            self::Expense => 'warning',
            self::Both => 'info',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Income => 'fa-solid fa-arrow-trend-up',
            self::Expense => 'fa-solid fa-arrow-trend-down',
            self::Both => 'fa-solid fa-arrows-left-right',
        };
    }

    public function allows(TransactionType $type): bool
    {
        return $this === self::Both || $this->value === $type->value;
    }
}
