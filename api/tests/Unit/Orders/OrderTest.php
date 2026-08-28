<?php

declare(strict_types=1);

use Modules\Orders\Domain\Entities\Order;
use Modules\Orders\Domain\Exceptions\EmptyOrder;
use Modules\Orders\Domain\Exceptions\InvalidQuantity;
use Modules\Orders\Domain\Exceptions\InvalidTransition;
use Modules\Orders\Domain\ValueObjects\OrderLine;
use Modules\Orders\Domain\ValueObjects\OrderLineModifier;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Modules\Orders\Domain\ValueObjects\ServiceType;
use Shared\Domain\ValueObjects\Money;

function unaLinea(int $precio = 300, int $cantidad = 1, array $modificadores = []): OrderLine
{
    return new OrderLine(
        productId: 'p-1',
        productName: 'Reina Pepiada',
        unitPrice: Money::fromCents($precio),
        quantity: $cantidad,
        modifiers: $modificadores,
    );
}

function unPedido(array $lineas = [], ?Money $reparto = null, bool $esperandoPago = false): Order
{
    return Order::place(
        id: 'o-1',
        serviceType: ServiceType::Takeaway,
        lines: $lineas === [] ? [unaLinea()] : $lineas,
        deliveryFee: $reparto,
        awaitingPayment: $esperandoPago,
    );
}

it('un pedido sin nada que cobrar no es un pedido', function (): void {
    expect(fn () => unPedido([]))->not->toThrow(EmptyOrder::class);

    expect(fn () => Order::place('o-1', ServiceType::Takeaway, []))
        ->toThrow(EmptyOrder::class);
});

it('una línea tiene que llevar al menos uno', function (): void {
    expect(fn () => unaLinea(cantidad: 0))->toThrow(InvalidQuantity::class);
});

it('los agregados se cobran POR UNIDAD, no por pedido', function (): void {
    // Dos hamburguesas con extra queso llevan el extra dos veces. La fórmula
    // «precio × cantidad + agregados» cobraría un solo extra y regalaría el
    // otro — y nadie lo nota hasta que el margen del mes no cuadra.
    $linea = unaLinea(precio: 300, cantidad: 2, modificadores: [
        new OrderLineModifier('m-1', 'Extra queso', Money::fromCents(50)),
    ]);

    expect($linea->total()->cents)->toBe(700);
});

it('un agregado puede descontar', function (): void {
    $linea = unaLinea(precio: 300, modificadores: [
        new OrderLineModifier('m-1', 'Sin queso', Money::fromCents(-50)),
    ]);

    expect($linea->total()->cents)->toBe(250);
});

it('los agregados salen en texto ya resuelto para la comanda', function (): void {
    // La cocina lee «SIN CEBOLLA · EXTRA QUESO», no identificadores que
    // habría que ir a buscar.
    $linea = unaLinea(modificadores: [
        new OrderLineModifier('m-1', 'Sin cebolla', Money::zero()),
        new OrderLineModifier('m-2', 'Extra queso', Money::fromCents(50)),
    ]);

    expect($linea->modifiersText())->toBe('Sin cebolla · Extra queso');
});

it('el total suma las líneas y el reparto', function (): void {
    $pedido = unPedido(
        [unaLinea(300, 2), unaLinea(150)],
        reparto: Money::fromCents(200),
    );

    expect($pedido->subtotal()->cents)->toBe(750)
        ->and($pedido->total()->cents)->toBe(950);
});

it('con pago móvil el pedido nace esperando el comprobante', function (): void {
    expect(unPedido(esperandoPago: true)->status())->toBe(OrderStatus::PendingPayment)
        ->and(unPedido()->status())->toBe(OrderStatus::Placed);
});

it('confirmar sella la hora, que es de donde salen los tiempos', function (): void {
    $pedido = unPedido();
    $pedido->moveTo(OrderStatus::Confirmed, new DateTimeImmutable('2026-03-01 12:30:00'));

    expect($pedido->stampedAt('confirmed_at')?->format('H:i'))->toBe('12:30');
});

it('repetir el paso en el que ya está no es un error', function (): void {
    // Dos personas tocando «Confirmar» a la vez no pueden hacer saltar un
    // mensaje rojo en mitad del servicio.
    $pedido = unPedido();
    $pedido->moveTo(OrderStatus::Confirmed);

    expect(fn () => $pedido->moveTo(OrderStatus::Confirmed))->not->toThrow(InvalidTransition::class);
    expect($pedido->status())->toBe(OrderStatus::Confirmed);
});

it('no deja saltarse la cocina', function (): void {
    $pedido = unPedido();
    $pedido->moveTo(OrderStatus::Confirmed);

    expect(fn () => $pedido->moveTo(OrderStatus::Ready))->toThrow(InvalidTransition::class);
});

it('cancelar se salta la tabla, porque el cliente se arrepiente cuando quiere', function (): void {
    $pedido = unPedido();
    $pedido->moveTo(OrderStatus::Confirmed);
    $pedido->moveTo(OrderStatus::Preparing);

    $pedido->cancel('El cliente se arrepintió');

    expect($pedido->status())->toBe(OrderStatus::Cancelled)
        ->and($pedido->cancellationReason())->toBe('El cliente se arrepintió');
});

it('un pedido entregado no revive', function (): void {
    $pedido = unPedido();
    $pedido->moveTo(OrderStatus::Confirmed);
    $pedido->moveTo(OrderStatus::Preparing);
    $pedido->moveTo(OrderStatus::Ready);
    $pedido->moveTo(OrderStatus::Delivered);

    expect(fn () => $pedido->cancel('Ups'))->toThrow(InvalidTransition::class);
});

it('el tiempo de cocina es el MÁXIMO, no la suma', function (): void {
    // Los platos se hacen a la vez, no en fila. Sumar daría media hora para
    // dos arepas y la pantalla de cocina nunca marcaría nada como tarde.
    $pedido = unPedido([
        new OrderLine('p-1', 'Arepa', Money::fromCents(300), 1),
        new OrderLine('p-2', 'Parrilla', Money::fromCents(900), 1),
    ]);

    expect($pedido->estimatedPrepMinutes(['p-1' => 8, 'p-2' => 20]))->toBe(20);
});

it('sin tiempos conocidos no se inventa uno', function (): void {
    expect(unPedido()->estimatedPrepMinutes([]))->toBeNull();
});
