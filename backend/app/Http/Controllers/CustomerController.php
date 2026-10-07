<?php

namespace App\Http\Controllers;

use App\Attributes\Permission;
use App\Enums\CustomerStatus;
use App\Service\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function __construct(
        protected CustomerService $customerService,
    ) {
    }

    /**
     * Display a listing of customers.
     */
    #[Permission('customers.view')]
    public function list(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::enum(CustomerStatus::class)],
        ]);
        $perPage = (int) $request->input('per_page', 15);

        return response()->json($this->customerService->list($filters, $perPage));
    }

    /**
     * Status options for selects and filters.
     */
    #[Permission('customers.view')]
    public function statuses(): JsonResponse
    {
        return response()->json(CustomerStatus::options());
    }

    /**
     * Display the specified customer.
     */
    #[Permission('customers.view')]
    public function index(int $id): JsonResponse
    {
        return response()->json($this->customerService->index($id));
    }

    /**
     * Store a newly created customer.
     */
    #[Permission('customers.create')]
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate($this->customerService->rules());

        return response()->json($this->customerService->create($validated), 201);
    }

    /**
     * Public self-registration (no authentication, `throttle:public`). Status and active
     * are not accepted from the outside, and only a confirmation is returned.
     */
    public function register(Request $request): JsonResponse
    {
        $rules = $this->customerService->rules();
        unset($rules['status'], $rules['active']);

        $this->customerService->register($request->validate($rules));

        return response()->json(['message' => 'Cadastro recebido com sucesso.'], 201);
    }

    /**
     * Update the specified customer.
     */
    #[Permission('customers.update')]
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate($this->customerService->rules(partial: true));

        return response()->json($this->customerService->update($id, $validated));
    }

    /**
     * Remove the specified customer.
     */
    #[Permission('customers.delete')]
    public function delete(int $id): JsonResponse
    {
        $this->customerService->delete($id);

        return response()->json(['message' => 'Cliente excluído com sucesso.']);
    }
}
