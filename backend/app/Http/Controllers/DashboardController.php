<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Service\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService,
    ) {
    }

    /**
     * Return example KPI/chart data for the dashboard.
     */
    #[Permission('dashboard.view')]
    public function summary(): JsonResponse
    {
        return response()->json($this->dashboardService->summary());
    }
}
