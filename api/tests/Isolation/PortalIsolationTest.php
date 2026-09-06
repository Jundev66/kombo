<?php

declare(strict_types=1);

/*
 * The portal is the door that asks for no password, so it is where isolation
 * matters most.
 *
 * No session to check and no permission to look at: the only things separating
 * one tenant's order from another's are the subdomain and RLS.
 */

use App\Models\Catalog\ProductModel;
use App\Models\Delivery\DeliveryZoneModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$suffix}");
    $this->pizzeria = makeTenant("laesquina-{$suffix}");

    $this->tokens = [];

    foreach ([$this->arepera => 'Reina Pepiada', $this->pizzeria => 'Margarita'] as $tenant => $dish) {
        actingForTenant($tenant);

        ProductModel::create(['name' => $dish, 'price_cents' => 300]);

        DeliveryZoneModel::create(['name' => "Zona de {$dish}", 'fee_cents' => 100]);

        $order = OrderModel::create([
            'number' => 1,
            'public_token' => Str::random(22),
            'total_cents' => 300,
            'channel' => 'portal',
            'customer_name' => "Cliente de {$dish}",
            'placed_at' => now(),
        ]);

        $this->tokens[$tenant] = $order->public_token;
    }
});

it('one tenant\'s menu does not appear in another\'s', function (): void {
    actingForTenant($this->arepera);
    expect(ProductModel::pluck('name')->all())->toBe(['Reina Pepiada']);

    actingForTenant($this->pizzeria);
    expect(ProductModel::pluck('name')->all())->toBe(['Margarita']);
});

it('delivery zones do not cross either', function (): void {
    actingForTenant($this->arepera);
    expect(DeliveryZoneModel::count())->toBe(1);

    actingForTenant($this->pizzeria);
    expect(DeliveryZoneModel::count())->toBe(1);
});

it('an order token does NOT open that order from another tenant', function (): void {
    /*
     * The obvious attack: somebody pastes the link to their order at one
     * tenant into another's address. Without RLS the token lookup would find it
     * anyway — the token is unique across the table — and show one tenant the
     * other's order, customer name and address.
     */
    $otherTenant = $this->tokens[$this->arepera];

    actingForTenant($this->pizzeria);

    expect(OrderModel::where('public_token', $otherTenant)->first())->toBeNull();

    // And from their own it does, so the test says something when it passes.
    actingForTenant($this->arepera);
    expect(OrderModel::where('public_token', $otherTenant)->first())->not->toBeNull();
});

it('with no tenant in context, the portal sees no orders', function (): void {
    // The failure mode that matters: a connection returned to the pool without
    // being cleaned must not become one that sees everything.
    withoutTenant();

    expect(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('delivery_zones')->count())->toBe(0);
});

it('an order cannot be slipped into another tenant\'s portal', function (): void {
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

it('an order cannot be delivered to another tenant\'s zone', function (): void {
    // The foreign key is COMPOSITE: (tenant_id, delivery_zone_id). With a
    // simple one this row would be perfectly valid to the database, and found
    // out months later when a report does not add up.
    actingForTenant($this->pizzeria);
    $otherTenantZone = DeliveryZoneModel::first()->id;

    actingForTenant($this->arepera);

    expect(fn () => OrderModel::create([
        'number' => 2,
        'public_token' => Str::random(22),
        'total_cents' => 300,
        'delivery_zone_id' => $otherTenantZone,
        'placed_at' => now(),
    ]))->toThrow(QueryException::class);
});
