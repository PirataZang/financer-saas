<?php

namespace App\Service;

use App\Enums\CustomerStatus;
use App\Enums\ProjectStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Home dashboard. "Atrasado" = a pending income past its date (customer) or an open project past
 * its due date. Month series cover the last MONTHS months, oldest first, zero-filled.
 */
class DashboardService
{
    private const MONTHS = 12;

    /** Projects still being worked on: neither finished nor cancelled. */
    private const OPEN = [ProjectStatus::Planning, ProjectStatus::InProgress, ProjectStatus::Paused];

    private const DUE_SOON_DAYS = 7;

    private const STALE_DAYS = 30;

    /** Rows shown per "needs attention" list (the total still counts all). */
    private const LIST_SIZE = 5;

    /** Days late → label; the last bucket is open-ended. */
    private const DELAY_BUCKETS = [['Até 7 dias', 7], ['8 a 30 dias', 30], ['31 a 90 dias', 90], ['Mais de 90 dias', null]];

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $today = CarbonImmutable::today();
        $since = $today->subMonths(self::MONTHS - 1)->startOfMonth();

        $overdueCustomers = Transaction::query()
            ->where('type', TransactionType::Income)
            ->where('status', TransactionStatus::Pending)
            ->where('date', '<', $today->toDateString())
            ->whereNotNull('customer_id')
            ->distinct()->pluck('customer_id');

        // Cancelled/inactive customers owe nothing to the pipeline; they're not "em dia" either.
        $billable = fn () => Customer::query()->where('active', true)->where('status', '!=', CustomerStatus::Cancelled);
        $late = $billable()->whereIn('id', $overdueCustomers)->count();

        $open = fn () => Project::query()->whereIn('status', self::OPEN);
        $lateProjects = $open()->whereNotNull('due_date')->where('due_date', '<', $today->toDateString());
        $lateCount = (clone $lateProjects)->count();

        return [
            'customers' => ['late' => $late, 'on_time' => $billable()->count() - $late],
            'projects' => ['late' => $lateCount, 'on_time' => $open()->count() - $lateCount],
            'customers_joined' => $this->byMonth(Customer::query()->where('created_at', '>=', $since), 'created_at', $since),
            // ponytail: no completed_at column, so "when it finished" = last update of a done project.
            'projects_done' => $this->byMonth(Project::query()->where('status', ProjectStatus::Done)->where('updated_at', '>=', $since), 'updated_at', $since),
            'projects_late' => $this->delayBuckets($lateProjects->pluck('due_date'), $today),
            'attention' => [
                'projects_due_soon' => $this->dueSoon($open(), $today),
                'stale_leads' => $this->staleLeads($today),
                'customers_without_project' => $this->withoutProject(),
            ],
            'customers_by_status' => array_map(
                fn (CustomerStatus $s) => ['key' => $s->value, 'label' => $s->label(), 'value' => Customer::query()->where('status', $s)->count()],
                CustomerStatus::cases(),
            ),
        ];
    }

    /**
     * @return list<array{month: string, value: int}>
     */
    private function byMonth($query, string $column, CarbonImmutable $since): array
    {
        // ponytail: grouped in PHP (portable across Postgres/sqlite); fine for a 12-month window, SQL GROUP BY if it grows.
        $counts = $query->pluck($column)->countBy(fn ($date) => $date->format('Y-m'));

        $series = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = $since->addMonths($i);
            $series[] = ['month' => $month->format('Y-m'), 'value' => $counts[$month->format('Y-m')] ?? 0];
        }

        return $series;
    }

    /** @return list<array{label: string, value: int}> */
    private function delayBuckets($dueDates, CarbonImmutable $today): array
    {
        $buckets = array_map(fn ($b) => ['label' => $b[0], 'value' => 0], self::DELAY_BUCKETS);

        foreach ($dueDates as $due) {
            $days = $due->startOfDay()->diffInDays($today);
            foreach (self::DELAY_BUCKETS as $i => [, $max]) {
                if ($max === null || $days <= $max) {
                    $buckets[$i]['value']++;
                    break;
                }
            }
        }

        return $buckets;
    }

    /**
     * Open projects due from today through DUE_SOON_DAYS (past due ones are the "atrasados" figures).
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function dueSoon($open, CarbonImmutable $today): array
    {
        $query = $open->whereBetween('due_date', [$today->toDateString(), $today->addDays(self::DUE_SOON_DAYS)->toDateString()]);

        return [
            'total' => (clone $query)->count(),
            'items' => $query->with('customer:id,name')->orderBy('due_date')->limit(self::LIST_SIZE)->get()
                ->map(fn (Project $p) => [
                    'id' => $p->id, 'name' => $p->name, 'customer' => $p->customer?->name,
                    'due_date' => $p->due_date->toDateString(), 'days' => (int) $today->diffInDays($p->due_date->startOfDay()),
                ])->all(),
        ];
    }

    /**
     * Leads / warming-up customers nobody touched for STALE_DAYS (customer.updated_at).
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function staleLeads(CarbonImmutable $today): array
    {
        $query = Customer::query()->where('active', true)
            ->whereIn('status', [CustomerStatus::Lead, CustomerStatus::WarmingUp])
            ->where('updated_at', '<', $today->subDays(self::STALE_DAYS));

        return [
            'total' => (clone $query)->count(),
            'items' => $query->orderBy('updated_at')->limit(self::LIST_SIZE)->get()
                ->map(fn (Customer $c) => [
                    'id' => $c->id, 'name' => $c->name, 'status' => $c->status_label,
                    'days' => (int) $c->updated_at->startOfDay()->diffInDays($today),
                ])->all(),
        ];
    }

    /**
     * Active customers (status "Cliente") with no project at all; leads aren't expected to have one.
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function withoutProject(): array
    {
        $query = Customer::query()->where('active', true)->where('status', CustomerStatus::Customer)->whereDoesntHave('projects');

        return [
            'total' => (clone $query)->count(),
            'items' => $query->orderBy('name')->limit(self::LIST_SIZE)->get()
                ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name])->all(),
        ];
    }
}
