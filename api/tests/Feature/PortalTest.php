<?php

declare(strict_types=1);

/*
 * El portal público: la única parte del sistema que se usa SIN sesión.
 *
 * Es también la más expuesta —cualquiera en internet puede llamarla— así que
 * las pruebas se escriben desde esa desconfianza: que nada de lo que mande el
 * cliente decida un precio, que no se acepte un pedido que el negocio no puede
 * cumplir, y que un token sólo abra su propio pedido.
 */

use App\Models\Catalog\ProductModel;
use App\Models\Delivery\DeliveryZoneModel;
use App\Models\Kitchen\KitchenTicketModel;
use App\Models\Orders\OrderModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Portal\Application\UseCases\CancelExpiredOrders;
use Platform\Capabilities\CurrentCapabilities;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$sufijo}";
    $this->tenant = makeTenant($this->slug, plan: 'negocio');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'kitchen', 'portal', 'delivery'] as $modulo) {
        enableModule($this->tenant, $modulo);
    }

    abierto($this->tenant);
    ajuste($this->tenant, 'portal.pago_movil_details', 'Banco · 0102 · V-12.345.678');

    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    $this->zona = DeliveryZoneModel::create([
        'name' => 'Los Palos Grandes',
        'fee_cents' => 200,
        'estimated_minutes' => 30,
    ]);
});

/** Deja el negocio abierto las 24 horas, todos los días. */
function abierto(string $tenantId): void
{
    for ($weekday = 0; $weekday <= 6; $weekday++) {
        DB::table('business_hours')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'weekday' => $weekday,
            'opens_at' => '00:00',
            'closes_at' => '23:59',
            'is_closed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function cerrado(string $tenantId): void
{
    DB::table('business_hours')->where('tenant_id', $tenantId)->update(['is_closed' => true]);
}

function ajuste(string $tenantId, string $key, string $value): void
{
    DB::table('tenant_settings')->updateOrInsert(
        ['tenant_id' => $tenantId, 'key' => $key],
        ['id' => (string) Str::uuid7(), 'value' => $value, 'created_at' => now(), 'updated_at' => now()],
    );

    app(CurrentCapabilities::class)->reset();
}

/** Una llamada al portal, SIN sesión: como la haría alguien de la calle. */
function comoCliente(string $slug, string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders(['Accept' => 'application/json'])
        ->json($method, urlFor($slug, $path), $body);
}

/**
 * Una foto de pago, falsificada por su TIPO y no generada de verdad.
 *
 * `UploadedFile::fake()->image()` necesita la extensión GD, y meterla en la
 * imagen de producción para que una prueba pueda dibujar un cuadrado sería
 * engordarla por nada: el sistema no procesa imágenes, sólo las guarda.
 */
function comprobanteFalso(string $name = 'pago.jpg', string $mime = 'image/jpeg'): UploadedFile
{
    return UploadedFile::fake()->create($name, 120, $mime);
}

function pedirAlPortal(string $slug, array $body): TestResponse
{
    return comoCliente($slug, 'POST', '/api/v1/portal/orders', $body);
}

it('la tienda y la carta se ven SIN entrar', function (): void {
    // Pedirle una cuenta a alguien para comprar una arepa es la forma más
    // rápida de que se vaya.
    comoCliente($this->slug, 'GET', '/api/v1/portal/shop')
        ->assertOk()
        ->assertJsonPath('data.slug', $this->slug)
        ->assertJsonPath('data.isOpen', true)
        ->assertJsonPath('data.zones.0.name', 'Los Palos Grandes');

    comoCliente($this->slug, 'GET', '/api/v1/portal/menu')
        ->assertOk()
        ->assertJsonPath('data.products.0.name', 'Reina Pepiada');
});

it('la carta pública NO enseña lo apagado ni lo agotado', function (): void {
    actingForTenant($this->tenant);

    ProductModel::create(['name' => 'Fuera de carta', 'price_cents' => 100, 'is_active' => false]);
    ProductModel::create([
        'name' => 'Se acabó', 'price_cents' => 100, 'track_stock' => true, 'stock_qty' => 0,
    ]);

    $nombres = comoCliente($this->slug, 'GET', '/api/v1/portal/menu')->json('data.products.*.name');

    expect($nombres)->toBe(['Reina Pepiada']);
});

it('un pedido del portal entra por el canal correcto y llega al negocio', function (): void {
    $respuesta = pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 2]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertCreated();

    $respuesta->assertJsonPath('data.totalCents', 600)
        // Efectivo al recibir: entra directo a la cola del negocio.
        ->assertJsonPath('data.status', 'placed')
        ->assertJsonPath('data.needsReceipt', false)
        // En palabras del CLIENTE, no en las del negocio.
        ->assertJsonPath('data.statusLabel', 'Recibido, ya lo vemos');

    actingForTenant($this->tenant);

    $order = OrderModel::latest('created_at')->first();
    expect($order->channel)->toBe('portal')
        ->and($order->customer_phone)->toBe('04141234567');
});

it('el precio lo pone el catálogo, aunque el cliente mande otro', function (): void {
    // Es la puerta más expuesta del sistema: cualquiera puede editar el cuerpo
    // de la petición desde la consola del navegador.
    pedirAlPortal($this->slug, [
        'items' => [[
            'product_id' => $this->arepa->id,
            'quantity' => 1,
            'unit_price_cents' => 1,
            'line_total_cents' => 1,
        ]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
        'total_cents' => 1,
        'delivery_fee_cents' => 0,
    ])->assertCreated()->assertJsonPath('data.totalCents', 300);
});

it('la tarifa del reparto sale de la ZONA, no de la petición', function (): void {
    pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'delivery',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
        'delivery_zone_id' => $this->zona->id,
        'delivery_address' => 'Cuarta avenida, casa 12',
        'delivery_fee_cents' => 0,
    ])->assertCreated()
        ->assertJsonPath('data.deliveryFeeCents', 200)
        ->assertJsonPath('data.totalCents', 500)
        // COPIADO: el pedido tiene que decir a qué barrio fue aunque la zona
        // se apague mañana.
        ->assertJsonPath('data.deliveryZoneName', 'Los Palos Grandes');
});

