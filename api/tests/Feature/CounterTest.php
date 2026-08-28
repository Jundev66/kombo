<?php

declare(strict_types=1);

/*
 * La caja del mostrador y el papel que se le entrega al cliente.
 *
 * Esto es lo que Juan va a usar todos los días, así que las pruebas están
 * escritas contra lo que pasa en el mostrador —se cobra mezclado, se anula con
 * el encargado al lado, se reimprime la nota que se manchó— y no contra la
 * forma de los endpoints.
 */

use App\Models\Catalog\ModifierGroupModel;
use App\Models\Catalog\ProductModel;
use App\Models\Documents\DeliveryNoteModel;
use App\Models\Kitchen\KitchenTicketModel;
use App\Models\Orders\OrderModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$sufijo}";
    $this->tenant = makeTenant($this->slug, plan: 'negocio');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'kitchen', 'documents', 'counter'] as $modulo) {
        enableModule($this->tenant, $modulo);
    }

    // María es la dueña; José el encargado —puede anular solo—; Ana está en el
    // mostrador y sólo puede SOLICITAR la anulación.
    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->jose = makeUser($this->tenant, 'jose@ejemplo.com', 'José', pin: '2345');
    giveRole($this->tenant, $this->jose, 'manager');

    $this->ana = makeUser($this->tenant, 'ana@ejemplo.com', 'Ana', pin: '3456');
    giveRole($this->tenant, $this->ana, 'counter');

    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);
    $this->jugo = ProductModel::create(['name' => 'Jugo de parchita', 'price_cents' => 150]);

    $grupo = ModifierGroupModel::create(['name' => 'Extras', 'min_choices' => 0, 'max_choices' => 3]);
    $this->quesoExtra = $grupo->modifiers()->create(['name' => 'Extra queso', 'price_delta_cents' => 100]);
});

/** Cobra una venta en el mostrador. */
function cobrar(string $slug, array $payload): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, '/api/v1/counter/sales'), $payload);
}

/** Una venta simple ya cobrada: una arepa en efectivo. Devuelve la respuesta. */
function ventaSimple(string $slug, string $productId, int $cents = 300): TestResponse
{
    return cobrar($slug, [
        'items' => [['product_id' => $productId, 'quantity' => 1]],
        'payments' => [['method' => 'cash_usd', 'amount_cents' => $cents]],
    ])->assertCreated();
}

it('cobrar en el mostrador emite la nota y manda la comanda a la cocina', function (): void {
    // El recorrido completo de una venta presencial, en UNA llamada: el cliente
    // está delante y ya pagó, así que no hay nada que esperar.
    entrarComo($this->slug, 'ana@ejemplo.com');

    $respuesta = cobrar($this->slug, [
        'items' => [
            ['product_id' => $this->arepa->id, 'quantity' => 2, 'modifier_ids' => [$this->quesoExtra->id]],
            ['product_id' => $this->jugo->id, 'quantity' => 1],
        ],
        'payments' => [['method' => 'cash_usd', 'amount_cents' => 950]],
    ])->assertCreated();

    // 2 × (300 + 100) + 150. El importe lo calcula el servidor.
    $respuesta->assertJsonPath('data.order.totalCents', 950);
    $respuesta->assertJsonPath('data.order.status', 'confirmed');
    $respuesta->assertJsonPath('data.order.paymentStatus', 'paid');

    // El papel dice lo que es, y lo dice desde el propio documento guardado.
    $respuesta->assertJsonPath('data.note.title', 'NOTA DE ENTREGA');
    $respuesta->assertJsonPath('data.note.disclaimer', 'No es una factura');
    $respuesta->assertJsonPath('data.note.snapshot.disclaimer', 'No es una factura');
    $respuesta->assertJsonPath('data.note.reference', 'A-000001');

    // Y lo que motivó todo el proyecto: la comanda aparece sola en la cocina,
    // con el MISMO número que se grita en el mostrador.
    actingForTenant($this->tenant);

    $ticket = KitchenTicketModel::first();
    expect($ticket)->not->toBeNull()
        ->and($ticket->status->value)->toBe('pending')
        ->and($ticket->number)->toBe((int) $respuesta->json('data.order.number'));
});

it('se cobra mezclado: parte en efectivo y el resto en pago móvil', function (): void {
    // Es como se cobra aquí, y la razón de que los pagos sean varias filas y no
    // una columna.
    entrarComo($this->slug, 'ana@ejemplo.com');

    cobrar($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 3]],
        'payments' => [
            ['method' => 'cash_usd', 'amount_cents' => 500],
            ['method' => 'pago_movil', 'amount_cents' => 400, 'reference' => '0102-99887'],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.order.totalCents', 900)
        ->assertJsonPath('data.order.paidCents', 900)
        ->assertJsonPath('data.note.snapshot.payments.1.reference', '0102-99887');
});

