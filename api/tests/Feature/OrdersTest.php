<?php

declare(strict_types=1);

/*
 * Orders over HTTP: that the server sets the price, that the whole journey
 * works, and that two people touching the same order do not overwrite each
 * other.
 */

use App\Models\Catalog\ModifierGroupModel;
use App\Models\Catalog\ProductModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$suffix}";
    $this->tenant = makeTenant($this->slug, plan: 'business');

    actingForTenant($this->tenant);
    enableModule($this->tenant, 'core');
    enableModule($this->tenant, 'catalog');
    enableModule($this->tenant, 'orders');

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    $group = ModifierGroupModel::create(['name' => 'Extras', 'min_choices' => 0, 'max_choices' => 3]);
    $this->extraQueso = $group->modifiers()->create(['name' => 'Extra queso', 'price_delta_cents' => 50]);
    $this->sinQueso = $group->modifiers()->create(['name' => 'Sin queso', 'price_delta_cents' => -50]);
});

function placeOrder(string $slug, array $body): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, '/api/v1/orders'), $body);
}

function advance(string $slug, string $id, string $status): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, "/api/v1/orders/{$id}/advance"), ['status' => $status]);
}

it('the SERVER sets the price, whatever the client sends', function (): void {
    // The rule that governs this module. Without it a tampered browser would
    // charge itself whatever it liked, and it would only show at month end.
    loginAs($this->slug, 'maria@ejemplo.com');

    $response = placeOrder($this->slug, [
        'items' => [[
            'product_id' => $this->arepa->id,
            'quantity' => 2,
            // A malicious client sending its own price. Ignored: the endpoint does not
            // even accept it.
            'price_cents' => 1,
            'unit_price_cents' => 1,
        ]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.totalCents', 600)
        ->assertJsonPath('data.items.0.unitPriceCents', 300);
});

it('add-ons are charged per unit', function (): void {
    // Two arepas with extra cheese carry the extra twice.
    loginAs($this->slug, 'maria@ejemplo.com');

    placeOrder($this->slug, [
        'items' => [[
            'product_id' => $this->arepa->id,
            'quantity' => 2,
            'modifier_ids' => [$this->extraQueso->id],
        ]],
    ])
        ->assertCreated()
        ->assertJsonPath('data.totalCents', 700)
        ->assertJsonPath('data.items.0.modifiers.0.name', 'Extra queso');
});

it('an add-on can take money off', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    placeOrder($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1, 'modifier_ids' => [$this->sinQueso->id]]],
    ])->assertCreated()->assertJsonPath('data.totalCents', 250);
});

it('name and price are COPIED: changing the menu does not move an old order', function (): void {
    // A ticket from six months ago must say what it said when it was printed.
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = placeOrder($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
    ])->json('data.id');

    actingForTenant($this->tenant);
    $this->arepa->update(['name' => 'Reina Pepiada Especial', 'price_cents' => 900]);

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, "/api/v1/orders/{$id}"))
        ->assertJsonPath('data.items.0.name', 'Reina Pepiada')
        ->assertJsonPath('data.totalCents', 300);
});

it('something no longer on the menu cannot be ordered', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    actingForTenant($this->tenant);
    $this->arepa->update(['is_active' => false]);

    placeOrder($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])
        ->assertStatus(422);
});

it('the whole journey: placed → confirmed → ready → delivered', function (): void {
    // This is the phase's exit criterion.
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = placeOrder($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])
        ->assertJsonPath('data.status', 'placed')
        ->json('data.id');

    advance($this->slug, $id, 'confirmed')->assertOk()->assertJsonPath('data.isInKitchen', true);
    advance($this->slug, $id, 'preparing')->assertOk();
    advance($this->slug, $id, 'ready')->assertOk()->assertJsonPath('data.isInKitchen', false);
    advance($this->slug, $id, 'delivered')
        ->assertOk()
        ->assertJsonPath('data.status', 'delivered')
        ->assertJsonPath('data.isOpen', false);
});

it('the kitchen cannot be skipped', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = placeOrder($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');

    advance($this->slug, $id, 'confirmed')->assertOk();

    // 409 and not 422: the data is fine, that jump simply does not exist. The
    // message asks for a reload.
    advance($this->slug, $id, 'delivered')->assertStatus(409);
});

