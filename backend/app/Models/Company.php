<?php

namespace App\Models;

use App\Attributes\ProtectedColumns;
use App\Models\Concerns\Auditable;
use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The tenant. Not company-scoped itself; only masters manage it (routes under `master`).
 * The current tenant lives in the container ("tenant") and is set per request by
 * ResolveCompany, per run by console commands, and by Company::use() in tests.
 */
#[Fillable([
    'name', 'trade_name', 'document', 'description',
    'zip_code', 'street', 'number', 'neighborhood', 'city', 'state', 'country', 'expires_at',
])]
#[ProtectedColumns(['token', 'telegram_code'])]
class Company extends Model
{
    use Auditable, SoftDeletes;

    public const DEFAULT_ID = 1;

    protected $appends = ['active'];

    protected function casts(): array
    {
        return ['expires_at' => 'date:Y-m-d', 'settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleting(function (Company $company) {
            if ($company->isDefault()) {
                throw new AccessDeniedHttpException('A empresa padrão não pode ser excluída.');
            }
        });
    }

    /** Id of the company every query is scoped to, or null (scoped queries then return nothing). */
    public static function current(): ?int
    {
        return app()->bound('tenant') ? app('tenant') : null;
    }

    public static function use(?int $id): void
    {
        $id === null ? app()->forgetInstance('tenant') : app()->instance('tenant', $id);
    }

    /** Runs $callback scoped to company $id, then restores the previous one (a long-lived worker never leaks a tenant). */
    public static function as(int $id, Closure $callback): mixed
    {
        $previous = self::current();
        self::use($id);

        try {
            return $callback();
        } finally {
            self::use($previous);
        }
    }

    public function isDefault(): bool
    {
        return $this->id === self::DEFAULT_ID;
    }

    /** License check: a token, and today is on or before expires_at (none = no expiry). */
    public function isActive(): bool
    {
        return !$this->trashed() && $this->token && (!$this->expires_at || !$this->expires_at->endOfDay()->isPast());
    }

    protected function active(): Attribute
    {
        return Attribute::get(fn () => $this->isActive());
    }

    public function regenerateToken(): void
    {
        $this->forceFill(['token' => Str::random(40)])->save();
    }

    public function revokeToken(): void
    {
        $this->forceFill(['token' => null])->save();
    }

    protected function document(): Attribute
    {
        return Attribute::set(fn (?string $value) => preg_replace('/\D/', '', (string) $value) ?: null);
    }

    protected function zipCode(): Attribute
    {
        return Attribute::set(fn (?string $value) => preg_replace('/\D/', '', (string) $value) ?: null);
    }

    protected function state(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value ? mb_strtoupper(trim($value)) : null);
    }
}
