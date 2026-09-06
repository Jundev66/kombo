<?php

declare(strict_types=1);

/*
 * The customers. The record fills itself in: in a food business nobody fills in
 * a customer form between two lunches.
 */

use App\Models\Catalog\ProductModel;
use App\Models\Customers\CustomerModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Orders\Application\UseCases\PlaceOrder;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$suffix}";
    $this->tenant = makeTenant($this->slug, plan: 'business');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'customers'] as $module) {
        enableModule($this->tenant, $module);
    }

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);
});

function orderAs(string $productId, string $phone, ?string $name = null, int $quantity = 1): void
{
    app(PlaceOrder::class)->execute(
        items: [['product_id' => $productId, 'quantity' => $quantity]],
        channel: 'portal',
        customerName: $name,
        customerPhone: $phone,
    );
}

function customers(string $slug, string $path = '', string $method = 'GET', array $body = []): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->json($method, urlFor($slug, "/api/v1/customers{$path}"), $body);
}

it('the record fills itself in with every order', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    orderAs($this->arepa->id, '04141234567', 'Ana');
    orderAs($this->arepa->id, '04141234567', 'Ana', quantity: 2);

    $data = customers($this->slug)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('Ana')
        ->and($data[0]['ordersCount'])->toBe(2)
        // 300 + 600: what they have spent, without summing the orders every time
        // the list is opened.
        ->and($data[0]['spentCents'])->toBe(900);
});

/*
 * The book is paginated, and says so.
 *
 * It used to be a bare `limit(100)`: a tenant with four hundred customers saw a
 * hundred, with nothing to hint at it and no way to reach the rest. Truncating
 * silently is the worst failure a list can have, because whoever looks at it
 * does not know anything is missing.
 */
it('the book says how many customers there are in total, not just how many fit', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    foreach (range(1, 3) as $i) {
        orderAs($this->arepa->id, '0414123456'.$i, "Cliente {$i}");
    }

    $response = customers($this->slug)->assertOk();

    expect($response->json('data'))->toHaveCount(3)
        ->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.page'))->toBe(1)
        ->and($response->json('meta.lastPage'))->toBe(1);
});

it('the second page brings different customers from the first', function (): void {
    /*
     * The `meta` being present is not enough: if `?page=2` returned the same
     * rows, "See more" would bring back what is already on screen.
     *
     * More than fit on a page are needed — 101 for a cap of 100 — inserted
     * directly rather than through `PlaceOrder`: what is tested here is the
     * list, and a hundred real orders would only make it slow.
     */
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    foreach (range(1, 101) as $i) {
        $phone = '0414'.str_pad((string) $i, 7, '0', STR_PAD_LEFT);

        CustomerModel::create([
            'phone' => $phone,
            'phone_hash' => CustomerModel::hashOf($phone),
            'name' => "Cliente {$i}",
            'orders_count' => 1,
            'spent_cents' => 300,
            // Different for each: the list orders by this, and with equal dates the
            // order between pages would be undefined — the test would pass or fail at
            // PostgreSQL's discretion.
            'last_order_at' => now()->subMinutes($i),
        ]);
    }

    $first = customers($this->slug)->assertOk();
    $second = customers($this->slug, '?page=2')->assertOk();

    expect($first->json('data'))->toHaveCount(100)
        ->and($first->json('meta.total'))->toBe(101)
        ->and($first->json('meta.lastPage'))->toBe(2)
        ->and($second->json('data'))->toHaveCount(1);

    $seen = array_column($first->json('data'), 'id');

    expect($seen)->not->toContain($second->json('data.0.id'));
});

it('with no phone number there is nobody to remember, and nothing breaks', function (): void {
    // At the counter most people do not leave one, and that is fine.
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    app(PlaceOrder::class)->execute(
        items: [['product_id' => $this->arepa->id, 'quantity' => 1]],
        channel: 'counter',
    );

    expect(customers($this->slug)->json('data'))->toBe([]);
});

