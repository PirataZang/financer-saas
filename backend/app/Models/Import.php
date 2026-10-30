<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One spreadsheet import. Progress is derived from its rows (see scopeWithProgress), never stored. */
#[Fillable(['user_id', 'type', 'filename', 'total'])]
class Import extends Model
{
    use BelongsToCompany;

    protected $appends = ['status'];

    /** Row counts by outcome: pending_count, created_count, updated_count, failed_count. */
    public function scopeWithProgress(Builder $query): void
    {
        $query->with('user:id,name')->withCount([
            'rows as pending_count' => fn ($q) => $q->where('status', 'pending'),
            'rows as created_count' => fn ($q) => $q->where('status', 'created'),
            'rows as updated_count' => fn ($q) => $q->where('status', 'updated'),
            'rows as failed_count' => fn ($q) => $q->where('status', 'failed'),
        ]);
    }

    /** processing while any row waits for the queue; needs scopeWithProgress. */
    protected function status(): Attribute
    {
        return Attribute::get(fn () => ($this->pending_count ?? 0) > 0 ? 'processing' : 'completed');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
