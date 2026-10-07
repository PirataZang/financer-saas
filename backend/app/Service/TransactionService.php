<?php

namespace App\Service;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Finance core. Every entry point (web, Telegram, future CSV/Open Finance imports) creates
 * transactions through create(); the source is just a label plus an optional external id.
 */
class TransactionService
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Transaction::query()
            ->with('category:id,name,icon,color')
            ->filtered($filters)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);
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
            'date' => [$required, 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::enum(TransactionStatus::class)],
        ];
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
