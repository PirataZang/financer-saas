<?php

namespace App\Service;

use App\Enums\GoalStatus;
use App\Enums\GoalType;
use App\Models\Category;
use App\Models\Company;
use App\Models\Goal;
use App\Models\Transaction;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Metas. A goal creates and owns its category; whatever is lançado in that category is the goal's
 * money. Progress = expenses − incomes in the goal's period (aporte − resgate, gasto − estorno):
 * `current` counts paid ones, `pending` the scheduled ones.
 */
class GoalService
{
    /** Stored on the category, not on the goal. */
    private const CATEGORY_FIELDS = ['name', 'icon', 'color'];

    /** Short, user-curated list: not paginated. ponytail: one sum query per goal, batch it if lists grow. */
    public function list(): array
    {
        $today = $this->today();

        return Goal::with('category')->orderBy('end_date')->orderBy('id')->get()
            ->map(fn (Goal $goal) => $this->payload($goal, $today))
            ->all();
    }

    public function index(int $id): array
    {
        return $this->payload(Goal::with('category')->findOrFail($id), $this->today());
    }

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $category = Category::create([
                ...Arr::only($data, self::CATEGORY_FIELDS),
                'type' => GoalType::from($data['type'])->categoryType(),
            ]);
            $goal = $category->goal()->create(Arr::except($data, self::CATEGORY_FIELDS));

            return $this->index($goal->id);
        });
    }

    public function update(int $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data) {
            $goal = Goal::with('category')->findOrFail($id);
            $goal->update(Arr::except($data, self::CATEGORY_FIELDS));
            $goal->category->update([
                ...Arr::only($data, self::CATEGORY_FIELDS),
                'type' => $goal->type->categoryType(),
            ]);

            return $this->index($goal->id);
        });
    }

    /** Soft deletes the category too: its transactions keep the label, new ones can't use it. */
    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $goal = Goal::with('category')->findOrFail($id);
            $goal->delete();

            return $goal->category->delete();
        });
    }

    /** Category rules for name/icon/color (unique name per company), ignoring the goal's own category. */
    public function rules(bool $partial = false, ?int $goalId = null): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $categoryId = $goalId ? Goal::whereKey($goalId)->value('category_id') : null;

        return [
            ...Arr::only(app(CategoryService::class)->rules($partial, $categoryId), self::CATEGORY_FIELDS),
            'type' => [$required, Rule::enum(GoalType::class)],
            'target_amount' => [$required, 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    /**
     * Saving: reached once current ≥ target; missed if the deadline passes first.
     * Spending: missed as soon as current > target; reached if the deadline passes within it.
     */
    public function progress(Goal $goal, string $today): array
    {
        $sums = Transaction::query()
            ->where('category_id', $goal->category_id)
            ->where('date', '>=', $goal->start_date->toDateString())
            ->when($goal->end_date, fn ($q, $end) => $q->where('date', '<=', $end->toDateString()))
            ->select('type', 'status')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('type', 'status')
            ->toBase()
            ->get();
        $net = fn (string $status) => round(
            (float) $sums->where('status', $status)->where('type', 'expense')->sum('total')
            - (float) $sums->where('status', $status)->where('type', 'income')->sum('total'),
            2,
        );

        $current = $net('paid');
        $target = (float) $goal->target_amount;
        $ended = $goal->end_date && $goal->end_date->toDateString() < $today;
        $status = match ($goal->type) {
            GoalType::Saving => $current >= $target ? GoalStatus::Reached : ($ended ? GoalStatus::Missed : GoalStatus::InProgress),
            GoalType::Spending => $current > $target ? GoalStatus::Missed : ($ended ? GoalStatus::Reached : GoalStatus::InProgress),
        };

        return [
            'current' => $current,
            'pending' => $net('pending'),
            'percent' => round($current * 100 / $target, 1),
            'status' => $status->value,
        ];
    }

    private function payload(Goal $goal, string $today): array
    {
        return [...$goal->toArray(), 'progress' => $this->progress($goal, $today)];
    }

    /** "Prazo vencido" follows the company's timezone (Configurações › Financeiro), like the portal's bills. */
    private function today(): string
    {
        return now(SettingsService::for(Company::findOrFail(Company::current()))['finance']['timezone'])->toDateString();
    }
}
