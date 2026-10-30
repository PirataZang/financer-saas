<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/** Document template: editor HTML with {{Cliente.name}}-style variables, filled in when it is issued as PDF. */
#[Fillable(['name', 'subject', 'description', 'active', 'content'])]
class Document extends Model
{
    use Auditable, BelongsToCompany;

    /** New templates can be issued right away (same default as the migration). */
    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
