<?php

declare(strict_types=1);

/*
 * El repartidor.
 *
 * El rol existía desde la primera fase y sus permisos también; lo que no había
 * era forma de usarlos. Dos listas: lo que está listo para salir y lo que
 * llevo yo.
 */

use App\Models\Catalog\ProductModel;
use App\Models\Delivery\DeliveryZoneModel;
use App\Models\Orders\OrderModel;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Orders\Application\UseCases\AdvanceOrder;
use Modules\Orders\Application\UseCases\PlaceOrder;
use Modules\Orders\Application\UseCases\RegisterPayment;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Modules\Orders\Domain\ValueObjects\ServiceType;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$sufijo}";
    $this->tenant = makeTenant($this->slug, plan: 'negocio');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'kitchen', 'delivery'] as $modulo) {
        enableModule($this->tenant, $modulo);
    }

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->pedro = makeUser($this->tenant, 'pedro@ejemplo.com', 'Pedro');
    giveRole($this->tenant, $this->pedro, 'courier');

    $this->luis = makeUser($this->tenant, 'luis@ejemplo.com', 'Luis');
    giveRole($this->tenant, $this->luis, 'courier');

    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);
    $this->zona = DeliveryZoneModel::create(['name' => 'Los Palos Grandes', 'fee_cents' => 200]);
});

/** Un pedido a domicilio, listo para salir. */
function paraLlevar(string $productId, string $zoneId, string $zoneName, bool $pagado = false): OrderModel
{
    $order = app(PlaceOrder::class)->execute(
        items: [['product_id' => $productId, 'quantity' => 1]],
        serviceType: ServiceType::Delivery,
        channel: 'portal',
        customerName: 'Ana',
        customerPhone: '04141234567',
        deliveryAddress: 'Cuarta avenida, casa 12',
        deliveryFeeCents: 200,
        deliveryZoneId: $zoneId,
        deliveryZoneName: $zoneName,
    );

    if ($pagado) {
        app(RegisterPayment::class)->execute(
            orderId: (string) $order->id,
            method: 'cash_usd',
            amountCents: (int) $order->total_cents,
        );
    }

    foreach ([OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready] as $paso) {
        $order = app(AdvanceOrder::class)->execute((string) $order->id, $paso);
    }

    return $order;
}

function entregas(string $slug, string $path = '', string $method = 'GET'): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->json($method, urlFor($slug, "/api/v1/delivery/orders{$path}"));
}

it('el repartidor ve lo que está listo para salir, con lo que hay que cobrar', function (): void {
    actingForTenant($this->tenant);
    paraLlevar($this->arepa->id, $this->zona->id, 'Los Palos Grandes');

    entrarComo($this->slug, 'pedro@ejemplo.com');

    $data = entregas($this->slug)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['address'])->toBe('Cuarta avenida, casa 12')
        // El teléfono se ve: es con lo que se llama cuando no se encuentra la
        // casa, que pasa en la mitad de los repartos.
        ->and($data[0]['customerPhone'])->toBe('04141234567')
        // 300 + 200 de reparto, y nadie ha pagado.
        ->and($data[0]['toCollectCents'])->toBe(500)
        ->and($data[0]['isMine'])->toBeFalse();
});

it('lo que ya se pagó no hay que cobrarlo al llegar', function (): void {
    actingForTenant($this->tenant);
    paraLlevar($this->arepa->id, $this->zona->id, 'Los Palos Grandes', pagado: true);

    entrarComo($this->slug, 'pedro@ejemplo.com');

    expect(entregas($this->slug)->json('data.0.toCollectCents'))->toBe(0);
});

it('el primero que lo toma se lo lleva', function (): void {
    /*
     * Dos repartidores tocando «lo llevo yo» al mismo tiempo pasa de verdad en
     * la puerta de una cocina. Sin la condición en el UPDATE, los dos saldrían
     * con el mismo pedido.
     */
    actingForTenant($this->tenant);
    $order = paraLlevar($this->arepa->id, $this->zona->id, 'Los Palos Grandes');

    entrarComo($this->slug, 'pedro@ejemplo.com');
    entregas($this->slug, "/{$order->id}/take", 'POST')->assertOk();

    entrarComo($this->slug, 'luis@ejemplo.com');
    $respuesta = entregas($this->slug, "/{$order->id}/take", 'POST')->assertStatus(422);

    expect($respuesta->json('message'))->toContain('ya se lo llevó otra persona');

    actingForTenant($this->tenant);
    expect(OrderModel::find($order->id)->courier_name)->toBe('Pedro');
});

