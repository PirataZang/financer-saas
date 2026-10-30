<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/** Local copy of a Mercado Pago payment (MercadoPagoService::sync), read by the dashboard charts. */
#[Fillable([
    'integration_id', 'mp_id', 'direction', 'status', 'status_detail', 'payment_type', 'payment_method',
    'description', 'amount', 'fee', 'net', 'refunded', 'installments', 'date', 'mp_updated_at',
])]
class MercadoPagoPayment extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'net' => 'decimal:2',
            'refunded' => 'decimal:2',
            'mp_updated_at' => 'datetime',
        ];
    }
}
