<?php

namespace App\Models;

use App\Attributes\ProtectedColumns;
use App\Enums\IntegrationProvider;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * A company's connection to an external provider (Configurações › Integrações). `credentials` is
 * encrypted at rest (APP_KEY) and never leaves the backend; `account` is what the card shows.
 */
#[Fillable(['active', 'settings'])]
#[ProtectedColumns(['credentials'])]
class Integration extends Model
{
    use Auditable, BelongsToCompany;

    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'active' => 'boolean',
            'credentials' => 'encrypted:array',
            'account' => 'array',
            'settings' => 'array',
            'sync_cursor' => 'datetime',
            'payouts_cursor' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public static function for(IntegrationProvider $provider): ?self
    {
        return static::where('provider', $provider)->first();
    }
}
