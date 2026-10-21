<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Goal;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    /**
     * Maps the short, URL-friendly subject key to its model class and the permission
     * needed to read its history. Add an entry whenever a model adopts Auditable.
     */
    private const SUBJECT_MAP = [
        'user' => [User::class, 'users.view'],
        'customer' => [Customer::class, 'customers.view'],
        'project' => [Project::class, 'projects.view'],
        'category' => [Category::class, 'finance.category.view'],
        'transaction' => [Transaction::class, 'finance.transaction.view'],
        'goal' => [Goal::class, 'finance.goal.view'],
        'company' => [Company::class, 'master'],
    ];

    /**
     * Change history for a single record, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(self::SUBJECT_MAP))],
            'subject_id' => 'required|integer',
        ]);

        [$modelClass, $permission] = self::SUBJECT_MAP[$validated['subject_type']];
        $allowed = $permission === 'master' ? $request->user()->master : $request->user()->hasPermission($permission);
        abort_unless($allowed, 403, 'Você não possui permissão para realizar esta ação.');

        $subject = $modelClass::findOrFail($validated['subject_id']);

        $activities = Activity::forSubject($subject)
            // A master acting in this company belongs to another one: load the name anyway.
            ->with(['causer' => fn ($query) => $query->withoutGlobalScope('company')])
            ->latest()
            ->latest('id') // same-second entries keep their real order
            ->get();

        return response()->json($activities->map(fn (Activity $activity) => $this->format($activity)));
    }

    private function format(Activity $activity): array
    {
        $after = collect($activity->changes->get('attributes', []));
        $before = collect($activity->changes->get('old', []));

        $fields = $after->keys()->merge($before->keys())->unique();

        return [
            'id' => $activity->id,
            'event' => $activity->event,
            'causer_name' => $activity->causer?->name ?? 'Sistema',
            'created_at' => $activity->created_at,
            'changes' => $fields->map(fn (string $field) => [
                'field' => $field,
                'before' => $before->get($field),
                'after' => $after->get($field),
            ])->values(),
        ];
    }
}
