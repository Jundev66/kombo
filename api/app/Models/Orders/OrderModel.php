<?php

declare(strict_types=1);

namespace App\Models\Orders;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Modules\Orders\Domain\ValueObjects\ServiceType;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Un pedido, del lado de la base.
 *
 * El dominio (`Modules\Orders\Domain\Entities\Order`) no sabe que esto existe.
 */
#[Fillable([
    'number', 'public_token', 'status', 'service_type', 'channel',
    'customer_name', 'customer_phone', 'delivery_address',
    'subtotal_cents', 'delivery_fee_cents', 'total_cents', 'currency', 'exchange_rate',
    'paid_cents', 'payment_status', 'notes', 'cancellation_reason',
    'placed_at', 'confirmed_at', 'preparing_at', 'ready_at',
    'out_for_delivery_at', 'delivered_at', 'cancelled_at', 'created_by',
])]
class OrderModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'orders';

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'service_type' => ServiceType::class,
            'number' => 'integer',
            'subtotal_cents' => 'integer',
            'delivery_fee_cents' => 'integer',
            'total_cents' => 'integer',
            'paid_cents' => 'integer',
            'state_version' => 'integer',
            'placed_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'preparing_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'out_for_delivery_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<OrderItemModel, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItemModel::class, 'order_id')->orderBy('sort_order');
    }

    /** @return HasMany<OrderPaymentModel, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPaymentModel::class, 'order_id');
    }

    /**
     * Lo que falta por cobrar.
     *
     * Se calcula, no se guarda: un campo «pendiente» y otro «pagado» son dos
     * sitios donde la verdad puede discrepar, y discrepan.
     */
    public function outstandingCents(): int
    {
        return max(0, (int) $this->total_cents - (int) $this->paid_cents);
    }
}
