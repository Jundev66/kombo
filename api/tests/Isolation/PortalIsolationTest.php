<?php

declare(strict_types=1);

/*
 * El portal es la puerta que no pide contraseña, así que es donde más importa
 * que el aislamiento aguante.
 *
 * Aquí no hay sesión que comprobar ni permiso que mirar: lo único que separa el
 * pedido de un negocio del de otro es el subdominio y RLS. Estas pruebas
 * empujan justo ahí.
 */

use App\Models\Catalog\ProductModel;
use App\Models\Delivery\DeliveryZoneModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$sufijo}");
    $this->pizzeria = makeTenant("laesquina-{$sufijo}");

    $this->tokens = [];

    foreach ([$this->arepera => 'Reina Pepiada', $this->pizzeria => 'Margarita'] as $negocio => $plato) {
        actingForTenant($negocio);

        ProductModel::create(['name' => $plato, 'price_cents' => 300]);

        DeliveryZoneModel::create(['name' => "Zona de {$plato}", 'fee_cents' => 100]);

        $order = OrderModel::create([
            'number' => 1,
            'public_token' => Str::random(22),
            'total_cents' => 300,
            'channel' => 'portal',
            'customer_name' => "Cliente de {$plato}",
            'placed_at' => now(),
        ]);

        $this->tokens[$negocio] = $order->public_token;
    }
});

it('la carta de un negocio no aparece en la de otro', function (): void {
    actingForTenant($this->arepera);
    expect(ProductModel::pluck('name')->all())->toBe(['Reina Pepiada']);

    actingForTenant($this->pizzeria);
    expect(ProductModel::pluck('name')->all())->toBe(['Margarita']);
});

it('las zonas de reparto tampoco se cruzan', function (): void {
    actingForTenant($this->arepera);
    expect(DeliveryZoneModel::count())->toBe(1);

    actingForTenant($this->pizzeria);
    expect(DeliveryZoneModel::count())->toBe(1);
});

it('el token de un pedido NO abre ese pedido desde otro negocio', function (): void {
    /*
     * Es el ataque obvio: alguien tiene el enlace de su pedido en la arepera y
     * lo pega en la dirección de la pizzería. Sin RLS, la consulta por token
     * encontraría el pedido igual —el token es único en toda la tabla— y le
     * enseñaría a un negocio el pedido, el nombre y la dirección del cliente
     * de otro.
     */
    $ajeno = $this->tokens[$this->arepera];

    actingForTenant($this->pizzeria);

    expect(OrderModel::where('public_token', $ajeno)->first())->toBeNull();

    // Y desde el suyo sí, para que la prueba diga algo cuando pasa.
    actingForTenant($this->arepera);
    expect(OrderModel::where('public_token', $ajeno)->first())->not->toBeNull();
});

it('sin negocio en contexto, el portal no ve ningún pedido', function (): void {
    // Es el modo de fallo que importa: una conexión devuelta al pool sin
    // limpiar no puede convertirse en una que lo ve todo.
    withoutTenant();

    expect(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('delivery_zones')->count())->toBe(0);
});

it('no se puede colar un pedido en el portal de otro negocio', function (): void {
    actingForTenant($this->arepera);

    expect(fn () => DB::table('orders')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->pizzeria,
        'number' => 99,
        'public_token' => Str::random(22),
        'status' => 'placed',
        'channel' => 'portal',
        'total_cents' => 100,
        'placed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('un pedido no puede repartirse a la zona de otro negocio', function (): void {
    // La clave foránea es COMPUESTA: (tenant_id, delivery_zone_id). Con una
    // simple, esta fila sería perfectamente válida para la base de datos y el
    // error se descubriría meses después, cuando un reporte no cuadre.
    actingForTenant($this->pizzeria);
    $zonaAjena = DeliveryZoneModel::first()->id;

    actingForTenant($this->arepera);

    expect(fn () => OrderModel::create([
        'number' => 2,
        'public_token' => Str::random(22),
        'total_cents' => 300,
        'delivery_zone_id' => $zonaAjena,
        'placed_at' => now(),
    ]))->toThrow(QueryException::class);
});
