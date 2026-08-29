<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Events;

/**
 * Entró un pedido.
 *
 * El tercer aviso de este módulo, y el más delgado de todos: sólo dice que
 * alguien pidió algo y quién fue. Lo escuchan los que llevan cuentas —los
 * clientes, mañana los reportes en vivo— y ninguno necesita las líneas.
 *
 *   `OrderPlaced`     «alguien pidió». Quién y por cuánto.
 *   `OrderConfirmed`  «nació una comanda». Qué hay que hacer, con todo.
 *   `OrderAdvanced`   «esto se movió». A dónde fue.
 */
final readonly class OrderPlaced
{
    public function __construct(
        public string $tenantId,
        public string $orderId,
        public int $number,
        public string $channel,
        public int $totalCents,
        public ?string $customerName = null,
        public ?string $customerPhone = null,
    ) {}
}
