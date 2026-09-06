<?php

declare(strict_types=1);

namespace Modules\Portal\Application\UseCases;

use App\Models\Orders\OrderModel;
use Modules\Orders\Application\UseCases\CancelOrder;
use Modules\Orders\Domain\ValueObjects\OrderStatus;

/**
 * Closes orders that ran out of time to pay.
 *
 * The customer went to the banking app and did not come back — that happens
 * daily and is nobody's fault. Leaving them there is the fault: a board half
 * full of orders that never arrived stops being looked at.
 *
 * Cancelled one by one through the normal use case, so each passes the state
 * machine, lands in the audit log, and tells whoever needs to know.
 */
final class CancelExpiredOrders
{
    public function __construct(private readonly CancelOrder $cancelOrder) {}

    /** @return int how many were closed */
    public function execute(): int
    {
        $expired = OrderModel::query()
            ->where('status', OrderStatus::PendingPayment->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            // A hundred at a time: closing a thousand over several passes beats a task
            // that takes a whole minute.
            ->limit(100)
            ->get();

        foreach ($expired as $order) {
            $this->cancelOrder->execute(
                (string) $order->id,
                'Se venció el plazo para enviar el comprobante del pago.',
            );
        }

        return $expired->count();
    }
}
