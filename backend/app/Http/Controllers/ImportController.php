<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Models\Import;
use App\Service\Import\ImportDefinition;
use App\Service\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(
        protected ImportService $imports,
    ) {
    }

    /**
     * Each type also asks for the permissions of the data it touches: importing users needs
     * users.create and users.update, so the import screen can't be used to get around them.
     */
    private function definition(Request $request, string $type, bool $manage): ImportDefinition
    {
        $definition = $this->imports->definition($type);
        $keys = [...$definition->viewPermissions(), ...($manage ? $definition->managePermissions() : [])];

        foreach ($keys as $key) {
            abort_unless($request->user()->hasPermission($key), 403, 'Você não tem permissão para esta importação.');
        }

        return $definition;
    }

    private function import(Request $request, int $id): Import
    {
        $import = Import::findOrFail($id);
        $this->definition($request, $import->type, false);

        return $import;
    }

    #[Permission('imports.view')]
    public function list(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['required', 'in:customer,user']])['type'];
        $this->definition($request, $type, false);

        return response()->json($this->imports->list($type, min((int) $request->input('per_page', 15), 100)));
    }

    #[Permission('imports.view')]
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->imports->find($this->import($request, $id)->id));
    }

    #[Permission('imports.view')]
    public function imported(Request $request, int $id): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 100);

        return response()->json($this->imports->imported($this->import($request, $id), $perPage));
    }

    #[Permission('imports.view')]
    public function errors(Request $request, int $id): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 100);

        return response()->json($this->imports->failures($this->import($request, $id), $perPage));
    }

    #[Permission('imports.view')]
    public function errorsCsv(Request $request, int $id): StreamedResponse
    {
        return $this->imports->failuresCsv($this->import($request, $id));
    }

    #[Permission('imports.create')]
    public function template(Request $request, string $type): BinaryFileResponse
    {
        $definition = $this->definition($request, $type, true);

        return response()->download($this->imports->template($type), "modelo_importacao_{$definition->slug()}.xlsx")->deleteFileAfterSend();
    }

    /**
     * Reads the spreadsheet: headers, rows and the suggested column mapping. Nothing is saved yet.
     */
    #[Permission('imports.create')]
    public function parse(Request $request, string $type): JsonResponse
    {
        $this->definition($request, $type, true);
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt'],
        ], [
            'file.max' => 'O arquivo passa de 5 MB.',
            'file.extensions' => 'Envie um arquivo .xlsx ou .csv.',
        ]);

        return response()->json($this->imports->parse($type, $request->file('file')));
    }

    /**
     * Validates mapped rows with the registration rules; also flags the record a row would update.
     */
    #[Permission('imports.create')]
    public function validateRows(Request $request, string $type): JsonResponse
    {
        $this->definition($request, $type, true);
        $validated = $request->validate([
            'rows' => ['required', 'array', 'max:'.ImportService::MAX_ROWS],
            'rows.*' => ['array'],
        ]);

        return response()->json(array_map(
            fn ($row) => collect($this->imports->validate($type, $row))->only(['errors', 'existing']),
            $validated['rows'],
        ));
    }

    /**
     * Confirms the import: rows are saved and processed in chunks by the queue.
     */
    #[Permission('imports.create')]
    public function store(Request $request, string $type): JsonResponse
    {
        $this->definition($request, $type, true);
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'rows' => ['required', 'array', 'min:1', 'max:'.ImportService::MAX_ROWS],
            'rows.*.line' => ['required', 'integer', 'min:1'],
            'rows.*.data' => ['required', 'array'],
        ]);

        $import = $this->imports->store($type, $validated['filename'], $validated['rows'], $request->user()?->id);

        return response()->json($this->imports->find($import->id), 201);
    }
}
