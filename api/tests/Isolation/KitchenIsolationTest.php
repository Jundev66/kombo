<?php

declare(strict_types=1);

/*
 * Las comandas de una cocina no existen para otra.
 *
 * Es el caso más fácil de romper sin darse cuenta: la pantalla de cocina se
 * conecta con un token de tablet, no con una sesión, y es la única del sistema
 * que consulta cada cinco segundos desde una máquina que vive en el local.
 */

use App\Models\Kitchen\KitchenTicketModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$sufijo}");
    $this->pizzeria = makeTenant("laesquina-{$sufijo}");

    foreach ([$this->arepera, $this->pizzeria] as $negocio) {
        actingForTenant($negocio);

        $pedido = OrderModel::create([
            'number' => 1, 'public_token' => Str::random(22), 'total_cents' => 300, 'placed_at' => now(),
        ]);

        $ticket = KitchenTicketModel::create([
            'order_id' => $pedido->id,
            'number' => 1,
            'status' => 'pending',
            'placed_at' => now(),
        ]);

        $ticket->items()->create([
            'name' => $negocio === $this->arepera ? 'Reina Pepiada' : 'Margarita',
            'quantity' => 1,
            'modifiers' => ['Sin cebolla'],
        ]);
    }
});

it('cada cocina ve sólo sus propias comandas', function (): void {
    actingForTenant($this->arepera);
    expect(KitchenTicketModel::with('items')->get()->pluck('items.0.name')->all())
        ->toBe(['Reina Pepiada']);

    actingForTenant($this->pizzeria);
    expect(KitchenTicketModel::with('items')->get()->pluck('items.0.name')->all())
        ->toBe(['Margarita']);
});

it('las líneas de una comanda ajena tampoco se ven', function (): void {
    // Si el aislamiento dependiera de recorrer la relación, aquí se filtraría
    // qué cocina el vecino.
    actingForTenant($this->arepera);

    expect(DB::table('kitchen_ticket_items')->count())->toBe(1);
});

it('no se puede colar una comanda en la cocina de otro', function (): void {
    actingForTenant($this->arepera);

    expect(fn () => DB::table('kitchen_tickets')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->pizzeria,
        'order_id' => (string) Str::uuid7(),
        'number' => 99,
        'status' => 'pending',
        'placed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('un pedido no puede tener dos comandas', function (): void {
    // Si el mismo pedido se confirmara dos veces —dos personas pulsando a la
    // vez— la base lo impide en vez de duplicar la comida.
    actingForTenant($this->arepera);

    $ticket = KitchenTicketModel::first();

    expect(fn () => KitchenTicketModel::create([
        'order_id' => $ticket?->order_id,
        'number' => 2,
        'status' => 'pending',
        'placed_at' => now(),
    ]))->toThrow(QueryException::class);
});