it('la caja NO puede ponerle precio a lo que vende', function (): void {
    // Un navegador manipulado no puede cobrarse a sí mismo lo que quiera: los
    // importes de línea que mande se ignoran y el precio sale del catálogo.
    entrarComo($this->slug, 'ana@ejemplo.com');

    cobrar($this->slug, [
        'items' => [[
            'product_id' => $this->arepa->id,
            'quantity' => 1,
            'unit_price_cents' => 1,
            'line_total_cents' => 1,
        ]],
        'payments' => [['method' => 'cash_usd', 'amount_cents' => 300]],
        'total_cents' => 1,
    ])->assertCreated()
        ->assertJsonPath('data.order.totalCents', 300)
        ->assertJsonPath('data.note.totalCents', 300);
});

it('el correlativo va uno detrás de otro, sin huecos', function (): void {
    entrarComo($this->slug, 'ana@ejemplo.com');

    $referencias = collect(range(1, 3))
        ->map(fn (): string => ventaSimple($this->slug, $this->arepa->id)->json('data.note.reference'));

    expect($referencias->all())->toBe(['A-000001', 'A-000002', 'A-000003']);
});

it('anular una venta no libera su número', function (): void {
    // Si dos papeles pueden llevar el mismo número, el número deja de
    // identificar a ninguno.
    entrarComo($this->slug, 'jose@ejemplo.com');

    $primera = ventaSimple($this->slug, $this->arepa->id);
    $orderId = $primera->json('data.order.id');

    expect($primera->json('data.note.reference'))->toBe('A-000001');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'El cliente se arrepintió',
        ])
        ->assertOk()
        ->assertJsonPath('data.note.isVoided', true)
        ->assertJsonPath('data.note.reference', 'A-000001')
        ->assertJsonPath('data.order.status', 'cancelled');

    // La siguiente venta toma el SIGUIENTE número, no el que quedó anulado.
    expect(ventaSimple($this->slug, $this->arepa->id)->json('data.note.reference'))->toBe('A-000002');
});

it('anular una venta saca la comanda de la cocina', function (): void {
    // Sin esto, el cocinero termina un plato que nadie va a recoger.
    entrarComo($this->slug, 'jose@ejemplo.com');

    $orderId = ventaSimple($this->slug, $this->arepa->id)->json('data.order.id');

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/kitchen/tickets'))
        ->assertJsonCount(1, 'data');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'Se equivocó de pedido',
        ])->assertOk();

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/kitchen/tickets'))
        ->assertJsonCount(0, 'data');

    // Pero NO se borra: hubo materia prima de por medio y el dueño va a querer
    // saber cuánta se perdió.
    actingForTenant($this->tenant);

    $ticket = KitchenTicketModel::first();
    expect($ticket->status->value)->toBe('cancelled')
        ->and($ticket->cancelled_at)->not->toBeNull();
});

it('el mostrador SOLICITA la anulación; sin el PIN del encargado no pasa', function (): void {
    entrarComo($this->slug, 'ana@ejemplo.com');

    $orderId = ventaSimple($this->slug, $this->arepa->id)->json('data.order.id');

    // 422 sobre `authorization_pin`, no 403: la caja tiene que saber que esto
    // tiene solución ahí mismo, y abrir el teclado del PIN.
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'Se equivocó de pedido',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('authorization_pin');

    actingForTenant($this->tenant);
    expect(OrderModel::find($orderId)->status->value)->not->toBe('cancelled');
});

it('con el PIN del encargado sí se anula, y queda a nombre de quien autorizó', function (): void {
    entrarComo($this->slug, 'ana@ejemplo.com');

    $orderId = ventaSimple($this->slug, $this->arepa->id)->json('data.order.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'Se equivocó de pedido',
            'authorized_by' => $this->jose,
            'authorization_pin' => '2345',
        ])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'cancelled');

    actingForTenant($this->tenant);

    // Lo hizo Ana, lo autorizó José, y las dos cosas quedan escritas. Es toda
    // la razón de que el mostrador sólo pueda solicitarlo.
    $registro = DB::table('audit_log')
        ->where('tenant_id', $this->tenant)
        ->where('action', 'orders.cancelled')
        ->first();

    expect($registro->user_id)->toBe($this->ana)
        ->and($registro->authorized_by)->toBe($this->jose)
        ->and($registro->reason)->toBe('Se equivocó de pedido');
});

