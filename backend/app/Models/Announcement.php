<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aviso do portal: without project_id every customer of the company sees it; with one, only that project's customer. */
#[Fillable(['project_id', 'title', 'body'])]
class Announcement extends Model
{
    use BelongsToCompany;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