it('no se reparte a una zona que no existe', function (): void {
    pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'delivery',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
        'delivery_zone_id' => (string) Str::uuid7(),
        'delivery_address' => 'Por ahí',
    ])->assertStatus(422)->assertJsonValidationErrors('delivery_zone_id');
});

it('sin dirección no hay a dónde llevarlo', function (): void {
    pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'delivery',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
        'delivery_zone_id' => $this->zona->id,
    ])->assertStatus(422)->assertJsonValidationErrors('delivery_address');
});

it('cerrado no se acepta ni un pedido', function (): void {
    // Aceptar un pedido que nadie va a preparar es peor que rechazarlo: el
    // cliente se queda esperando comida que no está haciendo nadie.
    actingForTenant($this->tenant);
    cerrado($this->tenant);

    comoCliente($this->slug, 'GET', '/api/v1/portal/shop')->assertJsonPath('data.isOpen', false);

    pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertStatus(422);

    actingForTenant($this->tenant);
    expect(OrderModel::count())->toBe(0);
});

it('el pago móvil deja el pedido esperando el comprobante, con fecha de caducidad', function (): void {
    $respuesta = pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'pago_movil',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertCreated();

    $respuesta->assertJsonPath('data.status', 'pending_payment')
        ->assertJsonPath('data.needsReceipt', true);

    expect($respuesta->json('data.expiresAt'))->not->toBeNull();

    // Y NO llega a la cocina: no se cocina lo que todavía no se pagó.
    actingForTenant($this->tenant);
    expect(KitchenTicketModel::count())->toBe(0);
});

it('sin datos de pago móvil, el portal no lo ofrece ni lo acepta', function (): void {
    // Un botón de pagar que no dice a quién pagarle es una llamada de teléfono
    // garantizada.
    actingForTenant($this->tenant);
    ajuste($this->tenant, 'portal.pago_movil_details', '');

    comoCliente($this->slug, 'GET', '/api/v1/portal/shop')
        ->assertJsonPath('data.paymentMethods', ['cash'])
        ->assertJsonPath('data.pagoMovilDetails', null);

    pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'pago_movil',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertStatus(422)->assertJsonValidationErrors('payment_method');
});

it('el mínimo del reparto se dice con el número, no con un «no»', function (): void {
    actingForTenant($this->tenant);
    ajuste($this->tenant, 'delivery.minimum_order_cents', '1000');

    $respuesta = pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'delivery',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
        'delivery_zone_id' => $this->zona->id,
        'delivery_address' => 'Cuarta avenida, casa 12',
    ])->assertStatus(422);

    expect($respuesta->json('message'))->toContain('$10,00');

    // La transacción se deshizo entera: ni pedido, ni hueco en el correlativo.
    actingForTenant($this->tenant);
    expect(OrderModel::count())->toBe(0);
});

