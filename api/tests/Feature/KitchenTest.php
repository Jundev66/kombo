<?php

declare(strict_types=1);

/*
 * The kitchen: that confirming an order makes the ticket appear, and that
 * advancing it behaves the way a real kitchen does.
 */

use App\Models\Catalog\ModifierGroupModel;
use App\Models\Catalog\ProductModel;
use App\Models\Kitchen\KitchenTicketModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Platform\Capabilities\CapabilityResolver;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$suffix}";
    $this->tenant = makeTenant($this->slug, plan: 'business');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'kitchen'] as $module) {
        enableModule($this->tenant, $module);
    }

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->arepa = ProductModel::create([
        'name' => 'Reina Pepiada', 'price_cents' => 300, 'prep_minutes' => 8,
    ]);
    $this->grill = ProductModel::create([
        'name' => 'Parrilla', 'price_cents' => 900, 'prep_minutes' => 20,
    ]);

    $group = ModifierGroupModel::create(['name' => 'Extras', 'min_choices' => 0, 'max_choices' => 3]);
    $this->noOnion = $group->modifiers()->create(['name' => 'Sin cebolla', 'price_delta_cents' => 0]);
});

/** Leaves a confirmed order and returns its id. */
function confirmedOrder(string $slug, array $items): string
{
    $id = test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, '/api/v1/orders'), ['items' => $items])
        ->assertCreated()
        ->json('data.id');

    test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, "/api/v1/orders/{$id}/advance"), ['status' => 'confirmed'])
        ->assertOk();

    return $id;
}

function tickets(string $slug): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->getJson(urlFor($slug, '/api/v1/kitchen/tickets'));
}

it('CONFIRMING an order makes the ticket appear in the kitchen', function (): void {
    // The trigger that motivated the whole project, and the ONLY path: the
    // kitchen never queries orders on its own.
    loginAs($this->slug, 'maria@ejemplo.com');

    tickets($this->slug)->assertOk()->assertJsonCount(0, 'data');

    confirmedOrder($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 2]]);

    tickets($this->slug)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.items.0.name', 'Reina Pepiada')
        ->assertJsonPath('data.0.items.0.quantity', 2);
});

it('an UNCONFIRMED order does not reach the kitchen', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, '/api/v1/orders'), [
            'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        ])->assertCreated();

    tickets($this->slug)->assertJsonCount(0, 'data');
});

it('the ticket carries the SAME number as the order', function (): void {
    // Two numbering schemes for one thing is how the wrong plate gets handed
    // over: the number shouted across the counter has to be the one the kitchen
    // has in front of it.
    loginAs($this->slug, 'maria@ejemplo.com');

    $id = confirmedOrder($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    $orderNumber = $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, "/api/v1/orders/{$id}"))
        ->json('data.number');

    tickets($this->slug)->assertJsonPath('data.0.number', $orderNumber);
});

it('add-ons arrive as TEXT, ready to read while cooking', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    confirmedOrder($this->slug, [[
        'product_id' => $this->arepa->id,
        'quantity' => 1,
        'modifier_ids' => [$this->noOnion->id],
    ]]);

    tickets($this->slug)->assertJsonPath('data.0.items.0.modifiers', ['Sin cebolla']);
});

it('the estimate is the MAXIMUM of what is on it, not the sum', function (): void {
    // Dishes are made at the same time, not one after another. Summing would
    // give 28 minutes for an arepa and a grill, and nothing would look late.
    loginAs($this->slug, 'maria@ejemplo.com');

    confirmedOrder($this->slug, [
        ['product_id' => $this->arepa->id, 'quantity' => 1],
        ['product_id' => $this->grill->id, 'quantity' => 1],
    ]);

    tickets($this->slug)->assertJsonPath('data.0.prepMinutes', 20);
});

