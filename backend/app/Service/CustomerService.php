<?php

namespace App\Service;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class CustomerService
{
    /**
     * Get a paginated list of customers.
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Customer::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $digits = preg_replace('/\D/', '', $search);

            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', '%'.mb_strtolower($search).'%');

                // Phone is stored as digits: "(47) 9999" must match too.
                if ($digits !== '') {
                    $q->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Get a single customer by ID.
     */
    public function index(int $id): Customer
    {
        return Customer::findOrFail($id);
    }

    /**
     * Create a new customer.
     */
    public function create(array $data): Customer
    {
        return Customer::create($data);
    }

    /**
     * Self-registration from the public form: always enters the funnel as an active lead,
     * whatever the request says about status/active.
     */
    public function register(array $data): Customer
    {
        return Customer::create([
            ...$data,
            'status' => CustomerStatus::Lead,
            'active' => true,
        ]);
    }

    /**
     * Update an existing customer.
     */
    public function update(int $id, array $data): Customer
    {
        $customer = Customer::findOrFail($id);
        $customer->update($data);

        return $customer;
    }

    /**
     * Soft-delete a customer (keeps history; restorable).
     */
    public function delete(int $id): bool
    {
        return Customer::findOrFail($id)->delete();
    }

    /**
     * Validation rules shared by the API (authenticated + public) and the `customer` artisan
     * command, so every entry point enforces the same domain rules.
     * "Email or phone": checked here on create; on a partial update it depends on the stored
     * record, so the Customer model's saving guard decides (plus a Postgres CHECK underneath).
     */
    public function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $contact = fn (string $other) => $partial ? 'nullable' : "required_without:{$other}";

        return [
            'name' => [$required, 'string', 'min:2', 'max:255'],
            'email' => ['nullable', $contact('phone'), 'string', 'email', 'max:255'],
            'phone' => ['nullable', $contact('email'), 'string', 'max:20', $this->digits(10, 11, 'O telefone deve ter DDD + número (10 ou 11 dígitos).')],
            'status' => ['sometimes', Rule::enum(CustomerStatus::class)],
            'active' => ['sometimes', 'boolean'],
            'zip_code' => ['nullable', 'string', 'max:9', $this->digits(8, 8, 'O CEP deve ter 8 dígitos.')],
            'street' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Accepts masked input ("(47) 99999-0000", "89160-000"); the model stores digits only.
     */
    private function digits(int $min, int $max, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($min, $max, $message) {
            $length = strlen(preg_replace('/\D/', '', (string) $value));
            if ($length < $min || $length > $max) {
                $fail($message);
            }
        };
    }
}
