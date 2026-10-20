<?php

namespace App\Service;

use App\Enums\ProjectStatus;
use App\Enums\ProjectStepStatus;
use App\Models\Company;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Customer projects (Portal do cliente). Steps are edited together with the project: the form
 * sends the whole ordered list and it replaces the stored one (sort_order = position).
 */
class ProjectService
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Project::with(['customer:id,name', 'steps'])
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->whereLike('name', "%{$search}%")
                ->orWhereHas('customer', fn ($q) => $q->whereLike('name', "%{$search}%"))))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->latest()
            ->latest('id')
            ->paginate($perPage);
    }

    public function index(int $id): Project
    {
        return Project::with(['customer:id,name', 'steps'])->findOrFail($id);
    }

    /** For selects (e.g. the project of an announcement): "Project · Customer". */
    public function options(): array
    {
        return Project::with('customer:id,name')->orderBy('name')->get()
            ->map(fn (Project $p) => ['value' => $p->id, 'label' => "{$p->name} · {$p->customer?->name}"])
            ->all();
    }

    public function create(array $data): Project
    {
        return DB::transaction(function () use ($data) {
            $project = Project::create(Arr::except($data, 'steps'));
            $this->syncSteps($project, $data['steps'] ?? []);

            return $this->index($project->id);
        });
    }

    public function update(int $id, array $data): Project
    {
        return DB::transaction(function () use ($id, $data) {
            $project = Project::findOrFail($id);
            $project->update(Arr::except($data, 'steps'));
            if (array_key_exists('steps', $data)) {
                $this->syncSteps($project, $data['steps'] ?? []);
            }

            return $this->index($project->id);
        });
    }

    public function delete(int $id): bool
    {
        return Project::findOrFail($id)->delete();
    }

    public function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'customer_id' => [$required, 'integer', Rule::exists('customers', 'id')->where('company_id', Company::current())->whereNull('deleted_at')],
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'steps' => ['sometimes', 'nullable', 'array', 'max:50'],
            'steps.*.name' => ['required', 'string', 'max:120'],
            'steps.*.status' => ['required', Rule::enum(ProjectStepStatus::class)],
            'steps.*.due_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function filterRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'customer_id' => ['nullable', 'integer'],
        ];
    }

    private function syncSteps(Project $project, array $steps): void
    {
        $project->steps()->delete();
        foreach (array_values($steps) as $order => $step) {
            $project->steps()->create([...$step, 'sort_order' => $order]);
        }
    }
}
