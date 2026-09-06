<?php

declare(strict_types=1);

/*
 * One kitchen's tickets do not exist for another.
 *
 * The easiest case to break without noticing: the kitchen screen connects with
 * a tablet token rather than a session, and polls every five seconds from a
 * machine that lives on the shop floor.
 */

use App\Models\Kitchen\KitchenTicketModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$suffix}");
    $this->pizzeria = makeTenant("laesquina-{$suffix}");

    foreach ([$this->arepera, $this->pizzeria] as $tenant) {
        actingForTenant($tenant);

        $order = OrderModel::create([
            'number' => 1, 'public_token' => Str::random(22), 'total_cents' => 300, 'placed_at' => now(),
        ]);

        $ticket = KitchenTicketModel::create([
            'order_id' => $order->id,
            'number' => 1,
            'status' => 'pending',
            'placed_at' => now(),
        ]);

        $ticket->items()->create([
            'name' => $tenant === $this->arepera ? 'Reina Pepiada' : 'Margarita',
            'quantity' => 1,
            'modifiers' => ['Sin cebolla'],
        ]);
    }
});

it('each kitchen sees only its own tickets', function (): void {
    actingForTenant($this->arepera);
    expect(KitchenTicketModel::with('items')->get()->pluck('items.0.name')->all())
        ->toBe(['Reina Pepiada']);

    actingForTenant($this->pizzeria);
    expect(KitchenTicketModel::with('items')->get()->pluck('items.0.name')->all())
        ->toBe(['Margarita']);
});

it('another tenant\'s ticket lines are not visible either', function (): void {
    // If isolation depended on walking the relation, this would leak what the
    // neighbour is cooking.
    actingForTenant($this->arepera);

    expect(DB::table('kitchen_ticket_items')->count())->toBe(1);
});

it('a ticket cannot be slipped into another tenant\'s kitchen', function (): void {
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

it('an order cannot have two tickets', function (): void {
    // If the same order were confirmed twice — two people tapping at once — the
    // database stops it rather than duplicating the food.
    actingForTenant($this->arepera);

    $ticket = KitchenTicketModel::first();

    expect(fn () => KitchenTicketModel::create([
        'order_id' => $ticket?->order_id,
        'number' => 2,
        'status' => 'pending',
        'placed_at' => now(),
    ]))->toThrow(QueryException::class);
});
