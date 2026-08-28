<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\UsesUuidV7;

/** Un pago de suscripción, anotado a mano por quien lo vio entrar. */
#[Fillable([
    'tenant_id', 'subscription_id', 'amount_cents', 'currency', 'method',
    'reference', 'paid_at', 'period_from', 'period_to', 'receipt_url',
    'registered_by', 'notes',
])]
class SubscriptionPaymentModel extends Model
{
    use UsesUuidV7;

    protected $table = 'subscription_payments';

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'paid_at' => 'immutable_datetime',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
        ];
    }
}
