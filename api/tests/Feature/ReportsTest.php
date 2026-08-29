<?php

declare(strict_types=1);

/*
 * Los reportes.
 *
 * Cuatro preguntas que un dueño de comida se hace de verdad: cuánto vendí,
 * qué se vende más, a qué hora entra la gente, y cómo me pagan. Las pruebas
 * están escritas contra esas respuestas, no contra la forma del JSON.
 */

use App\Models\Catalog\ProductModel;
use App\Models\Orders\OrderModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Orders\Application\UseCases\AdvanceOrder;
use Modules\Orders\Application\UseCases\CancelOrder;
use Modules\Orders\Application\UseCases\PlaceOrder;
use Modules\Orders\Application\UseCases\RegisterPayment;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Platform\Subscription\Subscriptions;
use Platform\Tenancy\TenantStatus;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$sufijo}";
    $this->tenant = makeTenant($this->slug, plan: 'negocio');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'reports'] as $modulo) {
        enableModule($this->tenant, $modulo);
    }

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);
    $this->jugo = ProductModel::create(['name' => 'Jugo', 'price_cents' => 100]);
});

/**
 * Un pedido vendido: confirmado, y opcionalmente cobrado.
 *
 * Se usa el camino normal —los mismos casos de uso que usa la caja— y no un
 * `insert` a mano: una prueba que siembra filas a mano puede pasar en verde
 * con el flujo real roto.
 */
function vender(string $productId, int $quantity = 1, ?string $method = null, ?Carbon $cuando = null): OrderModel
{
    $order = app(PlaceOrder::class)->execute(
        items: [['product_id' => $productId, 'quantity' => $quantity]],
        channel: 'counter',
    );

    $order = app(AdvanceOrder::class)->execute((string) $order->id, OrderStatus::Confirmed);

    if ($method !== null) {
        $order = app(RegisterPayment::class)->execute(
            orderId: (string) $order->id,
            method: $method,
            amountCents: (int) $order->total_cents,
            verifiedInPerson: true,
        );
    }

    if ($cuando !== null) {
        // Se mueven las dos fechas: el reporte agrupa por `confirmed_at` y la
        // hora del día sale de `placed_at`. En UTC, que es lo que guarda la
        // columna: una cadena sin huso es justo el fallo que estas pruebas
        // vienen a fijar.
        OrderModel::where('id', $order->id)->update([
            'confirmed_at' => $cuando->copy()->utc(),
            'placed_at' => $cuando->copy()->utc(),
        ]);
    }

    return $order->refresh();
}

function reporte(string $slug, string $periodo = 'hoy'): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->getJson(urlFor($slug, "/api/v1/reports/sales?periodo={$periodo}"));
}

it('dice cuánto se vendió y cuánto entró, que no es lo mismo', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    // Uno cobrado y otro no: el de domicilio que se paga al llegar.
    vender($this->arepa->id, 2, method: 'cash_usd');
    vender($this->arepa->id, 1);

    $resumen = reporte($this->slug)->assertOk()->json('data.summary');

    expect($resumen['orders'])->toBe(2)
        ->and($resumen['soldCents'])->toBe(900)
        ->and($resumen['collectedCents'])->toBe(600)
        // La diferencia es lo que falta por cobrar, y es de las primeras cosas
        // que un dueño mira.
        ->and($resumen['outstandingCents'])->toBe(300)
        ->and($resumen['averageTicketCents'])->toBe(450);
});

it('un pedido sin confirmar NO es una venta', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    // Entró, pero el negocio todavía no lo aceptó.
    app(PlaceOrder::class)->execute(
        items: [['product_id' => $this->arepa->id, 'quantity' => 1]],
        channel: 'portal',
    );

    expect(reporte($this->slug)->json('data.summary.orders'))->toBe(0);
});

it('lo cancelado no cuenta como vendido, pero se dice cuánto fue', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    $order = vender($this->arepa->id, 1, method: 'cash_usd');
    app(CancelOrder::class)
        ->execute((string) $order->id, 'El cliente se arrepintió');

    vender($this->jugo->id, 1, method: 'cash_usd');

    $resumen = reporte($this->slug)->json('data.summary');

    expect($resumen['orders'])->toBe(1)
        ->and($resumen['soldCents'])->toBe(100)
        // Cuántos se cayeron es información, no ruido: si son muchos, algo
        // pasa en la cocina o en el precio.
        ->and($resumen['cancelled'])->toBe(1);
});

