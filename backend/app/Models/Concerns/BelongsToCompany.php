<?php

namespace App\Models\Concerns;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Tenant isolation for a model whose table has `company_id` (required by TenancyTest).
 *
 * - Every query is filtered by the current company. Fail-closed: with no current
 *   company the filter matches nothing — never "all companies".
 * - company_id is never mass-assignable: on create it is the current company
 *   (explicit values only count when there is no current company: seeders, tests);
 *   it can't change afterwards.
 * - Records of another company are indistinguishable from missing ones (404).
 *
 * Opt out only where the tenant isn't known yet (login/token lookup):
 * `Model::withoutGlobalScope('company')`.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', fn (Builder $query) => $query->where(
            $query->qualifyColumn('company_id'),
            Company::current() ?? 0,
        ));

        static::creating(function (Model $model) {
            $model->company_id = Company::current() ?? $model->company_id
                ?? throw new LogicException(class_basename($model).' sem empresa: defina a empresa atual (Company::use).');
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('company_id')) {
                throw new LogicException('Um registro não pode mudar de empresa.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
