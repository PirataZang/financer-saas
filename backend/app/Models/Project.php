<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ProjectStepStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer's project, followed by them in the portal. Progress comes from the steps
 * (done / total); without steps, only a finished project counts as 100%.
 */
#[Fillable(['customer_id', 'name', 'description', 'status', 'start_date', 'due_date'])]
class Project extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    protected $appends = ['status_label', 'progress'];

    protected $attributes = ['status' => 'planning'];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'start_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
        ];
    }

    /** Soft-deleted customers still name their old projects. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ProjectStep::class)->orderBy('sort_order')->orderBy('id');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn () => $this->status?->label());
    }

    /** Callers eager-load `steps` (every service query does). */
    protected function progress(): Attribute
    {
        return Attribute::get(function () {
            $total = $this->steps->count();
            if ($total === 0) {
                return $this->status === ProjectStatus::Done ? 100 : 0;
            }

            return (int) round($this->steps->where('status', ProjectStepStatus::Done)->count() * 100 / $total);
        });
    }
}
