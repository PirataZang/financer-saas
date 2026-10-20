<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Enums\ProjectStatus;
use App\Enums\ProjectStepStatus;
use App\Service\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        protected ProjectService $projectService,
    ) {
    }

    #[Permission('projects.view')]
    public function list(Request $request): JsonResponse
    {
        $filters = $request->validate($this->projectService->filterRules());
        $perPage = min((int) $request->input('per_page', 15), 100);

        return response()->json($this->projectService->list($filters, $perPage));
    }

    /**
     * Enum options for selects and badges: project and step statuses.
     */
    #[Permission('projects.view')]
    public function statuses(): JsonResponse
    {
        return response()->json([
            'statuses' => ProjectStatus::options(),
            'step_statuses' => ProjectStepStatus::options(),
        ]);
    }

    #[Permission('projects.view')]
    public function options(): JsonResponse
    {
        return response()->json($this->projectService->options());
    }

    #[Permission('projects.view')]
    public function index(int $id): JsonResponse
    {
        return response()->json($this->projectService->index($id));
    }

    #[Permission('projects.create')]
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate($this->projectService->rules());

        return response()->json($this->projectService->create($validated), 201);
    }

    #[Permission('projects.update')]
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate($this->projectService->rules(partial: true));

        return response()->json($this->projectService->update($id, $validated));
    }

    #[Permission('projects.delete')]
    public function delete(int $id): JsonResponse
    {
        $this->projectService->delete($id);

        return response()->json(['message' => 'Projeto excluído com sucesso.']);
    }
}
