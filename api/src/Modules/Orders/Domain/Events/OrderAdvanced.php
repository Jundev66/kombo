<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Events;

/**
 * Un pedido cambió de estado.
 *
 * Es el aviso **delgado**: sólo dice qué pedido y a dónde fue. Convive con
 * `OrderConfirmed`, que es el **gordo** —lleva las líneas, los agregados y los
 * minutos de preparación— porque las dos cosas no son la misma:
 *
 *   `OrderConfirmed`  «nació una comanda». La cocina necesita saber QUÉ hacer.
 *   `OrderAdvanced`   «esto se movió». A quien avisa al cliente le basta con
 *                     el estado; los detalles ya los tiene el cliente.
 *
 * Meter todo en un solo evento obligaría a cargar las líneas de un pedido cada
 * vez que alguien toca «Entregado», para que las lea nadie.
 */
final readonly class OrderAdvanced
{
    public function __construct(
        public string $tenantId,
        public string $orderId,
        public int $number,
        public string $status,
        public string $previousStatus,
    ) {}
}