it('el token sigue su propio pedido y ningún otro', function (): void {
    $mio = pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->json('data.token');

    comoCliente($this->slug, 'GET', "/api/v1/portal/orders/{$mio}")
        ->assertOk()
        ->assertJsonPath('data.customerName', 'Ana Cliente');

    // 404 y no 403: un 403 confirmaría que ese token existe en algún sitio.
    comoCliente($this->slug, 'GET', '/api/v1/portal/orders/'.Str::random(22))
        ->assertNotFound();
});

it('el seguimiento no le enseña al cliente lo que no es suyo', function (): void {
    // Quien mira esto es alguien de la calle con un enlace. Aquí no van las
    // notas internas del negocio, ni quién lo atendió, ni las referencias de
    // otros pagos.
    $token = pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->json('data.token');

    $datos = comoCliente($this->slug, 'GET', "/api/v1/portal/orders/{$token}")->json('data');

    expect($datos)->not->toHaveKeys(['payments', 'createdBy', 'customerPhone', 'id']);
});

it('el comprobante se guarda en disco privado y deja el pago esperando revisión', function (): void {
    Storage::fake('local');

    $token = pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'pago_movil',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->json('data.token');

    test()->withHeaders(['Accept' => 'application/json'])
        ->post(urlFor($this->slug, "/api/v1/portal/orders/{$token}/receipt"), [
            'receipt' => comprobanteFalso(),
            'reference' => '998877',
        ])->assertOk();

    actingForTenant($this->tenant);

    $order = OrderModel::where('public_token', $token)->first();
    $pago = $order->payments()->first();

    expect($pago->status)->toBe('pending_review')
        ->and($pago->reference)->toBe('998877')
        // La ruta lleva el negocio delante: dar de baja a un cliente es borrar
        // una carpeta.
        ->and($pago->receipt_url)->toStartWith("receipts/{$this->tenant}/")
        // Y el pedido SIGUE esperando: que llegue una foto no significa que el
        // dinero llegó. Alguien del negocio mira su cuenta y dice que sí.
        ->and($order->status->value)->toBe('pending_payment');

    Storage::disk('local')->assertExists($pago->receipt_url);
});

it('lo que no es una foto no se sube', function (): void {
    // La puerta acepta archivos de cualquiera en internet. Se valida el tipo,
    // no la extensión: un `.jpg` que en realidad es otra cosa no pasa.
    Storage::fake('local');

    $token = pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'pago_movil',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->json('data.token');

    test()->withHeaders(['Accept' => 'application/json'])
        ->post(urlFor($this->slug, "/api/v1/portal/orders/{$token}/receipt"), [
            'receipt' => comprobanteFalso('comprobante.jpg', 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('receipt');
});

it('no se manda comprobante a un pedido que ya no lo espera', function (): void {
    Storage::fake('local');

    $token = pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->json('data.token');

    test()->withHeaders(['Accept' => 'application/json'])
        ->post(urlFor($this->slug, "/api/v1/portal/orders/{$token}/receipt"), [
            'receipt' => comprobanteFalso(),
        ])->assertStatus(422);
});

it('los pedidos vencidos se cierran solos, con su motivo', function (): void {
    $token = pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'pago_movil',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->json('data.token');

    actingForTenant($this->tenant);

    // Se adelanta el reloj del pedido en vez de esperar dos horas.
    OrderModel::where('public_token', $token)->update(['expires_at' => now()->subMinute()]);

    $cerrados = app(CancelExpiredOrders::class)->execute();

    expect($cerrados)->toBe(1);

    $order = OrderModel::where('public_token', $token)->first();
    expect($order->status->value)->toBe('cancelled')
        ->and($order->cancellation_reason)->toContain('comprobante');
});

it('un pedido pagado y vivo no lo cierra nadie', function (): void {
    pedirAlPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertCreated();

    actingForTenant($this->tenant);

    expect(app(CancelExpiredOrders::class)->execute())->toBe(0);
});

it('un negocio SIN portal no tiene portal: sus rutas no existen', function (): void {
    $sufijo = Str::lower(Str::random(6));
    $slug = "sinportal-{$sufijo}";
    $otro = makeTenant($slug, plan: 'negocio');

    actingForTenant($otro);
    foreach (['core', 'catalog', 'orders'] as $modulo) {
        enableModule($otro, $modulo);
    }

    // 404 y no 403: que un módulo no exista para un negocio es información
    // sobre su contrato, no sobre los permisos de nadie.
    comoCliente($slug, 'GET', '/api/v1/portal/shop')->assertNotFound();
    comoCliente($slug, 'GET', '/api/v1/portal/menu')->assertNotFound();
});
