<?php

namespace App\Service;

use App\Models\Announcement;
use App\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/** Avisos of the Portal do cliente: general (no project) or for one project's customer. */
class AnnouncementService
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Announcement::with('project:id,name')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->whereLike('title', "%{$search}%")
                ->orWhereLike('body', "%{$search}%")))
            ->latest()
            ->latest('id')
            ->paginate($perPage);
    }

    public function index(int $id): Announcement
    {
        return Announcement::with('project:id,name')->findOrFail($id);
    }

    public function create(array $data): Announcement
    {
        return Announcement::create($data)->load('project:id,name');
    }

    public function update(int $id, array $data): Announcement
    {
        $announcement = Announcement::findOrFail($id);
        $announcement->update($data);

        return $announcement->load('project:id,name');
    }

    public function delete(int $id): bool
    {
        return Announcement::findOrFail($id)->delete();
    }

    public function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('company_id', Company::current())->whereNull('deleted_at')],
            'title' => [$required, 'string', 'min:2', 'max:150'],
            'body' => [$required, 'string', 'max:5000'],
        ];
    }
}
