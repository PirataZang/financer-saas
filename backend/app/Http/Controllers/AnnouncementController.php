<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Service\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function __construct(
        protected AnnouncementService $announcementService,
    ) {
    }

    #[Permission('announcements.view')]
    public function list(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => 'nullable|string|max:255']);
        $perPage = min((int) $request->input('per_page', 15), 100);

        return response()->json($this->announcementService->list($filters, $perPage));
    }

    #[Permission('announcements.view')]
    public function index(int $id): JsonResponse
    {
        return response()->json($this->announcementService->index($id));
    }

    #[Permission('announcements.create')]
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate($this->announcementService->rules());

        return response()->json($this->announcementService->create($validated), 201);
    }

    #[Permission('announcements.update')]
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate($this->announcementService->rules(partial: true));

        return response()->json($this->announcementService->update($id, $validated));
    }

    #[Permission('announcements.delete')]
    public function delete(int $id): JsonResponse
    {
        $this->announcementService->delete($id);

        return response()->json(['message' => 'Aviso excluído com sucesso.']);
    }
}
