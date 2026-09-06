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
 * Cancelling an order. Always with a reason, always into the audit log.
 *
 * Cancelling is the natural way to get food out without charging, so the
 * question to answer is not "can it be done?" but "who did it, when and why?".
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

        // The domain checks it is not already closed. Cancelling skips the
        // transition table — customers change their mind whenever they like — but a
        // delivered order does not come back to life.
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
            // If a supervisor authorised it with their PIN, it is recorded in their
            // name. That is the whole reason the counter can only REQUEST it.
            authorizedBy: $authorizedBy,
        );

        /*
         * Somebody should stop cooking this. By event, like confirmation: if
         * the kitchen module does not exist, nobody listens and nothing happens.
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
