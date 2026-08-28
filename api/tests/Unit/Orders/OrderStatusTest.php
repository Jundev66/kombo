<?php

declare(strict_types=1);

use Modules\Orders\Domain\ValueObjects\OrderStatus;

/*
 * La tabla de transiciones, entera.
 *
 * Se prueba exhaustivamente porque es la regla que más sitios distintos
 * consultan —portal, caja, bot, cocina— y la que peor se nota cuando falla: un
 * pedido entregado que vuelve a la plancha, o uno que nunca llega a cocina.
 */

it('el camino normal de un pedido llega hasta entregado', function (): void {
    $camino = [
        OrderStatus::Placed,
        OrderStatus::Confirmed,
        OrderStatus::Preparing,
        OrderStatus::Ready,
        OrderStatus::Delivered,
    ];

    for ($i = 0; $i < count($camino) - 1; $i++) {
        expect($camino[$i]->canMoveTo($camino[$i + 1]))->toBeTrue();
    }
});

it('un pedido de delivery pasa por «en camino»', function (): void {
    expect(OrderStatus::Ready->canMoveTo(OrderStatus::OutForDelivery))->toBeTrue()
        ->and(OrderStatus::OutForDelivery->canMoveTo(OrderStatus::Delivered))->toBeTrue();
});

it('desde «listo» se puede entregar directamente, sin salir a la calle', function (): void {
    // Es el caso del mostrador: el cliente está ahí esperando.
    expect(OrderStatus::Ready->canMoveTo(OrderStatus::Delivered))->toBeTrue();
});

it('no se puede saltar la cocina', function (): void {
    // Confirmar y dar por listo de golpe dejaría la comanda sin pasar por la
    // plancha, y a alguien esperando comida que nadie hizo.
    expect(OrderStatus::Confirmed->canMoveTo(OrderStatus::Ready))->toBeFalse()
        ->and(OrderStatus::Placed->canMoveTo(OrderStatus::Delivered))->toBeFalse();
});

it('no se puede volver atrás', function (): void {
    // Un toque accidental que devuelva a «en la cocina» un pedido entregado
    // hace que se prepare dos veces. Corregir de verdad es cosa del encargado,
    // cancelando y volviendo a tomarlo.
    expect(OrderStatus::Ready->canMoveTo(OrderStatus::Preparing))->toBeFalse()
        ->and(OrderStatus::Confirmed->canMoveTo(OrderStatus::Placed))->toBeFalse();
});

it('entregado y cancelado son el final', function (): void {
    expect(OrderStatus::Delivered->isTerminal())->toBeTrue()
        ->and(OrderStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(OrderStatus::Delivered->allowedNext())->toBe([])
        ->and(OrderStatus::Cancelled->allowedNext())->toBe([]);
});

it('desde cualquier punto vivo se puede cancelar', function (): void {
    // En la vida real, un cliente se arrepiente en cualquier momento.
    foreach (OrderStatus::cases() as $estado) {
        if ($estado->isTerminal()) {
            continue;
        }

        expect($estado->canMoveTo(OrderStatus::Cancelled))->toBeTrue();
    }
});

it('sabe cuándo un pedido está en la cocina', function (): void {
    // Lo usan el tablero del panel y la pantalla de cocina, y tienen que
    // coincidir: si cada uno lo decidiera por su cuenta, un pedido aparecería
    // en una y no en la otra.
    expect(OrderStatus::Confirmed->isInKitchen())->toBeTrue()
        ->and(OrderStatus::Preparing->isInKitchen())->toBeTrue()
        ->and(OrderStatus::Ready->isInKitchen())->toBeFalse()
        ->and(OrderStatus::Placed->isInKitchen())->toBeFalse();
});

it('habla sin jerga', function (): void {
    // Lo que ve una persona no puede decir «pending_payment».
    expect(OrderStatus::PendingPayment->label())->toBe('Esperando el pago')
        ->and(OrderStatus::Preparing->label())->toBe('En la cocina');
});
