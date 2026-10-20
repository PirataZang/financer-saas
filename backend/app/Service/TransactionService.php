<?php

namespace App\Service;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Company;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Finance core. Every entry point (web, Telegram, future CSV/Open Finance imports) creates
 * transactions through create(); the source is just a label plus an optional external id.
 */
class TransactionService
{
    public function __construct(protected FinanceDashboardService $dashboardService)
    {
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Transaction::query()
            ->with('category:id,name,icon,color')
            ->filtered($filters)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Totals of every row matching the filters (not just the current page), any status:
     * the list shows paid and pending together, so `pending` says how many still haven't moved money.
     */
    public function totals(array $filters = []): array
    {
        $totals = $this->dashboardService->totalsByType(Transaction::query()->filtered($filters));

        return [
            ...$totals,
            'balance' => round($totals['income'] - $totals['expense'], 2),
            'pending' => Transaction::query()->filtered($filters)->where('status', TransactionStatus::Pending)->count(),
        ];
    }

    public function index(int $id): Transaction
    {
        return Transaction::with('category:id,name,icon,color')->findOrFail($id);
    }

    /**
     * With an $externalId the call is idempotent: the same source + id (a Telegram retry, a bank
     * statement imported twice) returns the original transaction instead of a duplicate, even
     * under concurrent requests (unique index + createOrFirst). Check wasRecentlyCreated.
     */
    public function create(array $data, TransactionSource $source = TransactionSource::Manual, ?string $externalId = null): Transaction
    {
        $attributes = [...$data, 'source' => $source];

        $transaction = $externalId === null
            ? Transaction::create($attributes)
            : Transaction::withTrashed()->createOrFirst(['source' => $source, 'external_id' => $externalId], $attributes);

        return $transaction->load('category:id,name,icon,color');
    }

    /**
     * A series created in one go, all or nothing, one month apart (31/01 → 28/02, never overflows):
     * - installments: the total split in $count parts (leftover cents go to the 1st), "Desc (1/N)";
     * - monthly: the same amount every month (rent, subscriptions).
     * Entries dated after today are pending (that money hasn't moved yet). Returns the first one.
     */
    public function createSeries(array $data, string $mode, int $count): Transaction
    {
        $cents = $this->toCents($data['amount']);
        if ($mode === 'installments' && $cents < $count) {
            throw ValidationException::withMessages(['repeat_count' => ['Valor pequeno demais para tantas parcelas.']]);
        }

        $start = CarbonImmutable::parse($data['date']);
        $today = now()->toDateString();

        return DB::transaction(function () use ($data, $mode, $count, $cents, $start, $today) {
            $first = null;
            for ($i = 0; $i < $count; $i++) {
                $date = $start->addMonthsNoOverflow($i)->toDateString();
                $amount = $mode === 'installments' ? intdiv($cents, $count) + ($i === 0 ? $cents % $count : 0) : $cents;
                $suffix = $mode === 'installments' ? ' ('.($i + 1)."/{$count})" : '';

                $transaction = $this->create([
                    ...$data,
                    'amount' => sprintf('%d.%02d', intdiv($amount, 100), $amount % 100),
                    'description' => mb_substr($data['description'], 0, 255 - mb_strlen($suffix)).$suffix,
                    'date' => $date,
                    'status' => $date > $today ? TransactionStatus::Pending : ($data['status'] ?? TransactionStatus::Paid),
                ]);
                $first ??= $transaction;
            }

            return $first;
        });
    }

    /**
     * Bulk status change (e.g. "Efetivar" the selected pending entries). Model by model so each
     * change lands in the activity log. Returns how many actually changed.
     */
    public function setStatus(array $ids, TransactionStatus $status): int
    {
        return DB::transaction(fn () => Transaction::whereIn('id', $ids)
            ->where('status', '!=', $status)
            ->get()
            ->each(fn (Transaction $transaction) => $transaction->update(['status' => $status]))
            ->count());
    }

    public function update(int $id, array $data): Transaction
    {
        $transaction = Transaction::findOrFail($id);
        $transaction->update($data);

        return $transaction->load('category:id,name,icon,color');
    }

    public function delete(int $id): bool
    {
        return Transaction::findOrFail($id)->delete();
    }

    /**
     * Shared by the web API and the bot API. Amount is validated as a decimal string/number with
     * at most 2 places (never parsed as float); category existence/activeness/type match is the
     * Transaction model's saving guard, so imports get it too.
     */
    public function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'type' => [$required, Rule::enum(TransactionType::class)],
            'amount' => [$required, 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'description' => [$required, 'string', 'max:255'],
            'category_id' => [$required, 'integer'],
            // Income linked to a customer = a bill in their portal.
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', Company::current())->whereNull('deleted_at')],
            'date' => [$required, 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::enum(TransactionStatus::class)],
        ];
    }

    /** Web-only extra for create(): the bot always records a single entry. */
    public function repeatRules(): array
    {
        return [
            'repeat' => ['nullable', Rule::in(['installments', 'monthly'])],
            'repeat_count' => ['required_with:repeat', 'nullable', 'integer', 'min:2', 'max:120'],
        ];
    }

    public function statusRules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'status' => ['required', Rule::enum(TransactionStatus::class)],
        ];
    }

    /** "150.5" → 15050, by string: amounts are never parsed as float. */
    private function toCents(string|int|float $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public function filterRules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'category_id' => ['nullable', 'integer'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
            'source' => ['nullable', Rule::enum(TransactionSource::class)],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
