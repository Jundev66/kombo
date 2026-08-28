<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Events;

/**
 * El negocio aceptó el pedido.
 *
 * **Éste es el disparador que manda un pedido a la cocina.** La pantalla de
 * comandas no consulta `orders` por su cuenta: escucha esto y crea su comanda.
 *
 * Es un evento y no una llamada directa porque `Orders` no puede conocer a
 * `Kitchen` —hay una prueba de arquitectura que lo impide— y porque así el
 * mismo momento puede disparar otras cosas (avisar al cliente por WhatsApp,
 * descontar insumos) sin que nadie toque este módulo.
 */
final readonly class OrderConfirmed
{
    public function __construct(
        public string $tenantId,
        public string $orderId,
        public int $number,
        public ?string $confirmedByName = null,
    ) {}
}
