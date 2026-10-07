<?php

namespace Tests\Unit;

use App\Enums\CustomerStatus;
use PHPUnit\Framework\TestCase;

class CustomerStatusTest extends TestCase
{
    public function test_values_are_english_and_labels_portuguese(): void
    {
        $this->assertSame(['lead', 'warming_up', 'customer', 'cancelled'], array_column(CustomerStatus::cases(), 'value'));
        $this->assertSame('Em aquecimento', CustomerStatus::WarmingUp->label());
        $this->assertSame(
            ['value' => 'cancelled', 'label' => 'Cancelado', 'color' => 'neutral', 'icon' => 'fa-solid fa-ban'],
            CustomerStatus::options()[3],
        );
    }

    public function test_every_status_has_a_known_tone_and_an_icon(): void
    {
        foreach (CustomerStatus::cases() as $status) {
            $this->assertContains($status->color(), ['info', 'warning', 'success', 'neutral', 'danger']);
            $this->assertStringStartsWith('fa-', $status->icon());
        }
    }
}
