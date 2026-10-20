<?php

namespace App\Models;

use App\Enums\ProjectStepStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'status', 'due_date', 'sort_order'])]
class ProjectStep extends Model
{
    use BelongsToCompany;

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => ProjectStepStatus::class,
            'due_date' => 'date:Y-m-d',
        ];
    }
}
