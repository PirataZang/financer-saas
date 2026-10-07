<?php

namespace App\Service;

class DashboardService
{
    /**
     * Build example KPI/chart data so the dashboard has something to render
     * out of the box. Replace with real queries once you have models.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'total' => 128,
            'monthly_revenue' => 18450.00,
            'overdue_count' => 3,
            'upcoming_due' => [
                ['name' => 'Cliente Exemplo', 'days_until_due' => 5],
            ],
            'by_category' => [
                'novo' => 42,
                'em_andamento' => 63,
                'concluido' => 23,
            ],
            'monthly_series' => [
                ['month' => 'Jan', 'value' => 12000],
                ['month' => 'Fev', 'value' => 15400],
                ['month' => 'Mar', 'value' => 13950],
                ['month' => 'Abr', 'value' => 18450],
            ],
        ];
    }
}
