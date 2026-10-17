<?php

namespace App\Service;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Aggregates for the finance dashboard, the bot's balancete and the Excel export. By default only
 * `paid` transactions count (pending ones haven't moved money yet); `status=all|pending` and the
 * category/source filters narrow every figure the same way, opening balance included.
 */
class FinanceDashboardService
{
    /** Longer ranges are bucketed by month so a yearly chart isn't 365 bars. */
    private const MAX_DAILY_BUCKETS = 62;

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
            'source' => ['nullable', Rule::enum(TransactionSource::class)],
            'status' => ['nullable', Rule::in([...array_column(TransactionStatus::cases(), 'value'), 'all'])],
        ];
    }

    /**
     * Dashboard filters → Transaction::filtered() keys. Status defaults to paid; "all" drops it.
     */
    public function scope(array $filters): array
    {
        $status = $filters['status'] ?? TransactionStatus::Paid->value;

        return [
            'category_ids' => $filters['category_ids'] ?? null,
            'source' => $filters['source'] ?? null,
            'status' => $status === 'all' ? null : $status,
        ];
    }

    /**
     * $from null = everything up to $to (from the first matching transaction).
     */
    public function summary(?string $from, string $to, array $filters = []): array
    {
        $scope = $this->scope($filters);
        $base = fn () => Transaction::query()->filtered($scope);

        $from ??= $base()->min('date') ?? $to;
        $from = min($from, $to);
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $monthly = $start->diffInDays($end) + 1 > self::MAX_DAILY_BUCKETS;
        $bucketOf = fn (string $date) => $monthly ? substr($date, 0, 7) : substr($date, 0, 10);
        $inPeriod = fn () => $base()->whereBetween('date', [$from, $to]);

        $opening = $this->totalsByType($base()->where('date', '<', $from));
        $openingBalance = $opening[TransactionType::Income->value] - $opening[TransactionType::Expense->value];

        $buckets = [];
        foreach (CarbonPeriod::create($start, $monthly ? '1 month' : '1 day', $monthly ? $end->endOfMonth() : $end) as $day) {
            $buckets[$bucketOf($day->toDateString())] = ['income' => 0.0, 'expense' => 0.0];
        }

        $rows = $inPeriod()
            ->select('date', 'type')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('date', 'type')
            ->toBase()
            ->get();
        foreach ($rows as $row) {
            $buckets[$bucketOf($row->date)][$row->type] += (float) $row->total;
        }

        $balance = $openingBalance;
        $timeline = [];
        foreach ($buckets as $period => $totals) {
            $balance = round($balance + $totals['income'] - $totals['expense'], 2);
            $timeline[] = [
                'period' => $period,
                'income' => round($totals['income'], 2),
                'expense' => round($totals['expense'], 2),
                'balance' => $balance,
            ];
        }

        $totals = $this->totalsByType($inPeriod());
        $categories = $this->byCategory($inPeriod);

        return [
            'period' => ['from' => $from, 'to' => $to, 'granularity' => $monthly ? 'month' : 'day'],
            'totals' => [
                'income' => $totals['income'],
                'expense' => $totals['expense'],
                'balance' => round($totals['income'] - $totals['expense'], 2),
            ],
            'opening_balance' => round($openingBalance, 2),
            'closing_balance' => $balance,
            'counts' => array_map(fn (array $list) => array_sum(array_column($list, 'count')), $categories),
            'timeline' => $timeline,
            'categories' => $categories,
        ];
    }

    /**
     * Also the transaction list's totals (TransactionService::totals).
     *
     * @return array{income: float, expense: float}
     */
    public function totalsByType(Builder $query): array
    {
        $sums = $query->select('type')->selectRaw('SUM(amount) as total')->groupBy('type')->toBase()->pluck('total', 'type');

        return [
            'income' => round((float) ($sums['income'] ?? 0), 2),
            'expense' => round((float) ($sums['expense'] ?? 0), 2),
        ];
    }

    /**
     * @return array{income: list<array>, expense: list<array>}
     */
    private function byCategory(Closure $inPeriod): array
    {
        $rows = $inPeriod()
            ->select('category_id', 'type')
            ->selectRaw('SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category_id', 'type')
            ->with('category:id,name,icon,color')
            ->get();

        $result = ['income' => [], 'expense' => []];
        foreach ($rows as $row) {
            $result[$row->type->value][] = [
                'id' => $row->category_id,
                'name' => $row->category?->name,
                'icon' => $row->category?->icon,
                'color' => $row->category?->color,
                'total' => round((float) $row->getRawOriginal('total'), 2),
                'count' => (int) $row->getRawOriginal('count'),
            ];
        }

        return array_map(fn (array $list) => collect($list)->sortByDesc('total')->values()->all(), $result);
    }
}
