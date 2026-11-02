<?php

namespace App\Models;

use App\Attributes\ProtectedColumns;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A spreadsheet line already normalized/validated; `status` is its outcome. */
#[Fillable(['status', 'error'])]
// secret: campos sensíveis da linha (senha), nunca saem pela API.
#[ProtectedColumns(['secret'])]
class ImportRow extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['data' => 'array', 'secret' => 'encrypted:array'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
