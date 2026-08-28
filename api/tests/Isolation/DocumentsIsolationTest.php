<?php

declare(strict_types=1);

/*
 * Las notas de un negocio no existen para otro — y su correlativo tampoco.
 *
 * Aquí hay algo más que privacidad: si dos negocios compartieran numeración, el
 * de al lado se comería los números de éste y su correlativo saldría con
 * huecos que nadie sabría explicar.
 */

use App\Models\Documents\DeliveryNoteModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$sufijo}");
    $this->pizzeria = makeTenant("laesquina-{$sufijo}");

    $this->pedidos = [];

    foreach ([$this->arepera, $this->pizzeria] as $negocio) {
        actingForTenant($negocio);

        $pedido = OrderModel::create([
            'number' => 1, 'public_token' => Str::random(22), 'total_cents' => 300, 'placed_at' => now(),
        ]);

        $this->pedidos[$negocio] = (string) $pedido->id;

        DeliveryNoteModel::create([
            'order_id' => $pedido->id,
            'series' => 'A',
            'number' => 1,
            'issued_at' => now(),
            'customer_name' => $negocio === $this->arepera ? 'Cliente del Sazón' : 'Cliente de La Esquina',
            'subtotal_cents' => 300,
            'total_cents' => 300,
            'currency' => 'USD',
            'snapshot' => ['title' => 'NOTA DE ENTREGA'],
        ]);
    }
});

it('cada negocio ve sólo sus propias notas', function (): void {
    actingForTenant($this->arepera);
    expect(DeliveryNoteModel::pluck('customer_name')->all())->toBe(['Cliente del Sazón']);

    actingForTenant($this->pizzeria);
    expect(DeliveryNoteModel::pluck('customer_name')->all())->toBe(['Cliente de La Esquina']);
});

it('el correlativo es de cada negocio: los dos tienen su A-000001', function (): void {
    // Dos negocios con la misma serie y el mismo número no chocan, porque el
    // único lleva `tenant_id` delante. Compartir numeración sería que el de al
    // lado te consuma los números.
    withoutTenant();

    expect(DB::table('delivery_notes')->count())->toBe(0);
});

it('no se puede emitir una nota a nombre de otro negocio', function (): void {
    actingForTenant($this->arepera);

    expect(fn () => DB::table('delivery_notes')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->pizzeria,
        'order_id' => $this->pedidos[$this->pizzeria],
        'series' => 'A',
        'number' => 99,
        'issued_at' => now(),
        'subtotal_cents' => 100,
        'total_cents' => 100,
        'currency' => 'USD',
        'snapshot' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('un pedido no puede tener dos notas', function (): void {
    // Es lo que impide que un doble toque en «Cobrar» saque dos documentos con
    // dos números distintos para la misma comida.
    actingForTenant($this->arepera);

    expect(fn () => DeliveryNoteModel::create([
        'order_id' => $this->pedidos[$this->arepera],
        'series' => 'A',
        'number' => 2,
        'issued_at' => now(),
        'subtotal_cents' => 300,
        'total_cents' => 300,
        'currency' => 'USD',
        'snapshot' => [],
    ]))->toThrow(QueryException::class);
});

it('el mismo número no se repite dentro de una serie', function (): void {
    actingForTenant($this->arepera);

    $otro = OrderModel::create([
        'number' => 2, 'public_token' => Str::random(22), 'total_cents' => 300, 'placed_at' => now(),
    ]);

    expect(fn () => DeliveryNoteModel::create([
        'order_id' => $otro->id,
        'series' => 'A',
        'number' => 1,   // ya emitida
        'issued_at' => now(),
        'subtotal_cents' => 300,
        'total_cents' => 300,
        'currency' => 'USD',
        'snapshot' => [],
    ]))->toThrow(QueryException::class);
});