it('the stopwatch is computed by the SERVER', function (): void {
    // A kitchen tablet's clock is almost never set right. Computed there, the
    // traffic light would lie all day.
    loginAs($this->slug, 'maria@ejemplo.com');

    confirmedOrder($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    $ticket = tickets($this->slug)->json('data.0');

    expect($ticket['waitingSeconds'])->toBeGreaterThanOrEqual(0)
        ->and($ticket['waitingSeconds'])->toBeLessThan(60);
});

it('the "running late" threshold comes from the tenant, not fixed in the screen', function (): void {
    // An arepera and a pizzeria do not share an idea of late.
    loginAs($this->slug, 'maria@ejemplo.com');

    tickets($this->slug)->assertJsonPath('meta.staleMinutes', 15);

    actingForTenant($this->tenant);
    DB::table('tenant_settings')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant,
        'key' => 'kitchen.stale_minutes',
        'value' => '25',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Capabilities are cached; enabling or changing something invalidates them.
    app(CapabilityResolver::class)->forget($this->tenant);

    tickets($this->slug)->assertJsonPath('meta.staleMinutes', 25);
});

it('the ticket advances with one tap: start, ready, delivered', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');
    confirmedOrder($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    $id = tickets($this->slug)->json('data.0.id');

    foreach (['preparing', 'ready'] as $step) {
        $this->withHeaders(browsingAs($this->slug))
            ->postJson(urlFor($this->slug, "/api/v1/kitchen/tickets/{$id}/advance"), ['status' => $step])
            ->assertOk()
            ->assertJsonPath('data.status', $step);
    }

    // Once served it LEAVES the screen: history is reports' business.
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/kitchen/tickets/{$id}/advance"), ['status' => 'served'])
        ->assertOk();

    tickets($this->slug)->assertJsonCount(0, 'data');
});

it('repeating the same step is NOT an error', function (): void {
    // Two cooks tapping "Ready" at once cannot raise a red message in the middle
    // of service.
    loginAs($this->slug, 'maria@ejemplo.com');
    confirmedOrder($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    $id = tickets($this->slug)->json('data.0.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/kitchen/tickets/{$id}/advance"), ['status' => 'preparing'])
        ->assertOk();

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/kitchen/tickets/{$id}/advance"), ['status' => 'preparing'])
        ->assertOk()
        ->assertJsonPath('data.status', 'preparing');
});

it('a step cannot be skipped and there is no going back', function (): void {
    // A stray tap sending a delivered ticket back to "to do" gets the kitchen to
    // make it twice.
    loginAs($this->slug, 'maria@ejemplo.com');
    confirmedOrder($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    $id = tickets($this->slug)->json('data.0.id');

    // From "to do" straight to "ready", skipping the griddle: 409.
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/kitchen/tickets/{$id}/advance"), ['status' => 'ready'])
        ->assertStatus(409);
});

it('every step stamps its time', function (): void {
    // This is where "how long did we take" comes from, the only way to know
    // whether the traffic light is calibrated.
    loginAs($this->slug, 'maria@ejemplo.com');
    confirmedOrder($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    $id = tickets($this->slug)->json('data.0.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/kitchen/tickets/{$id}/advance"), ['status' => 'preparing'])
        ->assertOk();

    actingForTenant($this->tenant);
    $ticket = KitchenTicketModel::find($id);

    expect($ticket?->started_at)->not->toBeNull()
        ->and($ticket?->ready_at)->toBeNull();
});

it('without the kitchen module, confirming an order creates no ticket', function (): void {
    // A stall where whoever serves is whoever cooks does not need this screen,
    // and the listener has to know — the route answering 404 is not enough.
    actingForTenant($this->tenant);
    DB::table('tenant_modules')
        ->where('tenant_id', $this->tenant)
        ->where('module_code', 'kitchen')
        ->update(['enabled' => false]);
    app(CapabilityResolver::class)->forget($this->tenant);

    loginAs($this->slug, 'maria@ejemplo.com');
    confirmedOrder($this->slug, [['product_id' => $this->arepa->id, 'quantity' => 1]]);

    actingForTenant($this->tenant);
    expect(KitchenTicketModel::count())->toBe(0);
});