it('el ticket promedio con cero pedidos es cero, no una división por cero', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');

    expect(reporte($this->slug)->json('data.summary.averageTicketCents'))->toBe(0);
});

it('dice lo que más se vende, de mayor a menor', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    vender($this->jugo->id, 10, method: 'cash_usd');   // 10 × 100 = 1.000
    vender($this->arepa->id, 5, method: 'cash_usd');   //  5 × 300 = 1.500

    $productos = reporte($this->slug)->json('data.byProduct');

    // Ordenado por lo que DEJA, no por cuántas unidades salieron: diez jugos
    // se ven mucho y venden menos que cinco areperas.
    expect($productos[0]['name'])->toBe('Reina Pepiada')
        ->and($productos[0]['totalCents'])->toBe(1500)
        ->and($productos[0]['quantity'])->toBe(5)
        ->and($productos[1]['name'])->toBe('Jugo');
});

it('lo vendido se agrupa por el nombre que tenía en su momento', function (): void {
    // Si el dueño renombra y sube el precio, son dos ofertas distintas y
    // mezclarlas escondería justo el efecto que quiere medir.
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    vender($this->arepa->id, 1, method: 'cash_usd');

    $this->arepa->update(['name' => 'Reina Pepiada GRANDE']);

    vender($this->arepa->id, 1, method: 'cash_usd');

    $nombres = array_column(reporte($this->slug)->json('data.byProduct'), 'name');

    expect($nombres)->toContain('Reina Pepiada')
        ->toContain('Reina Pepiada GRANDE');
});

it('las 24 horas vienen SIEMPRE, con cero donde no hubo nada', function (): void {
    // Una pantalla que tenga que rellenar los huecos acabaría rellenándolos
    // distinto que el que exporte a una hoja de cálculo.
    entrarComo($this->slug, 'maria@ejemplo.com');

    $horas = reporte($this->slug)->json('data.byHour');

    expect($horas)->toHaveCount(24)
        ->and($horas[0]['hour'])->toBe(0)
        ->and($horas[23]['hour'])->toBe(23)
        ->and($horas[13]['orders'])->toBe(0);
});

it('la hora es la del NEGOCIO, no la del servidor', function (): void {
    /*
     * Es el fallo que pone el pico del almuerzo a las cuatro de la tarde: el
     * contenedor corre en UTC y Caracas está cuatro horas atrás.
     *
     * Con el reloj FIJADO: si dependiera de a qué hora corra la suite, pasaría
     * por la mañana y fallaría de madrugada, que es la peor clase de prueba.
     */
    test()->travelTo(Carbon::parse('2026-03-10 15:00', 'America/Caracas'));

    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    vender(
        $this->arepa->id,
        1,
        method: 'cash_usd',
        cuando: Carbon::parse('2026-03-10 12:00', 'America/Caracas'),
    );

    $horas = reporte($this->slug)->json('data.byHour');

    expect($horas[12]['orders'])->toBe(1)
        // Las 16:00 UTC son el mediodía de Caracas. Si el reporte agrupara por
        // la hora del servidor, el pico aparecería aquí.
        ->and($horas[16]['orders'])->toBe(0);

    test()->travelBack();
});

it('dice cómo pagan, y sólo cuenta lo confirmado', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    vender($this->arepa->id, 1, method: 'cash_usd');
    vender($this->arepa->id, 2, method: 'cash_usd');

    // Un pago móvil esperando revisión todavía NO es dinero.
    $order = vender($this->jugo->id, 1);
    app(RegisterPayment::class)->execute(
        orderId: (string) $order->id,
        method: 'pago_movil',
        amountCents: 100,
    );

    $metodos = reporte($this->slug)->json('data.byPaymentMethod');

    expect($metodos)->toHaveCount(1)
        ->and($metodos[0]['method'])->toBe('cash_usd')
        ->and($metodos[0]['totalCents'])->toBe(900);
});

