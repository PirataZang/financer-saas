<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'name', 'email', 'phone', 'status', 'active',
    'zip_code', 'street', 'number', 'complement', 'neighborhood', 'reference', 'city', 'state', 'country',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, Auditable, SoftDeletes;

    protected $appends = ['status_label'];

    /** New customers enter the funnel as active leads (same defaults as the migration). */
    protected $attributes = [
        'status' => 'lead',
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'active' => 'boolean',
        ];
    }

    /**
     * Domain guarantee of "email OR phone" for every write path (API, public form, tinker),
     * on top of request validation and the Postgres CHECK constraint.
     */
    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            if (!$customer->email && !$customer->phone) {
                throw ValidationException::withMessages([
                    'email' => ['Informe um e-mail ou um telefone.'],
                ]);
            }
        });
    }

    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value) => self::blankToNull($value ? mb_strtolower(trim($value)) : null));
    }

    /** Stored as digits only; the frontend phone mask formats it for display. */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => self::blankToNull(preg_replace('/\D/', '', (string) $value)));
    }

    /** Stored as 8 digits; the frontend cep mask formats it as 00000-000. */
    protected function zipCode(): Attribute
    {
        return Attribute::set(fn (?string $value) => self::blankToNull(preg_replace('/\D/', '', (string) $value)));
    }

    protected function state(): Attribute
    {
        return Attribute::set(fn (?string $value) => self::blankToNull($value ? mb_strtoupper(trim($value)) : null));
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn () => $this->status?->label());
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
