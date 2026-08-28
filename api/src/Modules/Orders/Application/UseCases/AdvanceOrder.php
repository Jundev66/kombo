<?php

declare(strict_types=1);

namespace Modules\Orders\Application\UseCases;

use App\Models\Orders\OrderModel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Orders\Application\Exceptions\OrderMovedByOther;
use Modules\Orders\Application\Exceptions\OrderNotFound;
use Modules\Orders\Domain\Events\OrderConfirmed;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Platform\Audit\AuditLogger;
use Platform\Tenancy\TenantContext;

/**
 * Mover un pedido al siguiente estado.
 *
 * Con **bloqueo optimista de verdad**: el `UPDATE` lleva
 * `where state_version = ?`. Si no afecta ninguna fila, es que alguien se
 * adelantó y se responde 409 pidiendo recargar.
 *
 * No es una precaución teórica: la caja y la pantalla de cocina miran el mismo
 * pedido y dos personas pulsan casi a la vez todos los días. Sin esto, quien
 * guarda segundo pisa lo que hizo el primero y **nadie se entera**.
 */
final class AdvanceOrder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Dispatcher $events,
        private readonly TenantContext $context,
    ) {}

    public function execute(string $orderId, OrderStatus $next, ?string $byName = null): OrderModel
    {
        $model = OrderModel::find($orderId) ?? throw new OrderNotFound;

        $current = $model->status;

        // Repetir el paso en el que ya está no es error, y no gasta una
        // versión: dos toques en «Confirmar» no pueden hacer saltar un mensaje
        // rojo en mitad del servicio.
        if ($current === $next) {
            return $model;
        }

        // La entidad de dominio decide si la transición vale. Lanza 409 con un
        // mensaje que dice qué pasó, no «transición inválida».
        $order = OrderReader::toDomain($model);
        $order->moveTo($next);

        $stamp = self::stampColumn($next);
        $version = (int) $model->state_version;

        $affected = $this->db->table('orders')
            ->where('id', $orderId)
            ->where('tenant_id', $this->context->id())
            ->where('state_version', $version)
            ->update([
                'status' => $next->value,
                'state_version' => $version + 1,
                $stamp => now(),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            throw new OrderMovedByOther;
        }

        $this->audit->record(
            action: 'orders.advanced',
            entityType: 'order',
            entityId: $orderId,
            before: ['status' => $current->value],
            after: ['status' => $next->value],
        );

        /*
         * Confirmar es lo que manda el pedido a la COCINA.
         *
         * Va por evento y no por llamada directa porque `Orders` no puede
         * conocer a `Kitchen` —hay una prueba de arquitectura que lo impide— y
         * porque así el mismo momento puede disparar además el aviso al
         * cliente sin que nadie toque este caso de uso.
         */
        if ($next === OrderStatus::Confirmed) {
            $this->events->dispatch(new OrderConfirmed(
                tenantId: $this->context->id(),
                orderId: $orderId,
                number: (int) $model->number,
                confirmedByName: $byName,
            ));
        }

        return $model->refresh();
    }

    private static function stampColumn(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Confirmed => 'confirmed_at',
            OrderStatus::Preparing => 'preparing_at',
            OrderStatus::Ready => 'ready_at',
            OrderStatus::OutForDelivery => 'out_for_delivery_at',
            OrderStatus::Delivered => 'delivered_at',
            OrderStatus::Cancelled => 'cancelled_at',
            OrderStatus::Placed, OrderStatus::PendingPayment => 'placed_at',
        };
    }
}
