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
 * Un repartidor toma un pedido.
 *
 * **El primero que lo toma se lo lleva**, y por eso el `UPDATE` lleva
 * `courier_id is null` en el `WHERE`: dos repartidores tocando «lo llevo yo»
 * al mismo tiempo pasa de verdad en la puerta de una cocina, y sin esa
 * condición los dos saldrían con el mismo pedido.
 *
 * Queda a su nombre —copiado— porque es con eso con lo que se le paga.
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

        $afectadas = $this->db->table('orders')
            ->where('id', $orderId)
            ->where('tenant_id', $this->context->id())
            // Sólo si está libre. Es la carrera de verdad, no una teórica.
            ->whereNull('courier_id')
            ->update([
                'courier_id' => $courierId,
                'courier_name' => $courierName,
                'updated_at' => now(),
            ]);

        if ($afectadas === 0) {
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

    /** Soltarlo: se equivocó, o no puede salir. Vuelve a estar libre. */
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
