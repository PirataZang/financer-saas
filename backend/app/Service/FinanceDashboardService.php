<?php

namespace App\Service;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aggregates for the finance dashboard. Only `paid` transactions count: pending ones haven't
 * moved money yet. Every chart on the frontend derives from this one payload.
 */
class FinanceDashboardService
{
    /** Longer ranges are bucketed by month so a yearly chart isn't 365 bars. */
    private const MAX_DAILY_BUCKETS = 62;

    public function summary(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $monthly = $start->diffInDays($end) + 1 > self::MAX_DAILY_BUCKETS;
        $bucketOf = fn (string $date) => $monthly ? substr($date, 0, 7) : substr($date, 0, 10);

        $opening = $this->totalsByType($this->paid()->where('date', '<', $from));
        $openingBalance = $opening[TransactionType::Income->value] - $opening[TransactionType::Expense->value];

        $buckets = [];
        foreach (CarbonPeriod::create($start, $monthly ? '1 month' : '1 day', $monthly ? $end->endOfMonth() : $end) as $day) {
            $buckets[$bucketOf($day->toDateString())] = ['income' => 0.0, 'expense' => 0.0];
        }

        $rows = $this->inPeriod($from, $to)
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

        $totals = $this->totalsByType($this->inPeriod($from, $to));

        return [
            'period' => ['from' => $from, 'to' => $to, 'granularity' => $monthly ? 'month' : 'day'],
            'totals' => [
                'income' => $totals['income'],
                'expense' => $totals['expense'],
                'balance' => round($totals['income'] - $totals['expense'], 2),
            ],
            'opening_balance' => round($openingBalance, 2),
            'closing_balance' => $balance,
            'timeline' => $timeline,
            'categories' => $this->byCategory($from, $to),
        ];
    }

    private function paid(): Builder
    {
        return Transaction::query()->where('status', TransactionStatus::Paid);
    }

    private function inPeriod(string $from, string $to): Builder
    {
        return $this->paid()->whereBetween('date', [$from, $to]);
    }

    /**
     * @return array{income: float, expense: float}
     */
    private function totalsByType(Builder $query): array
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
    private function byCategory(string $from, string $to): array
    {
        $rows = $this->inPeriod($from, $to)
            ->select('category_id', 'type')
            ->selectRaw('SUM(amount) as total')
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
            ];
        }

        return array_map(fn (array $list) => collect($list)->sortByDesc('total')->values()->all(), $result);
    }
}
