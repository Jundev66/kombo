<?php

declare(strict_types=1);

/*
 * One tenant's notes do not exist for another — nor does their sequence.
 *
 * More than privacy: shared numbering would let the neighbour eat this tenant's
 * numbers, leaving gaps nobody could explain.
 */

use App\Models\Documents\DeliveryNoteModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$suffix}");
    $this->pizzeria = makeTenant("laesquina-{$suffix}");

    $this->orders = [];

    foreach ([$this->arepera, $this->pizzeria] as $tenant) {
        actingForTenant($tenant);

        $order = OrderModel::create([
            'number' => 1, 'public_token' => Str::random(22), 'total_cents' => 300, 'placed_at' => now(),
        ]);

        $this->orders[$tenant] = (string) $order->id;

        DeliveryNoteModel::create([
            'order_id' => $order->id,
            'series' => 'A',
            'number' => 1,
            'issued_at' => now(),
            'customer_name' => $tenant === $this->arepera ? 'Cliente del Sazón' : 'Cliente de La Esquina',
            'subtotal_cents' => 300,
            'total_cents' => 300,
            'currency' => 'USD',
            'snapshot' => ['title' => 'NOTA DE ENTREGA'],
        ]);
    }
});

it('each tenant sees only its own delivery notes', function (): void {
    actingForTenant($this->arepera);
    expect(DeliveryNoteModel::pluck('customer_name')->all())->toBe(['Cliente del Sazón']);

    actingForTenant($this->pizzeria);
    expect(DeliveryNoteModel::pluck('customer_name')->all())->toBe(['Cliente de La Esquina']);
});

it('the sequence is per tenant: both have their own A-000001', function (): void {
    // Two tenants with the same series and number do not collide, because the
    // unique index leads with `tenant_id`.
    withoutTenant();

    expect(DB::table('delivery_notes')->count())->toBe(0);
});

it('a note cannot be issued in another tenant\'s name', function (): void {
    actingForTenant($this->arepera);

    expect(fn () => DB::table('delivery_notes')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->pizzeria,
        'order_id' => $this->orders[$this->pizzeria],
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

it('an order cannot have two notes', function (): void {
    // What stops a double tap on "Charge" producing two documents with two
    // different numbers for the same food.
    actingForTenant($this->arepera);

    expect(fn () => DeliveryNoteModel::create([
        'order_id' => $this->orders[$this->arepera],
        'series' => 'A',
        'number' => 2,
        'issued_at' => now(),
        'subtotal_cents' => 300,
        'total_cents' => 300,
        'currency' => 'USD',
        'snapshot' => [],
    ]))->toThrow(QueryException::class);
});

it('the same number does not repeat within a series', function (): void {
    actingForTenant($this->arepera);

    $other = OrderModel::create([
        'number' => 2, 'public_token' => Str::random(22), 'total_cents' => 300, 'placed_at' => now(),
    ]);

    expect(fn () => DeliveryNoteModel::create([
        'order_id' => $other->id,
        'series' => 'A',
        'number' => 1,   // already issued
        'issued_at' => now(),
        'subtotal_cents' => 300,
        'total_cents' => 300,
        'currency' => 'USD',
        'snapshot' => [],
    ]))->toThrow(QueryException::class);
});