it('dice por dónde entró cada pedido', function (): void {
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    vender($this->arepa->id, 1, method: 'cash_usd');

    $order = app(PlaceOrder::class)->execute(
        items: [['product_id' => $this->arepa->id, 'quantity' => 1]],
        channel: 'portal',
    );
    app(AdvanceOrder::class)->execute((string) $order->id, OrderStatus::Confirmed);

    $canales = collect(reporte($this->slug)->json('data.byChannel'))->keyBy('channel');

    expect($canales['counter']['orders'])->toBe(1)
        ->and($canales['portal']['orders'])->toBe(1);
});

it('«ayer» es ayer en la hora del negocio, y no arrastra lo de hoy', function (): void {
    test()->travelTo(Carbon::parse('2026-03-10 15:00', 'America/Caracas'));

    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    vender($this->arepa->id, 1, method: 'cash_usd');
    vender(
        $this->jugo->id,
        1,
        method: 'cash_usd',
        cuando: Carbon::parse('2026-03-09 12:00', 'America/Caracas'),
    );

    expect(reporte($this->slug, 'hoy')->json('data.summary.orders'))->toBe(1)
        ->and(reporte($this->slug, 'ayer')->json('data.summary.orders'))->toBe(1)
        ->and(reporte($this->slug, 'ayer')->json('data.summary.soldCents'))->toBe(100);

    test()->travelBack();
});

it('el mes incluye lo de hoy y lo de ayer', function (): void {
    // Con el reloj fijado a mitad de mes: así no hay que preguntarse qué pasa
    // cuando la suite corre un día 1, que es la clase de rama que nadie prueba.
    test()->travelTo(Carbon::parse('2026-03-10 15:00', 'America/Caracas'));

    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    vender($this->arepa->id, 1, method: 'cash_usd');
    vender(
        $this->jugo->id,
        1,
        method: 'cash_usd',
        cuando: Carbon::parse('2026-03-09 12:00', 'America/Caracas'),
    );

    expect(reporte($this->slug, 'mes')->json('data.summary.orders'))->toBe(2)
        // Y no arrastra lo del mes pasado.
        ->and(reporte($this->slug, 'mes')->json('data.summary.soldCents'))->toBe(400);

    test()->travelBack();
});

it('quien no puede ver las ventas, no las ve', function (): void {
    // Hay negocios donde el encargado opera todo el día y el dueño prefiere
    // que no vea los totales.
    actingForTenant($this->tenant);

    $carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos', pin: '4567');
    giveRole($this->tenant, $carlos, 'kitchen');

    entrarComo($this->slug, 'carlos@ejemplo.com');

    reporte($this->slug)->assertForbidden();
});

it('un negocio sin reportes no tiene reportes', function (): void {
    $sufijo = Str::lower(Str::random(6));
    $slug = "sinreportes-{$sufijo}";
    $otro = makeTenant($slug, plan: 'inicial');

    actingForTenant($otro);
    foreach (['core', 'catalog', 'orders'] as $modulo) {
        enableModule($otro, $modulo);
    }

    $pedro = makeUser($otro, 'pedro@ejemplo.com', 'Pedro');
    giveRole($otro, $pedro, 'owner');

    entrarComo($slug, 'pedro@ejemplo.com');

    // 404 y no 403: que un módulo no exista es información sobre el contrato.
    reporte($slug)->assertNotFound();
});

it('los reportes de un negocio no ven las ventas de otro', function (): void {
    // RLS ya lo garantiza, pero estas consultas llevan uniones y `groupBy`
    // escritos a mano: es justo donde un `where` olvidado no se nota.
    $sufijo = Str::lower(Str::random(6));
    $vecino = makeTenant("vecino-{$sufijo}", plan: 'negocio');

    actingForTenant($vecino);
    foreach (['core', 'catalog', 'orders', 'reports'] as $modulo) {
        enableModule($vecino, $modulo);
    }

    $pizza = ProductModel::create(['name' => 'Margarita', 'price_cents' => 900]);
    vender($pizza->id, 3, method: 'cash_usd');

    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    vender($this->arepa->id, 1, method: 'cash_usd');

    $data = reporte($this->slug)->json('data');

    expect($data['summary']['soldCents'])->toBe(300)
        ->and(array_column($data['byProduct'], 'name'))->toBe(['Reina Pepiada']);
});

