<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Service\FinanceDashboardService;
use App\Service\FinanceExportService;
use App\Service\MercadoPagoService;
use App\Service\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TransactionController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService,
        protected FinanceDashboardService $dashboardService,
        protected FinanceExportService $exportService,
        protected MercadoPagoService $mercadoPago,
    ) {
    }

    #[Permission('finance.transaction.view')]
    public function list(Request $request): JsonResponse
    {
        $filters = $request->validate($this->transactionService->filterRules());
        $perPage = min((int) $request->input('per_page', 15), 100);

        return response()->json([
            ...$this->transactionService->list($filters, $perPage)->toArray(),
            'totals' => $this->transactionService->totals($filters),
        ]);
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
     * Web entries are always `manual`; other sources have their own entry points. With
     * `repeat` (installments | monthly) + `repeat_count` it creates the whole series and returns the 1st.
     */
    #[Permission('finance.transaction.create')]
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([...$this->transactionService->rules(), ...$this->transactionService->repeatRules()]);
        $repeat = Arr::pull($validated, 'repeat');
        $count = Arr::pull($validated, 'repeat_count');

        $transaction = $repeat
            ? $this->transactionService->createSeries($validated, $repeat, $count)
            : $this->transactionService->create($validated);

        return response()->json($transaction, 201);
    }

    /**
     * Bulk status change for the list's selection (ids[] + status).
     */
    #[Permission('finance.transaction.update')]
    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate($this->transactionService->statusRules());
        $updated = $this->transactionService->setStatus($validated['ids'], TransactionStatus::from($validated['status']));

        return response()->json(['updated' => $updated]);
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
     * Dashboard aggregates for a period (defaults to the current month) and filters
     * (category_ids[], source, status = paid (default) | pending | all).
     */
    #[Permission('finance.dashboard')]
    public function dashboard(Request $request): JsonResponse
    {
        $validated = $request->validate($this->dashboardService->rules());

        $from = $validated['from'] ?? now()->startOfMonth()->toDateString();
        $to = $validated['to'] ?? now()->toDateString();

        return response()->json([
            ...$this->dashboardService->summary($from, $to, $validated),
            // Integration panel (null = not connected or switched off); its own figures, same period.
            'mercado_pago' => $this->mercadoPago->dashboard($from, $to),
        ]);
    }

    /**
     * The dashboard's data as an Excel workbook (Resumo with formulas, Mensal, Transações, Categorias).
     */
    #[Permission('finance.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $validated = $request->validate($this->dashboardService->rules());
        $from = $validated['from'] ?? now()->startOfMonth()->toDateString();
        $to = $validated['to'] ?? now()->toDateString();

        return response()
            ->download($this->exportService->export($from, $to, $validated), "financeiro_{$from}_a_{$to}.xlsx")
            ->deleteFileAfterSend();
    }
}
