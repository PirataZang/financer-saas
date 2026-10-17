<?php

namespace App\Http\Controllers;

use App\Service\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Restricted area: every route is behind the `master` middleware (routes/api.php).
 */
class CompanyController extends Controller
{
    public function __construct(
        protected CompanyService $companyService,
    ) {
    }

    public function list(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => 'nullable|string|max:255']);

        return response()->json($this->companyService->list($filters, (int) $request->input('per_page', 15)));
    }

    public function index(int $id): JsonResponse
    {
        return response()->json($this->companyService->index($id));
    }

    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate($this->companyService->rules());

        return response()->json($this->companyService->create($validated), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate($this->companyService->rules($id));

        return response()->json($this->companyService->update($id, $validated));
    }

    public function delete(int $id): JsonResponse
    {
        $this->companyService->delete($id);

        return response()->json(['message' => 'Empresa excluída. Os dados foram mantidos e os usuários dela perderam o acesso.']);
    }

    public function regenerateToken(int $id): JsonResponse
    {
        return response()->json($this->companyService->regenerateToken($id));
    }

    public function revokeToken(int $id): JsonResponse
    {
        return response()->json($this->companyService->revokeToken($id));
    }
}
