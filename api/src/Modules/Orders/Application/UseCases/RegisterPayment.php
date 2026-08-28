<?php

declare(strict_types=1);

namespace Modules\Orders\Application\UseCases;

use App\Models\Orders\OrderModel;
use App\Models\Orders\OrderPaymentModel;
use Illuminate\Database\DatabaseManager;
use Modules\Orders\Application\Exceptions\OrderNotFound;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Platform\Audit\AuditLogger;

/**
 * Registrar un pago de un pedido.
 *
 * Puede haber VARIOS por pedido, y ése es todo el punto: aquí se cobra
 * mezclado —tres dólares en efectivo y el resto en bolívares por pago móvil—.
 * Con una sola columna `payment_method` eso no se representa, y el cajero
 * acaba anotando la mitad en el campo de observaciones.
 *
 * El pago móvil se **confirma a mano**: alguien mira el comprobante y dice que
 * sí. No hay API bancaria fiable que preguntar, y fingir que la hay sería
 * peor que asumirlo.
 */
final class RegisterPayment
{
    /** Los que se dan por buenos en el acto: el dinero está en la mano. */
    private const IMMEDIATE = ['cash_usd', 'cash_bs', 'card'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  bool  $verifiedInPerson  Lo da por bueno quien está cobrando.
     *                                  En el mostrador el cajero mira la
     *                                  notificación en su teléfono ANTES de
     *                                  entregar la comida: ese acto es la
     *                                  confirmación, y dejar el pago esperando
     *                                  revisión imprimiría una nota que dice
     *                                  que el cliente todavía debe. En el
     *                                  portal es al revés —el comprobante lo
     *                                  sube el cliente— y por eso el valor por
     *                                  defecto es que no.
     */
    public function execute(
        string $orderId,
        string $method,
        int $amountCents,
        ?string $reference = null,
        ?string $receiptUrl = null,
        bool $verifiedInPerson = false,
    ): OrderModel {
        $order = OrderModel::find($orderId) ?? throw new OrderNotFound;

        return $this->db->transaction(function () use ($order, $method, $amountCents, $reference, $receiptUrl, $verifiedInPerson): OrderModel {
            $confirmedNow = $verifiedInPerson || in_array($method, self::IMMEDIATE, true);

            $order->payments()->create([
                'method' => $method,
                'amount_cents' => $amountCents,
                'currency' => $order->currency,
                // La tasa de ESTE pago. Si el cliente paga en dos veces y la
                // tasa cambió entre medias, cada pago vale lo que valía.
                'exchange_rate' => $order->exchange_rate,
                'reference' => $reference,
                'receipt_url' => $receiptUrl,
                'status' => $confirmedNow ? 'confirmed' : 'pending_review',
                'confirmed_by' => $confirmedNow ? auth()->id() : null,
                'confirmed_at' => $confirmedNow ? now() : null,
                'created_by' => auth()->id(),
            ]);

            $this->recalculate($order);

            $this->audit->record(
                action: 'orders.payment_registered',
                entityType: 'order',
                entityId: (string) $order->id,
                after: ['method' => $method, 'amount_cents' => $amountCents],
            );

            return $order->refresh();
        });
    }

    /** Dar por bueno un pago que estaba esperando revisión. */
    public function confirm(string $paymentId): OrderModel
    {
        $payment = OrderPaymentModel::find($paymentId) ?? throw new OrderNotFound;

        $payment->update([
            'status' => 'confirmed',
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
        ]);

        $order = OrderModel::findOrFail($payment->order_id);

        $this->recalculate($order);

        $this->audit->record(
            action: 'orders.payment_confirmed',
            entityType: 'order',
            entityId: (string) $order->id,
            after: ['payment_id' => $paymentId, 'amount_cents' => $payment->amount_cents],
        );

        return $order->refresh();
    }

    /**
     * Recalcula lo pagado a partir de los pagos CONFIRMADOS.
     *
     * Se recalcula en vez de ir sumando: dos campos que deberían coincidir
     * —lo pagado y la suma de los pagos— acaban discrepando, y el que se mira
     * es siempre el equivocado.
     *
     * Y si el pedido estaba esperando el pago, ahora ya llegó al negocio.
     */
    private function recalculate(OrderModel $order): void
    {
        $paid = (int) $order->payments()->where('status', 'confirmed')->sum('amount_cents');

        $order->paid_cents = $paid;
        $order->payment_status = match (true) {
            $paid <= 0 => 'unpaid',
            $paid < (int) $order->total_cents => 'partial',
            default => 'paid',
        };

        if ($order->status === OrderStatus::PendingPayment && $order->payment_status === 'paid') {
            $order->status = OrderStatus::Placed;
            $order->state_version = (int) $order->state_version + 1;
        }

        $order->save();
    }
}
