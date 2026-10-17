<?php

namespace App\Models;

use App\Attributes\ProtectedColumns;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Base of every model in the app (ProtectedColumnsTest enforces it). Applies
 * #[ProtectedColumns] so sensitive data can't leave or enter through the API:
 * hidden from every response, ignored by fill()/create()/update(), kept out of
 * the audit log, and — for locked columns — unchangeable during a request.
 */
abstract class Model extends Eloquent
{
    /** @return list<string> */
    public static function protectedColumns(): array
    {
        $attribute = static::resolveClassAttribute(ProtectedColumns::class);

        return $attribute ? array_values(array_unique([...$attribute->columns, ...$attribute->locked])) : [];
    }

    /** @return list<string> */
    public static function lockedColumns(): array
    {
        return static::resolveClassAttribute(ProtectedColumns::class)?->locked ?? [];
    }

    protected static function boot(): void
    {
        parent::boot();

        // Every write path (API, a bug, direct assignment) — except the console.
        static::saving(function (Model $model) {
            $changed = array_keys($model->getDirty());
            if (!app()->runningInConsole() && array_intersect($model->lockedColumns(), $changed)) {
                throw new AccessDeniedHttpException('Este dado só pode ser alterado diretamente no banco de dados.');
            }
        });
    }

    public function initializeHidesAttributes(): void
    {
        parent::initializeHidesAttributes();
        $this->mergeHidden(static::protectedColumns());
    }

    /** Request data can't set a protected column even if listed as fillable; forceFill()/unguarded code can. */
    public function isFillable($key): bool
    {
        return (static::$unguarded || !in_array($key, static::protectedColumns(), true)) && parent::isFillable($key);
    }
}
