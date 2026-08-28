<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Events;

/**
 * Una línea, tal como la necesita quien reacciona a un pedido confirmado.
 *
 * Va dentro del evento y no se consulta después, a propósito: así quien
 * escucha —la cocina hoy, un aviso al cliente mañana— **no tiene que tocar las
 * tablas de pedidos**. El evento lleva los hechos; el que reacciona no
 * investiga.
 */
final readonly class ConfirmedOrderLine
{
    /**
     * @param  list<string>  $modifiers  Ya resueltos en texto: «Sin cebolla».
     */
    public function __construct(
        public ?string $productId,
        public string $name,
        public int $quantity,
        public array $modifiers = [],
        public ?string $notes = null,
    ) {}
}
