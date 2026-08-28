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
 * mismo momento puede disparar además el aviso al cliente o el descuento de
 * insumos, sin que nadie toque el módulo de pedidos.
 *
 * Lleva **todo lo que hace falta para reaccionar**. Quien escucha no vuelve a
 * consultar: si tuviera que hacerlo, acabaría importando las tablas de
 * pedidos y el acoplamiento que el evento venía a evitar volvería por la
 * puerta de atrás.
 */
final readonly class OrderConfirmed
{
    /**
     * @param  list<ConfirmedOrderLine>  $lines
     */
    public function __construct(
        public string $tenantId,
        public string $orderId,
        public int $number,
        public string $serviceType,
        public array $lines,
        public ?int $prepMinutes = null,
        public ?string $notes = null,
        public ?string $confirmedByName = null,
    ) {}
}
