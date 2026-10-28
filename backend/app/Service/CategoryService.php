<?php

namespace App\Service;

use App\Enums\CategoryType;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    /**
     * Every category matching the filters, in display order. Not paginated: categories are a
     * short, user-curated list. ponytail: paginate if anyone ever has hundreds.
     */
    public function list(array $filters = []): Collection
    {
        return Category::query()
            ->withCount('transactions')
            ->with('goal:id,category_id,type')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereLike('name', "%{$search}%"))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when(isset($filters['active']), fn ($q) => $q->where('active', (bool) $filters['active']))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Active categories that accept the given transaction type (all active ones without a type),
     * in the `[{ value, label, color, icon }]` shape every select and the bot consume.
     */
    public function options(?TransactionType $type = null): Collection
    {
        return Category::query()
            ->where('active', true)
            ->when($type, fn ($q) => $q->whereIn('type', [$type->value, CategoryType::Both->value]))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => [
                'value' => $category->id,
                'label' => $category->name,
                'color' => $category->color,
                'icon' => $category->icon,
                'type' => $category->type->value,
            ]);
    }

    public function index(int $id): Category
    {
        return Category::withCount('transactions')->findOrFail($id);
    }

    public function create(array $data): Category
    {
        return Category::create($data);
    }

    public function update(int $id, array $data): Category
    {
        $category = Category::findOrFail($id);
        if (isset($data['type']) && $data['type'] !== $category->type->value) {
            $this->guardGoal($category);
            $this->guardIntegration($category);
        }
        if (($data['active'] ?? true) === false) {
            $this->guardIntegration($category);
        }
        $category->update($data);

        return $category;
    }

    /**
     * Soft delete: past transactions keep showing their category.
     */
    public function delete(int $id): bool
    {
        $category = Category::findOrFail($id);
        $this->guardGoal($category);
        $this->guardIntegration($category);

        return $category->delete();
    }

    /** A meta's category follows the meta: its type and deletion only change on the Metas screen. */
    private function guardGoal(Category $category): void
    {
        if ($category->goal()->exists()) {
            throw ValidationException::withMessages(['category' => ['Esta categoria pertence a uma meta: altere o tipo ou exclua pela tela de Metas.']]);
        }
    }

    /** An integration's category (Mercado Pago) receives its entries: it stays active, typed and alive while connected. */
    private function guardIntegration(Category $category): void
    {
        if ($category->integration_key) {
            throw ValidationException::withMessages(['category' => ['Esta categoria é da integração com o Mercado Pago: dá para mudar nome, ícone e cor; para removê-la, remova a integração em Configurações › Integrações.']]);
        }
    }

    public function rules(bool $partial = false, ?int $ignoreId = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:2', 'max:60',
                Rule::unique('finance_categories', 'name')->where('company_id', Company::current())->whereNull('deleted_at')->ignore($ignoreId)],
            'icon' => ['nullable', 'string', 'max:16'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'type' => ['sometimes', Rule::enum(CategoryType::class)],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
