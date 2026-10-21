<?php

namespace App\Models;

use App\Enums\CategoryType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'icon', 'color', 'type', 'active', 'sort_order'])]
class Category extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    protected $table = 'finance_categories';

    /** Same defaults as the migration. */
    protected $attributes = [
        'color' => '#64748b',
        'type' => 'both',
        'active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => CategoryType::class,
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Set when the category was created by a meta (Metas screen owns it). */
    public function goal(): HasOne
    {
        return $this->hasOne(Goal::class);
    }
}
