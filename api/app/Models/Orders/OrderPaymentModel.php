<?php

declare(strict_types=1);

namespace App\Models\Orders;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Un pago de un pedido. Puede haber VARIOS: aquí se cobra mezclado —tres
 * dólares en efectivo y el resto en bolívares por pago móvil—.
 */
#[Fillable(['order_id', 'method', 'amount_cents', 'currency', 'exchange_rate',
    'reference', 'receipt_url', 'status', 'confirmed_by', 'confirmed_at', 'created_by'])]
class OrderPaymentModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'order_payments';

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
