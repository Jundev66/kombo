<?php

declare(strict_types=1);

namespace Modules\Orders\Application\UseCases;

use App\Models\Orders\OrderModel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Catalog\Application\Contracts\ProductCatalog;
use Modules\Orders\Application\Exceptions\OrderMovedByOther;
use Modules\Orders\Application\Exceptions\OrderNotFound;
use Modules\Orders\Domain\Events\ConfirmedOrderLine;
use Modules\Orders\Domain\Events\OrderAdvanced;
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
        private readonly ProductCatalog $products,
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
            $this->events->dispatch($this->confirmedEvent($model, $byName));
        }

        /*
         * Y el aviso delgado, para todo lo demás: el mensaje al cliente, y
         * mañana los reportes de cuánto se tarda entre paso y paso.
         *
         * Delgado a propósito: cargar las líneas de un pedido cada vez que
         * alguien toca «Entregado» sería trabajo para que no lo lea nadie.
         */
        $this->events->dispatch(new OrderAdvanced(
            tenantId: $this->context->id(),
            orderId: $orderId,
            number: (int) $model->number,
            status: $next->value,
            previousStatus: $current->value,
        ));

        return $model->refresh();
    }

    /**
     * Arma el evento con todo lo que la cocina necesita.
     *
     * El tiempo de preparación sale del CATÁLOGO, no del pedido: no se guarda
     * en la línea porque no es un dato del pedido sino de lo que se vende, y
     * porque si el dueño ajusta el tiempo de la parrilla, las comandas
     * siguientes deben usar el nuevo.
     *
     * Se toma el MÁXIMO de las líneas, no la suma: los platos se hacen a la
     * vez, no en fila. Sumar daría media hora para dos arepas y el semáforo no
     * marcaría nada como tarde nunca.
     */
    private function confirmedEvent(OrderModel $model, ?string $byName): OrderConfirmed
    {
        $items = $model->items()->with('modifiers')->get();

        $snapshots = $this->products->findMany(
            $items->pluck('product_id')->filter()->unique()->values()->all(),
        );

        $prepMinutes = collect($snapshots)
            ->map(fn ($snapshot): ?int => $snapshot->prepMinutes)
            ->filter()
            ->max();

        return new OrderConfirmed(
            tenantId: $this->context->id(),
            orderId: (string) $model->id,
            number: (int) $model->number,
            serviceType: $model->service_type->value,
            lines: $items->map(fn ($item): ConfirmedOrderLine => new ConfirmedOrderLine(
                productId: $item->product_id,
                name: (string) $item->product_name,
                quantity: (int) $item->quantity,
                // Ya en texto: la cocina lee «Sin cebolla», no un id.
                modifiers: $item->modifiers->pluck('name')->all(),
                notes: $item->notes,
            ))->all(),
            prepMinutes: $prepMinutes === null ? null : (int) $prepMinutes,
            notes: $model->notes,
            confirmedByName: $byName,
        );
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