it('cada quien ve lo suyo, no lo de los demás', function (): void {
    // Una lista con las entregas de tres personas es una lista donde nadie
    // encuentra la propia.
    actingForTenant($this->tenant);

    $deLuis = paraLlevar($this->arepa->id, $this->zona->id, 'Los Palos Grandes');
    $libre = paraLlevar($this->arepa->id, $this->zona->id, 'Los Palos Grandes');

    entrarComo($this->slug, 'luis@ejemplo.com');
    entregas($this->slug, "/{$deLuis->id}/take", 'POST')->assertOk();

    entrarComo($this->slug, 'pedro@ejemplo.com');

    $numeros = array_column(entregas($this->slug)->json('data'), 'number');

    expect($numeros)->toBe([$libre->number]);
});

it('marcar entregado lo de otro no se puede', function (): void {
    // Es cómo se pierde el rastro de quién llevó qué, que es con lo que se le
    // paga a cada uno.
    actingForTenant($this->tenant);
    $order = paraLlevar($this->arepa->id, $this->zona->id, 'Los Palos Grandes');

    entrarComo($this->slug, 'luis@ejemplo.com');
    entregas($this->slug, "/{$order->id}/take", 'POST')->assertOk();

    entrarComo($this->slug, 'pedro@ejemplo.com');

    test()->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/delivery/orders/{$order->id}/advance"), [
            'status' => 'delivered',
        ])->assertForbidden();
});

it('el recorrido completo: lo tomo, salgo, lo entrego', function (): void {
    actingForTenant($this->tenant);
    $order = paraLlevar($this->arepa->id, $this->zona->id, 'Los Palos Grandes');

    entrarComo($this->slug, 'pedro@ejemplo.com');

    entregas($this->slug, "/{$order->id}/take", 'POST')->assertOk();

    test()->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/delivery/orders/{$order->id}/advance"), [
            'status' => 'out_for_delivery',
        ])->assertOk();

    test()->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/delivery/orders/{$order->id}/advance"), [
            'status' => 'delivered',
        ])->assertOk();

    actingForTenant($this->tenant);

    $entregado = OrderModel::find($order->id);

    expect($entregado->status->value)->toBe('delivered')
        // Y queda a su nombre, copiado: el día que Pedro se dé de baja, el
        // pedido tiene que seguir diciendo quién lo llevó.
        ->and($entregado->courier_name)->toBe('Pedro')
        ->and($entregado->delivered_at)->not->toBeNull();
});

it('soltar un pedido lo devuelve a la lista', function (): void {
    actingForTenant($this->tenant);
    $order = paraLlevar($this->arepa->id, $this->zona->id, 'Los Palos Grandes');

    entrarComo($this->slug, 'pedro@ejemplo.com');
    entregas($this->slug, "/{$order->id}/take", 'POST')->assertOk();
    entregas($this->slug, "/{$order->id}/release", 'POST')->assertOk();

    entrarComo($this->slug, 'luis@ejemplo.com');
    entregas($this->slug, "/{$order->id}/take", 'POST')->assertOk();

    actingForTenant($this->tenant);
    expect(OrderModel::find($order->id)->courier_name)->toBe('Luis');
});

it('lo que se busca no es de domicilio, y no aparece', function (): void {
    actingForTenant($this->tenant);

    $order = app(PlaceOrder::class)->execute(
        items: [['product_id' => $this->arepa->id, 'quantity' => 1]],
        channel: 'counter',
    );

    foreach ([OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready] as $paso) {
        app(AdvanceOrder::class)->execute((string) $order->id, $paso);
    }

    entrarComo($this->slug, 'pedro@ejemplo.com');

    expect(entregas($this->slug)->json('data'))->toBe([]);
});

it('la cocina no reparte', function (): void {
    actingForTenant($this->tenant);

    $carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos');
    giveRole($this->tenant, $carlos, 'kitchen');

    entrarComo($this->slug, 'carlos@ejemplo.com');

    entregas($this->slug)->assertForbidden();
});
