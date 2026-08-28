<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Events;

/**
 * Un pedido se canceló.
 *
 * Se emite para que quien esté haciendo algo con ese pedido **deje de
 * hacerlo**: la cocina, sobre todo. Un pedido anulado en la caja mientras la
 * arepa está en la plancha tiene que salir del tablero, y sin este aviso el
 * cocinero termina un plato que nadie va a recoger.
 *
 * Como `OrderConfirmed`, viaja con los hechos que hacen falta: quien lo
 * escucha no consulta las tablas de pedidos.
 */
final readonly class OrderCancelled
{
    public function __construct(
        public string $tenantId,
        public string $orderId,
        public int $number,
        public string $reason,
    ) {}
}
