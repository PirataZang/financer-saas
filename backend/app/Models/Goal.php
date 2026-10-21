<?php

namespace App\Models;

use App\Enums\GoalType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A meta. Name, icon and color are its category's (one source); the category's transactions in
 * [start_date, end_date] are the goal's money. Created through GoalService, which creates the category.
 */
#[Fillable(['type', 'target_amount', 'start_date', 'end_date'])]
class Goal extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    protected $table = 'finance_goals';

    protected function casts(): array
    {
        return [
            'type' => GoalType::class,
            'target_amount' => 'decimal:2',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }
}
