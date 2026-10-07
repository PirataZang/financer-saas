<?php

namespace App\Enums\Concerns;

/**
 * Select/filter/badge options for a backed enum exposing label(), color() and icon().
 */
trait HasOptions
{
    /**
     * @return list<array{value: string, label: string, color: string, icon: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'color' => $case->color(),
            'icon' => $case->icon(),
        ], self::cases());
    }
}
