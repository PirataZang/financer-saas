<?php

namespace App\Service;

use App\Models\Company;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Tenant management (masters only — see routes/api.php). Deleting is a soft delete:
 * the company's data stays and its users are blocked; the default company can't go.
 */
class CompanyService
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Company::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $digits = preg_replace('/\D/', '', $search);

            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%")->orWhere('trade_name', 'like', "%{$search}%");
                if ($digits !== '') {
                    $q->orWhere('document', 'like', "%{$digits}%");
                }
            });
        }

        return $query->orderBy('id')->paginate($perPage);
    }

    /** With the token: this payload only ever reaches masters. */
    public function index(int $id): Company
    {
        return Company::findOrFail($id)->makeVisible('token');
    }

    public function create(array $data): Company
    {
        $company = Company::create($data);
        $company->regenerateToken();

        return $company->makeVisible('token');
    }

    public function update(int $id, array $data): Company
    {
        $company = Company::findOrFail($id);
        $company->update($data);

        return $company->makeVisible('token');
    }

    public function delete(int $id): bool
    {
        return Company::findOrFail($id)->delete();
    }

    /**
     * New token = license (re)activated; a public form using the old one stops working.
     * A company with an expiry date gets 30 more days from today; one without stays without.
     */
    public function regenerateToken(int $id): Company
    {
        $company = Company::findOrFail($id);
        if ($company->expires_at) {
            $company->expires_at = today()->addDays(30);
        }
        $company->regenerateToken();

        return $company->makeVisible('token');
    }

    /** No token = blocked right away, whatever expires_at says. */
    public function revokeToken(int $id): Company
    {
        $company = Company::findOrFail($id);
        $company->revokeToken();

        return $company->makeVisible('token');
    }

    public function rules(?int $id = null): array
    {
        $required = $id ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:2', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'document' => ['nullable', 'string', 'max:18', $this->document(), Rule::unique('companies', 'document')->ignore($id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'zip_code' => ['nullable', 'string', 'max:9', fn ($a, $v, $fail) => strlen(preg_replace('/\D/', '', (string) $v)) === 8 || $fail('O CEP deve ter 8 dígitos.')],
            'street' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:20'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** CPF (11) or CNPJ (14) with valid check digits; masked input accepted. */
    private function document(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (!self::validDocument(preg_replace('/\D/', '', (string) $value))) {
                $fail('Informe um CPF ou CNPJ válido.');
            }
        };
    }

    public static function validDocument(string $digits): bool
    {
        $length = strlen($digits);
        if (!in_array($length, [11, 14], true) || preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }

        // Each check digit: weighted sum of the digits before it (CPF 10..2 / 11..2; CNPJ 2..9 cycling from the right).
        for ($position = $length - 2; $position < $length; $position++) {
            $sum = 0;
            for ($i = 0; $i < $position; $i++) {
                $sum += $digits[$i] * ($length === 11 ? $position + 1 - $i : 2 + ($position - 1 - $i) % 8);
            }
            if ((int) $digits[$position] !== ($sum % 11 < 2 ? 0 : 11 - $sum % 11)) {
                return false;
            }
        }

        return true;
    }
}
