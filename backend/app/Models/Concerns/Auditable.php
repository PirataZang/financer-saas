<?php

namespace App\Models\Concerns;

use App\Models\Company;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Records every create/update/delete on the model into the activity log,
 * scoped to its fillable attributes and only the fields that actually changed.
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            // Secrets never reach the history screen.
            ->logExcept(['password', ...static::protectedColumns()])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(class_basename($this));
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->properties = $activity->properties->put('ip', request()->ip());
        // activity_log.company_id is NOT NULL: the record's company (a Company is its own tenant).
        $activity->company_id = $this instanceof Company ? $this->id : $this->company_id;
    }
}