it('the phone number is stored ENCRYPTED', function (): void {
    // A leaked list of phone numbers is exactly what a competitor would want.
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    orderAs($this->arepa->id, '04141234567', 'Ana');

    $rawBody = (string) DB::table('customers')->value('phone');

    expect($rawBody)->not->toContain('04141234567')
        ->and(CustomerModel::first()?->phone)->toBe('04141234567');
});

it('is searched by the whole number, even though it is encrypted', function (): void {
    /*
     * Laravel's encryption is not deterministic, so it cannot be matched by
     * equality. A hash keyed with the application key goes alongside: it finds
     * the customer without being able to read the number.
     */
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    orderAs($this->arepa->id, '04141234567', 'Ana');
    orderAs($this->arepa->id, '04149999999', 'Pedro');

    $found = customers($this->slug, '?search=04141234567')->json('data');

    expect($found)->toHaveCount(1)
        ->and($found[0]['name'])->toBe('Ana');
});

it('the number is found however it is typed', function (): void {
    // People copy it out of the chat with dashes and spaces.
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    orderAs($this->arepa->id, '0414-123 4567', 'Ana');

    expect(customers($this->slug, '?search=04141234567')->json('data'))->toHaveCount(1);
});

it('the record carries what they have ordered', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    orderAs($this->arepa->id, '04141234567', 'Ana');

    $id = customers($this->slug)->json('data.0.id');

    $record = customers($this->slug, "/{$id}")->assertOk()->json('data');

    expect($record['orders'])->toHaveCount(1)
        ->and($record['orders'][0]['totalCents'])->toBe(300);
});

it('the note is written by hand, and the system keeps the rest', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    orderAs($this->arepa->id, '04141234567', 'Ana');

    $id = customers($this->slug)->json('data.0.id');

    customers($this->slug, "/{$id}", 'PATCH', ['notes' => 'No le pongan cebolla'])
        ->assertOk()
        ->assertJsonPath('data.notes', 'No le pongan cebolla');
});

it('the name improves over time, and a nameless order does not overwrite it', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    orderAs($this->arepa->id, '04141234567', 'ana');
    orderAs($this->arepa->id, '04141234567', 'Ana Pérez');
    orderAs($this->arepa->id, '04141234567');

    expect(customers($this->slug)->json('data.0.name'))->toBe('Ana Pérez');
});

it('one tenant\'s customers are not another\'s', function (): void {
    $suffix = Str::lower(Str::random(6));
    $neighbour = makeTenant("vecino-{$suffix}", plan: 'business');

    actingForTenant($neighbour);
    foreach (['core', 'catalog', 'orders', 'customers'] as $module) {
        enableModule($neighbour, $module);
    }

    $pizza = ProductModel::create(['name' => 'Margarita', 'price_cents' => 900]);
    orderAs($pizza->id, '04140000000', 'Cliente del vecino');

    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    orderAs($this->arepa->id, '04141234567', 'Ana');

    $names = array_column(customers($this->slug)->json('data'), 'name');

    expect($names)->toBe(['Ana']);
});

it('a tenant without the module has no customers', function (): void {
    $suffix = Str::lower(Str::random(6));
    $slug = "sinclientes-{$suffix}";
    $other = makeTenant($slug, plan: 'starter');

    actingForTenant($other);
    foreach (['core', 'catalog', 'orders'] as $module) {
        enableModule($other, $module);
    }

    $pedro = makeUser($other, 'pedro@ejemplo.com', 'Pedro');
    giveRole($other, $pedro, 'owner');

    loginAs($slug, 'pedro@ejemplo.com');

    customers($slug)->assertNotFound();

    // And the listener keeps quiet: with no module, not a row is written.
    actingForTenant($other);

    $product = ProductModel::create(['name' => 'Algo', 'price_cents' => 100]);
    orderAs($product->id, '04141111111', 'Nadie');

    expect(DB::table('customers')->count())->toBe(0);
});

it('the kitchen does not see the customer book', function (): void {
    actingForTenant($this->tenant);

    $carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos');
    giveRole($this->tenant, $carlos, 'kitchen');

    loginAs($this->slug, 'carlos@ejemplo.com');

    customers($this->slug)->assertForbidden();
});
