<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Service\FinanceDashboardService;
use App\Service\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService,
        protected FinanceDashboardService $dashboardService,
    ) {
    }

    #[Permission('finance.transaction.view')]
    public function list(Request $request): JsonResponse
    {
        $filters = $request->validate($this->transactionService->filterRules());
        $perPage = min((int) $request->input('per_page', 15), 100);

        return response()->json($this->transactionService->list($filters, $perPage));
    }

    /**
     * Enum options for selects, filters and badges.
     */
    #[Permission('finance.transaction.view')]
    public function options(): JsonResponse
    {
        return response()->json([
            'types' => TransactionType::options(),
            'statuses' => TransactionStatus::options(),
            'sources' => TransactionSource::options(),
        ]);
    }

    #[Permission('finance.transaction.view')]
    public function index(int $id): JsonResponse
    {
        return response()->json($this->transactionService->index($id));
    }

    /**
     * Web entries are always `manual`; other sources have their own entry points.
     */
    #[Permission('finance.transaction.create')]
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate($this->transactionService->rules());

        return response()->json($this->transactionService->create($validated), 201);
    }

    #[Permission('finance.transaction.update')]
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate($this->transactionService->rules(partial: true));

        return response()->json($this->transactionService->update($id, $validated));
    }

    #[Permission('finance.transaction.delete')]
    public function delete(int $id): JsonResponse
    {
        $this->transactionService->delete($id);

        return response()->json(['message' => 'Transação excluída com sucesso.']);
    }

    /**
     * Dashboard aggregates for a period (defaults to the current month).
     */
    #[Permission('finance.dashboard')]
    public function dashboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json($this->dashboardService->summary(
            $validated['from'] ?? now()->startOfMonth()->toDateString(),
            $validated['to'] ?? now()->toDateString(),
        ));
    }
}
