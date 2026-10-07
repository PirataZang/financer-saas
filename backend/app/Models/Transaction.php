<?php

namespace App\Models;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
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
    use Auditable, SoftDeletes;

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