it('optimistic locking rejects whoever arrives with the old version', function (): void {
    // The till and the kitchen screen look at the same order and tap almost at
    // once every day. Without this, whoever saves second overwrites the first
    // and NOBODY finds out.
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = placeOrder($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');

    actingForTenant($this->tenant);
    $initialVersion = (int) DB::table('orders')->where('id', $id)->value('state_version');

    // First person: confirms. The version advances.
    advance($this->slug, $id, 'confirmed')->assertOk();

    actingForTenant($this->tenant);
    expect((int) DB::table('orders')->where('id', $id)->value('state_version'))->toBe($initialVersion + 1);

    // Second person: their screen was loaded BEFORE, so their write carries the
    // old version. Exactly the UPDATE the use case makes, affecting no rows —
    // which is how it learns it arrived late.
    $affected = DB::table('orders')
        ->where('id', $id)
        ->where('state_version', $initialVersion)
        ->update(['status' => 'cancelled']);

    expect($affected)->toBe(0);

    // And the order stays where the first person left it.
    expect(DB::table('orders')->where('id', $id)->value('status'))->toBe('confirmed');
});

it('repeating the same step is not an error', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = placeOrder($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');

    advance($this->slug, $id, 'confirmed')->assertOk();
    advance($this->slug, $id, 'confirmed')->assertOk();
});

it('payment comes in a mix: part cash and the rest by mobile transfer', function (): void {
    // How payment works here. One `payment_method` column cannot represent it,
    // and the cashier ends up using the notes field.
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = placeOrder($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 2]]])
        ->json('data.id');   // total: 600

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/orders/{$id}/payments"), [
            'method' => 'cash_usd', 'amount_cents' => 300,
        ])
        ->assertOk()
        ->assertJsonPath('data.paidCents', 300)
        ->assertJsonPath('data.paymentStatus', 'partial')
        ->assertJsonPath('data.outstandingCents', 300);

    // Mobile payment arrives pending review: there is no banking API to ask,
    // somebody looks at the receipt.
    $payment = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/orders/{$id}/payments"), [
            'method' => 'pago_movil', 'amount_cents' => 300, 'reference' => '004512',
        ])->assertOk();

    // They still owe 300: mobile payment does not count until confirmed.
    $payment->assertJsonPath('data.outstandingCents', 300);

    $paymentId = collect($payment->json('data.payments'))->firstWhere('method', 'pago_movil')['id'];

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/orders/{$id}/payments/{$paymentId}/confirm"))
        ->assertOk()
        ->assertJsonPath('data.paidCents', 600)
        ->assertJsonPath('data.paymentStatus', 'paid')
        ->assertJsonPath('data.outstandingCents', 0);
});

it('cancelling requires a reason and lands in the audit log', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = placeOrder($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/orders/{$id}/cancel"), [])
        ->assertStatus(422);

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/orders/{$id}/cancel"), [
            'reason' => 'El cliente se arrepintió',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    actingForTenant($this->tenant);

    $entry = DB::table('audit_log')->where('action', 'orders.cancelled')->first();
    expect($entry?->reason)->toBe('El cliente se arrepintió');
});

it('a delivered order cannot be cancelled', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = placeOrder($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');

    foreach (['confirmed', 'preparing', 'ready', 'delivered'] as $status) {
        advance($this->slug, $id, $status)->assertOk();
    }

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/orders/{$id}/cancel"), ['reason' => 'Ups'])
        ->assertStatus(409);
});

it('the board shows only live orders, oldest first', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $old = placeOrder($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');
    $newOne = placeOrder($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])->json('data.id');

    foreach (['confirmed', 'preparing', 'ready', 'delivered'] as $status) {
        advance($this->slug, $newOne, $status)->assertOk();
    }

    $board = $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/orders'))
        ->assertOk()
        ->json('data');

    expect(collect($board)->pluck('id')->all())->toBe([$old]);
});

it('every order carries its number, and it never repeats', function (): void {
    // It is the number shouted across the counter: two orders with the same
    // number are two customers collecting each other's food.
    loginAs($this->slug, 'maria@ejemplo.com');

    $numbers = [];
    for ($i = 0; $i < 3; $i++) {
        $numbers[] = placeOrder($this->slug, [
            'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        ])->json('data.number');
    }

    expect($numbers)->toBe([1, 2, 3]);
});

it('the kitchen cannot take orders', function (): void {
    $carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos', pin: '4567');
    giveRole($this->tenant, $carlos, 'kitchen');

    loginAs($this->slug, 'carlos@ejemplo.com');

    placeOrder($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])
        ->assertForbidden();
});

it('the rate of the day is frozen into the order', function (): void {
    // Without this, a March order's bolívar amount would change every morning.
    actingForTenant($this->tenant);
    DB::table('exchange_rates')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant,
        'rate' => '36.500000',
        'source' => 'custom',
        'effective_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    loginAs($this->slug, 'maria@ejemplo.com');

    placeOrder($this->slug, ['items' => [['product_id' => $this->arepa->id, 'quantity' => 1]]])
        ->assertCreated()
        ->assertJsonPath('data.exchangeRate', 36.5);
});
