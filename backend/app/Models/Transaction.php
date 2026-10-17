<?php

namespace App\Models;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * Finance core entry, independent of where it came from (`source`). `date` stays a plain
 * Y-m-d string on purpose: no timezone shifts, and it compares/groups the same in every DB.
 */
#[Fillable(['type', 'amount', 'description', 'category_id', 'date', 'status', 'source', 'external_id'])]
class Transaction extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    protected $table = 'finance_transactions';

    protected $attributes = [
        'status' => 'paid',
        'source' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'source' => TransactionSource::class,
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Single filter vocabulary for the list, the dashboard and the export (all keys optional):
     * from, to, type, category_id, category_ids[], source, status, min_amount, max_amount, search.
     */
    public function scopeFiltered(Builder $query, array $filters): void
    {
        $query
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('date', '<=', $to))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['category_ids'] ?? null, fn ($q, $ids) => $q->whereIn('category_id', $ids))
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['min_amount'] ?? null, fn ($q, $min) => $q->where('amount', '>=', $min))
            ->when($filters['max_amount'] ?? null, fn ($q, $max) => $q->where('amount', '<=', $max))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereLike('description', "%{$search}%"));
    }

    /** Soft-deleted categories still label their old transactions. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    /**
     * Category rule for every write path (web, bot, future imports): it must exist, be active and
     * accept the transaction type. Only checked when category/type change, so deactivating a
     * category doesn't lock its old transactions.
     */
    protected static function booted(): void
    {
        static::saving(function (Transaction $transaction) {
            if (!$transaction->isDirty(['category_id', 'type'])) {
                return;
            }

            $category = Category::find($transaction->category_id);

            if (!$category?->active) {
                throw ValidationException::withMessages(['category_id' => ['Categoria inexistente ou inativa.']]);
            }

            if (!$category->type->allows($transaction->type)) {
                throw ValidationException::withMessages([
                    'category_id' => ["A categoria {$category->name} não aceita {$transaction->type->label()}."],
                ]);
            }
        });
    }
}
