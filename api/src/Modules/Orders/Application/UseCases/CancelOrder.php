<?php

declare(strict_types=1);

namespace Modules\Orders\Application\UseCases;

use App\Models\Orders\OrderModel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Orders\Application\Exceptions\OrderMovedByOther;
use Modules\Orders\Application\Exceptions\OrderNotFound;
use Modules\Orders\Domain\Events\OrderCancelled;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Platform\Audit\AuditLogger;
use Platform\Audit\AuthorizedBy;
use Platform\Tenancy\TenantContext;

/**
 * Cancelar un pedido.
 *
 * Siempre con MOTIVO, y siempre a la bitácora. Cancelar es la vía natural para
 * sacar comida sin cobrarla, así que la pregunta que esto viene a responder no
 * es «¿se puede?» sino «¿quién lo hizo, cuándo y por qué?».
 */
final class CancelOrder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Dispatcher $events,
        private readonly TenantContext $context,
    ) {}

    public function execute(string $orderId, string $reason, ?AuthorizedBy $authorizedBy = null): OrderModel
    {
        $model = OrderModel::find($orderId) ?? throw new OrderNotFound;

        $current = $model->status;

        // El dominio comprueba que no esté ya cerrado. Cancelar se salta la
        // tabla de transiciones —el cliente se arrepiente cuando quiere— pero
        // un pedido entregado no revive.
        $order = OrderReader::toDomain($model);
        $order->cancel($reason);

        $version = (int) $model->state_version;

        $affected = $this->db->table('orders')
            ->where('id', $orderId)
            ->where('tenant_id', $this->context->id())
            ->where('state_version', $version)
            ->update([
                'status' => OrderStatus::Cancelled->value,
                'cancellation_reason' => $reason,
                'state_version' => $version + 1,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            throw new OrderMovedByOther;
        }

        $this->audit->record(
            action: 'orders.cancelled',
            entityType: 'order',
            entityId: $orderId,
            before: ['status' => $current->value],
            after: ['status' => OrderStatus::Cancelled->value],
            reason: $reason,
            // Si lo autorizó un supervisor con su PIN, queda a su nombre. Es
            // toda la razón de que el mostrador sólo pueda SOLICITARLO.
            authorizedBy: $authorizedBy,
        );

        /*
         * Que alguien deje de cocinar esto.
         *
         * Por evento, como la confirmación y por la misma razón: `Orders` no
         * conoce a `Kitchen`. Si el módulo de cocina no existe, no lo escucha
         * nadie y no pasa nada.
         */
        $this->events->dispatch(new OrderCancelled(
            tenantId: $this->context->id(),
            orderId: $orderId,
            number: (int) $model->number,
            reason: $reason,
        ));

        return $model->refresh();
    }
}
