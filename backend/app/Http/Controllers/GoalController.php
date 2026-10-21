<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Enums\GoalStatus;
use App\Enums\GoalType;
use App\Service\GoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function __construct(
        protected GoalService $goalService,
    ) {
    }

    #[Permission('finance.goal.view')]
    public function list(): JsonResponse
    {
        return response()->json($this->goalService->list());
    }

    /**
     * Enum options for selects and badges: goal types and (computed) statuses.
     */
    #[Permission('finance.goal.view')]
    public function options(): JsonResponse
    {
        return response()->json([
            'types' => GoalType::options(),
            'statuses' => GoalStatus::options(),
        ]);
    }

    #[Permission('finance.goal.view')]
    public function index(int $id): JsonResponse
    {
        return response()->json($this->goalService->index($id));
    }

    #[Permission('finance.goal.create')]
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate($this->goalService->rules());

        return response()->json($this->goalService->create($validated), 201);
    }

    #[Permission('finance.goal.update')]
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate($this->goalService->rules(partial: true, goalId: $id));

        return response()->json($this->goalService->update($id, $validated));
    }

    #[Permission('finance.goal.delete')]
    public function delete(int $id): JsonResponse
    {
        $this->goalService->delete($id);

        return response()->json(['message' => 'Meta excluída com sucesso.']);
    }
}
