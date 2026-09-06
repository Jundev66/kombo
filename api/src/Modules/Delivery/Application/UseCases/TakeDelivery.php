<?php

declare(strict_types=1);

namespace Modules\Delivery\Application\UseCases;

use App\Models\Orders\OrderModel;
use Illuminate\Database\DatabaseManager;
use Modules\Orders\Application\Exceptions\OrderNotFound;
use Platform\Audit\AuditLogger;
use Platform\Tenancy\TenantContext;
use Shared\Domain\Exceptions\UserError;

/**
 * A courier takes an order. First to take it gets it, which is why the `UPDATE`
 * carries `courier_id is null`: two couriers tapping at once really happens.
 *
 * Recorded in their name — copied — because that is what they get paid on.
 */
final class TakeDelivery
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(string $orderId, string $courierId, string $courierName): OrderModel
    {
        $order = OrderModel::find($orderId) ?? throw new OrderNotFound;

        if ($order->service_type->value !== 'delivery') {
            throw new class('Ese pedido no es para llevar a domicilio.') extends UserError {};
        }

        $affected = $this->db->table('orders')
            ->where('id', $orderId)
            ->where('tenant_id', $this->context->id())
            // Only if it is free. A real race, not a theoretical one.
            ->whereNull('courier_id')
            ->update([
                'courier_id' => $courierId,
                'courier_name' => $courierName,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            throw new class('Ese pedido ya se lo llevó otra persona.') extends UserError {};
        }

        $this->audit->record(
            action: 'delivery.taken',
            entityType: 'order',
            entityId: $orderId,
            after: ['courier' => $courierName],
        );

        return $order->refresh();
    }

    /** Dropping it: they picked wrong, or cannot go out. It is free again. */
    public function release(string $orderId, string $courierId): OrderModel
    {
        $order = OrderModel::find($orderId) ?? throw new OrderNotFound;

        if ((string) $order->courier_id !== $courierId) {
            throw new class('Ese pedido no lo llevas tú.') extends UserError {};
        }

        $order->update(['courier_id' => null, 'courier_name' => null]);

        return $order->refresh();
    }
}
