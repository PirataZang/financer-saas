<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Enums\CategoryType;
use App\Enums\TransactionType;
use App\Service\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function __construct(
        protected CategoryService $categoryService,
    ) {
    }

    #[Permission('finance.category.view')]
    public function list(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:255',
            'type' => ['nullable', Rule::enum(CategoryType::class)],
            'active' => 'nullable|boolean',
        ]);

        return response()->json($this->categoryService->list($filters));
    }

    /**
     * Category type options (Receita / Despesa / Ambos).
     */
    #[Permission('finance.category.view')]
    public function types(): JsonResponse
    {
        return response()->json(CategoryType::options());
    }

    /**
     * Active categories for selects — transaction screens need them, so it follows transaction.view.
     */
    #[Permission('finance.transaction.view')]
    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate(['type' => ['nullable', Rule::enum(TransactionType::class)]]);

        return response()->json($this->categoryService->options(TransactionType::tryFrom($validated['type'] ?? '')));
    }

    #[Permission('finance.category.view')]
    public function index(int $id): JsonResponse
    {
        return response()->json($this->categoryService->index($id));
    }

    #[Permission('finance.category.create')]
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate($this->categoryService->rules());

        return response()->json($this->categoryService->create($validated), 201);
    }

    #[Permission('finance.category.update')]
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate($this->categoryService->rules(partial: true, ignoreId: $id));

        return response()->json($this->categoryService->update($id, $validated));
    }

    #[Permission('finance.category.delete')]
    public function delete(int $id): JsonResponse
    {
        $this->categoryService->delete($id);

        return response()->json(['message' => 'Categoria excluída com sucesso.']);
    }
}