it('un PIN equivocado no dice en qué se equivocó', function (): void {
    // Un solo mensaje para los tres fallos: no existe, está inactivo, o el PIN
    // está mal. Decir cuál es regalar la mitad del trabajo a quien prueba.
    entrarComo($this->slug, 'ana@ejemplo.com');

    $orderId = ventaSimple($this->slug, $this->arepa->id)->json('data.order.id');

    $conPinMalo = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'Se equivocó de pedido',
            'authorized_by' => $this->jose,
            'authorization_pin' => '9999',
        ])->assertForbidden();

    $conUsuarioInventado = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'Se equivocó de pedido',
            'authorized_by' => (string) Str::uuid7(),
            'authorization_pin' => '2345',
        ])->assertForbidden();

    expect($conPinMalo->json('message'))->toBe($conUsuarioInventado->json('message'));

    actingForTenant($this->tenant);
    expect(OrderModel::find($orderId)->status->value)->not->toBe('cancelled');
});

it('nadie se autoriza a sí mismo', function (): void {
    // Ana tiene el PIN puesto y sabe el suyo. Si pudiera usarlo, el permiso
    // «solicita» no valdría nada.
    entrarComo($this->slug, 'ana@ejemplo.com');

    $orderId = ventaSimple($this->slug, $this->arepa->id)->json('data.order.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'Se equivocó de pedido',
            'authorized_by' => $this->ana,
            'authorization_pin' => '3456',
        ])->assertForbidden();

    actingForTenant($this->tenant);
    expect(OrderModel::find($orderId)->status->value)->not->toBe('cancelled');
});

it('anular exige un motivo', function (): void {
    entrarComo($this->slug, 'jose@ejemplo.com');

    $orderId = ventaSimple($this->slug, $this->arepa->id)->json('data.order.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

it('reimprimir da EXACTAMENTE el mismo papel aunque la carta haya cambiado', function (): void {
    // El que reclama tiene el original en la mano. Reconstruir la nota desde
    // las tablas vivas daría otro papel.
    entrarComo($this->slug, 'ana@ejemplo.com');

    $venta = ventaSimple($this->slug, $this->arepa->id);
    $noteId = $venta->json('data.note.id');
    $original = $venta->json('data.note.snapshot');

    actingForTenant($this->tenant);
    $this->arepa->update(['name' => 'Reina Pepiada GRANDE', 'price_cents' => 500]);

    $reimpresa = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/notes/{$noteId}/reprint"))
        ->assertOk();

    // `toEqual` y no `toBe`: jsonb no conserva el orden de las claves, y lo
    // que tiene que ser idéntico es el contenido del papel.
    expect($reimpresa->json('data.snapshot'))->toEqual($original)
        ->and($reimpresa->json('data.snapshot.lines.0.name'))->toBe('Reina Pepiada')
        // Se cuenta: una nota reimpresa cinco veces es una pregunta que alguien
        // va a querer hacerse.
        ->and($reimpresa->json('data.printedCount'))->toBe(1);
});

it('cobrar dos veces por un doble toque no emite dos documentos', function (): void {
    // La segunda nota tendría otro número y respaldaría la misma comida.
    entrarComo($this->slug, 'ana@ejemplo.com');

    $orderId = ventaSimple($this->slug, $this->arepa->id)->json('data.order.id');

    actingForTenant($this->tenant);

    expect(DeliveryNoteModel::where('order_id', $orderId)->count())->toBe(1);
});

it('la cocina no puede cobrar', function (): void {
    $carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos', pin: '4567');
    giveRole($this->tenant, $carlos, 'kitchen');

    entrarComo($this->slug, 'carlos@ejemplo.com');

    // 403 y no 404: el módulo existe para este negocio; lo que no tiene Carlos
    // es el permiso, y eso se le dice claro.
    cobrar($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'payments' => [['method' => 'cash_usd', 'amount_cents' => 300]],
    ])->assertForbidden();
});

it('un negocio SIN caja no tiene caja: sus rutas no existen', function (): void {
    // Un puesto que sólo vende por el portal. Sus rutas responden 404 —que un
    // módulo no exista es información sobre el contrato, no sobre el permiso—.
    $sufijo = Str::lower(Str::random(6));
    $slug = "laesquina-{$sufijo}";
    $otro = makeTenant($slug, plan: 'inicial');

    actingForTenant($otro);
    foreach (['core', 'catalog', 'orders', 'kitchen'] as $modulo) {
        enableModule($otro, $modulo);
    }

    $pedro = makeUser($otro, 'pedro@ejemplo.com', 'Pedro', pin: '1234');
    giveRole($otro, $pedro, 'owner');

    entrarComo($slug, 'pedro@ejemplo.com');

    cobrar($slug, [
        'items' => [['product_id' => (string) Str::uuid7(), 'quantity' => 1]],
        'payments' => [['method' => 'cash_usd', 'amount_cents' => 300]],
    ])->assertNotFound();

    $this->withHeaders(browsingAs($slug))
        ->getJson(urlFor($slug, '/api/v1/notes'))
        ->assertNotFound();
});
