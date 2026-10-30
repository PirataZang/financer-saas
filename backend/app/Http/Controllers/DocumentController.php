<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Service\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(
        protected DocumentService $documentService,
    ) {
    }

    #[Permission('documents.view')]
    public function list(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $perPage = min((int) $request->input('per_page', 15), 200);

        return response()->json($this->documentService->list($filters, $perPage));
    }

    /**
     * The {{variables}} the editor offers, as key + label.
     */
    #[Permission('documents.view')]
    public function variables(): JsonResponse
    {
        return response()->json(collect(DocumentService::VARIABLES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values());
    }

    #[Permission('documents.view')]
    public function index(int $id): JsonResponse
    {
        return response()->json($this->documentService->index($id));
    }

    #[Permission('documents.create')]
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate($this->documentService->rules());

        return response()->json($this->documentService->create($validated), 201);
    }

    #[Permission('documents.update')]
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate($this->documentService->rules(partial: true));

        return response()->json($this->documentService->update($id, $validated));
    }

    #[Permission('documents.delete')]
    public function delete(int $id): JsonResponse
    {
        $this->documentService->delete($id);

        return response()->json(['message' => 'Documento excluído com sucesso.']);
    }

    /**
     * The document as a PDF download, variables filled from the customer / project / value given.
     */
    #[Permission('documents.emit')]
    public function emit(Request $request, int $id)
    {
        $validated = $request->validate($this->documentService->emitRules());
        ['pdf' => $pdf, 'filename' => $filename] = $this->documentService->emit($id, $validated);

        return $pdf->download($filename);
    }
}
