<?php

declare(strict_types=1);

namespace App\Models\Orders;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A payment against an order. There can be SEVERAL: people pay in a mix here —
 * some cash, the rest by mobile transfer.
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