it('el reporte no consulta la base una vez por producto', function (): void {
    /*
     * El N+1 es el defecto que más se nota en una máquina modesta, y un
     * reporte es donde más fácil se cuela: basta con recorrer los pedidos y
     * pedirle las líneas a cada uno.
     *
     * Se cuentan las consultas y se exige que no crezcan con los datos.
     */
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    foreach (range(1, 10) as $i) {
        vender($this->arepa->id, 1, method: 'cash_usd');
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    reporte($this->slug)->assertOk();

    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Cinco bloques de reporte más lo que cuesta resolver la sesión y las
    // capacidades. Lo que importa es que no dependa de cuántos pedidos hay.
    expect($consultas)->toBeLessThan(20);
});

it('una venta de las nueve de la noche cuenta como de HOY', function (): void {
    /*
     * El fallo que esta prueba fija: el rango se calcula en la hora del negocio
     * pero viaja a la base como texto SIN huso, y PostgreSQL lo lee en UTC. Con
     * Caracas cuatro horas atrás, las ventas de después de las ocho de la noche
     * caían fuera de «hoy» — y a las once de la mañana todo parecía correcto,
     * que es lo que lo hace difícil de ver.
     */
    $tenant = $this->tenant;
    $slug = $this->slug;
    $arepa = $this->arepa;

    // Las nueve de la noche en Caracas: la una de la madrugada del día
    // siguiente en UTC.
    test()->travelTo(Carbon::parse('2026-03-10 21:00', 'America/Caracas'));

    entrarComo($slug, 'maria@ejemplo.com');
    actingForTenant($tenant);

    vender($arepa->id, 1, method: 'cash_usd');

    expect(reporte($slug, 'hoy')->json('data.summary.orders'))->toBe(1)
        ->and(reporte($slug, 'ayer')->json('data.summary.orders'))->toBe(0);

    test()->travelBack();
});

it('exportar da un archivo que se abre en una hoja de cálculo', function (): void {
    /*
     * Esto es lo que hace verdad la frase del middleware de suspensión: «lee y
     * exporta». Sin un botón de exportar, esa promesa era una frase bonita en
     * un comentario.
     */
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    vender($this->arepa->id, 2, method: 'cash_usd');

    $respuesta = test()->withHeaders(browsingAs($this->slug))
        ->get(urlFor($this->slug, '/api/v1/reports/export?periodo=mes'))
        ->assertOk();

    $csv = $respuesta->streamedContent();

    // El BOM: sin él, Excel en Windows enseña «Reina Pepiáda».
    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('numero;fecha;estado')
        ->and($csv)->toContain('2x Reina Pepiada')
        // Coma decimal: una hoja en español lee «6.00» como seiscientos.
        ->and($csv)->toContain('6,00');
});

it('un negocio suspendido sigue pudiendo exportar lo suyo', function (): void {
    // Sus pedidos son suyos aunque nos deba tres meses. Lo que se corta es
    // seguir operando gratis, no el acceso a sus datos.
    entrarComo($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    vender($this->arepa->id, 1, method: 'cash_usd');

    app(Subscriptions::class)
        ->setTenantStatus($this->tenant, TenantStatus::Suspended);

    test()->withHeaders(browsingAs($this->slug))
        ->get(urlFor($this->slug, '/api/v1/reports/export'))
        ->assertOk();
});

it('quien no ve las ventas tampoco las exporta', function (): void {
    actingForTenant($this->tenant);

    $carlos = makeUser($this->tenant, 'carlos-export@ejemplo.com', 'Carlos');
    giveRole($this->tenant, $carlos, 'kitchen');

    entrarComo($this->slug, 'carlos-export@ejemplo.com');

    test()->withHeaders(browsingAs($this->slug))
        ->get(urlFor($this->slug, '/api/v1/reports/export'))
        ->assertForbidden();
});
