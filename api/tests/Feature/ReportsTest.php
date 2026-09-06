<?php

declare(strict_types=1);

/*
 * The reports.
 *
 * Four questions a food business owner actually asks: how much did I sell, what
 * sells most, what time do people come in, and how do they pay me. The tests
 * are written against those answers, not against the shape of the JSON.
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
    $suffix = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$suffix}";
    $this->tenant = makeTenant($this->slug, plan: 'business');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'reports'] as $module) {
        enableModule($this->tenant, $module);
    }

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);
    $this->jugo = ProductModel::create(['name' => 'Jugo', 'price_cents' => 100]);
});

/**
 * A sold order: confirmed, and optionally paid.
 *
 * It goes the normal way — the same use cases the till uses — rather than a
 * hand-written `insert`: a test that seeds rows by hand can pass green with the
 * real flow broken.
 */
function sell(string $productId, int $quantity = 1, ?string $method = null, ?Carbon $when = null): OrderModel
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

    if ($when !== null) {
        // Both dates move: the report groups by `confirmed_at` and the hour of day
        // comes from `placed_at`. In UTC, which is what the column stores — a string
        // with no timezone is exactly the bug these tests pin.
        OrderModel::where('id', $order->id)->update([
            'confirmed_at' => $when->copy()->utc(),
            'placed_at' => $when->copy()->utc(),
        ]);
    }

    return $order->refresh();
}

function salesReport(string $slug, string $period = 'today'): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->getJson(urlFor($slug, "/api/v1/reports/sales?period={$period}"));
}

it('says how much was sold and how much came in, which are not the same', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    // One paid and one not: the delivery order paid on arrival.
    sell($this->arepa->id, 2, method: 'cash_usd');
    sell($this->arepa->id, 1);

    $summary = salesReport($this->slug)->assertOk()->json('data.summary');

    expect($summary['orders'])->toBe(2)
        ->and($summary['soldCents'])->toBe(900)
        ->and($summary['collectedCents'])->toBe(600)
        // The difference is what is still owed, one of the first things an owner
        // looks at.
        ->and($summary['outstandingCents'])->toBe(300)
        ->and($summary['averageTicketCents'])->toBe(450);
});

it('an unconfirmed order is NOT a sale', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    // It arrived, but the tenant has not accepted it yet.
    app(PlaceOrder::class)->execute(
        items: [['product_id' => $this->arepa->id, 'quantity' => 1]],
        channel: 'portal',
    );

    expect(salesReport($this->slug)->json('data.summary.orders'))->toBe(0);
});

it('cancelled does not count as sold, but how much is reported', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    $order = sell($this->arepa->id, 1, method: 'cash_usd');
    app(CancelOrder::class)
        ->execute((string) $order->id, 'El cliente se arrepintió');

    sell($this->jugo->id, 1, method: 'cash_usd');

    $summary = salesReport($this->slug)->json('data.summary');

    expect($summary['orders'])->toBe(1)
        ->and($summary['soldCents'])->toBe(100)
        // How many fell through is information, not noise: a lot of them means
        // something is wrong in the kitchen or in the price.
        ->and($summary['cancelled'])->toBe(1);
});

it('the average ticket with zero orders is zero, not a division by zero', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');

    expect(salesReport($this->slug)->json('data.summary.averageTicketCents'))->toBe(0);
});

it('says what sells most, highest first', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    sell($this->jugo->id, 10, method: 'cash_usd');   // 10 × 100 = 1,000
    sell($this->arepa->id, 5, method: 'cash_usd');   //  5 × 300 = 1,500

    $products = salesReport($this->slug)->json('data.byProduct');

    // Ordered by what it LEAVES, not by units sold: ten juices look busy and
    // earn less than five areperas.
    expect($products[0]['name'])->toBe('Reina Pepiada')
        ->and($products[0]['totalCents'])->toBe(1500)
        ->and($products[0]['quantity'])->toBe(5)
        ->and($products[1]['name'])->toBe('Jugo');
});

it('sales are grouped by the name it had at the time', function (): void {
    // If the owner renames and raises the price, they are two different offers,
    // and merging them would hide the effect being measured.
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    sell($this->arepa->id, 1, method: 'cash_usd');

    $this->arepa->update(['name' => 'Reina Pepiada GRANDE']);

    sell($this->arepa->id, 1, method: 'cash_usd');

    $names = array_column(salesReport($this->slug)->json('data.byProduct'), 'name');

    expect($names)->toContain('Reina Pepiada')
        ->toContain('Reina Pepiada GRANDE');
});

it('all 24 hours come back ALWAYS, with zero where there was nothing', function (): void {
    // A screen that had to fill the gaps would fill them differently from
    // whoever exports to a spreadsheet.
    loginAs($this->slug, 'maria@ejemplo.com');

    $hours = salesReport($this->slug)->json('data.byHour');

    expect($hours)->toHaveCount(24)
        ->and($hours[0]['hour'])->toBe(0)
        ->and($hours[23]['hour'])->toBe(23)
        ->and($hours[13]['orders'])->toBe(0);
});

it('the clock is the TENANT\'s, not the server\'s', function (): void {
    /*
     * The bug that puts the lunch peak at four in the afternoon: the container
     * runs in UTC and Caracas is four hours behind.
     *
     * With the clock FROZEN: depending on when the suite runs, it would pass in
     * the morning and fail at night — the worst kind of test.
     */
    test()->travelTo(Carbon::parse('2026-03-10 15:00', 'America/Caracas'));

    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    sell(
        $this->arepa->id,
        1,
        method: 'cash_usd',
        when: Carbon::parse('2026-03-10 12:00', 'America/Caracas'),
    );

    $hours = salesReport($this->slug)->json('data.byHour');

    expect($hours[12]['orders'])->toBe(1)
        // 16:00 UTC is midday in Caracas. Grouping by the server's hour would put
        // the peak here.
        ->and($hours[16]['orders'])->toBe(0);

    test()->travelBack();
});

it('says how they pay, counting only what is confirmed', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    sell($this->arepa->id, 1, method: 'cash_usd');
    sell($this->arepa->id, 2, method: 'cash_usd');

    // A mobile payment awaiting review is NOT money yet.
    $order = sell($this->jugo->id, 1);
    app(RegisterPayment::class)->execute(
        orderId: (string) $order->id,
        method: 'pago_movil',
        amountCents: 100,
    );

    $methods = salesReport($this->slug)->json('data.byPaymentMethod');

    expect($methods)->toHaveCount(1)
        ->and($methods[0]['method'])->toBe('cash_usd')
        ->and($methods[0]['totalCents'])->toBe(900);
});

it('says which channel each order came in through', function (): void {
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    sell($this->arepa->id, 1, method: 'cash_usd');

    $order = app(PlaceOrder::class)->execute(
        items: [['product_id' => $this->arepa->id, 'quantity' => 1]],
        channel: 'portal',
    );
    app(AdvanceOrder::class)->execute((string) $order->id, OrderStatus::Confirmed);

    $channels = collect(salesReport($this->slug)->json('data.byChannel'))->keyBy('channel');

    expect($channels['counter']['orders'])->toBe(1)
        ->and($channels['portal']['orders'])->toBe(1);
});

it('"yesterday" is yesterday in the tenant\'s timezone, and drags in none of today', function (): void {
    test()->travelTo(Carbon::parse('2026-03-10 15:00', 'America/Caracas'));

    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    sell($this->arepa->id, 1, method: 'cash_usd');
    sell(
        $this->jugo->id,
        1,
        method: 'cash_usd',
        when: Carbon::parse('2026-03-09 12:00', 'America/Caracas'),
    );

    expect(salesReport($this->slug, 'today')->json('data.summary.orders'))->toBe(1)
        ->and(salesReport($this->slug, 'yesterday')->json('data.summary.orders'))->toBe(1)
        ->and(salesReport($this->slug, 'yesterday')->json('data.summary.soldCents'))->toBe(100);

    test()->travelBack();
});

it('the month includes today\'s and yesterday\'s', function (): void {
    // Clock frozen mid-month, so there is no question of what happens when the
    // suite runs on the 1st — the kind of branch nobody tests.
    test()->travelTo(Carbon::parse('2026-03-10 15:00', 'America/Caracas'));

    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    sell($this->arepa->id, 1, method: 'cash_usd');
    sell(
        $this->jugo->id,
        1,
        method: 'cash_usd',
        when: Carbon::parse('2026-03-09 12:00', 'America/Caracas'),
    );

    expect(salesReport($this->slug, 'month')->json('data.summary.orders'))->toBe(2)
        // And it does not drag in last month's.
        ->and(salesReport($this->slug, 'month')->json('data.summary.soldCents'))->toBe(400);

    test()->travelBack();
});

it('whoever cannot see the sales does not see them', function (): void {
    // In some tenants the manager works all day and the owner would rather they
    // did not see the totals.
    actingForTenant($this->tenant);

    $carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos', pin: '4567');
    giveRole($this->tenant, $carlos, 'kitchen');

    loginAs($this->slug, 'carlos@ejemplo.com');

    salesReport($this->slug)->assertForbidden();
});

it('a tenant without reports has no reports', function (): void {
    $suffix = Str::lower(Str::random(6));
    $slug = "noreports-{$suffix}";
    $other = makeTenant($slug, plan: 'starter');

    actingForTenant($other);
    foreach (['core', 'catalog', 'orders'] as $module) {
        enableModule($other, $module);
    }

    $pedro = makeUser($other, 'pedro@ejemplo.com', 'Pedro');
    giveRole($other, $pedro, 'owner');

    loginAs($slug, 'pedro@ejemplo.com');

    // 404 and not 403: a missing module is contract information.
    salesReport($slug)->assertNotFound();
});

it('one tenant\'s reports do not see another\'s sales', function (): void {
    // RLS already guarantees it, but these queries carry hand-written joins and
    // `groupBy`s: exactly where a forgotten `where` goes unnoticed.
    $suffix = Str::lower(Str::random(6));
    $neighbour = makeTenant("vecino-{$suffix}", plan: 'business');

    actingForTenant($neighbour);
    foreach (['core', 'catalog', 'orders', 'reports'] as $module) {
        enableModule($neighbour, $module);
    }

    $pizza = ProductModel::create(['name' => 'Margarita', 'price_cents' => 900]);
    sell($pizza->id, 3, method: 'cash_usd');

    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    sell($this->arepa->id, 1, method: 'cash_usd');

    $data = salesReport($this->slug)->json('data');

    expect($data['summary']['soldCents'])->toBe(300)
        ->and(array_column($data['byProduct'], 'name'))->toBe(['Reina Pepiada']);
});

it('the report does not query the database once per product', function (): void {
    /*
     * N+1 is the defect that shows most on a modest machine, and a report is
     * where it slips in most easily: walking the orders and asking each for its
     * lines.
     *
     * The queries are counted, and required not to grow with the data.
     */
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    foreach (range(1, 10) as $i) {
        sell($this->arepa->id, 1, method: 'cash_usd');
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    salesReport($this->slug)->assertOk();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Five report blocks plus resolving the session and capabilities. What
    // matters is that it does not depend on how many orders there are.
    expect($queries)->toBeLessThan(20);
});

it('a nine-in-the-evening sale counts as TODAY\'s', function (): void {
    /*
     * The bug this pins: the range is computed in the tenant's timezone but
     * travels to the database as text WITHOUT one, and PostgreSQL reads it in
     * UTC. With Caracas four hours behind, sales after eight in the evening fell
     * outside "today" — and at eleven in the morning everything looked correct.
     */
    $tenant = $this->tenant;
    $slug = $this->slug;
    $arepa = $this->arepa;

    // Nine at night in Caracas: one in the morning the next day, in UTC.
    test()->travelTo(Carbon::parse('2026-03-10 21:00', 'America/Caracas'));

    loginAs($slug, 'maria@ejemplo.com');
    actingForTenant($tenant);

    sell($arepa->id, 1, method: 'cash_usd');

    expect(salesReport($slug, 'today')->json('data.summary.orders'))->toBe(1)
        ->and(salesReport($slug, 'yesterday')->json('data.summary.orders'))->toBe(0);

    test()->travelBack();
});

it('exporting gives a file that opens in a spreadsheet', function (): void {
    /*
     * This is what makes the suspension middleware's promise true: "reads and
     * exports". Without an export button it was a nice line in a comment.
     */
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    sell($this->arepa->id, 2, method: 'cash_usd');

    $response = test()->withHeaders(browsingAs($this->slug))
        ->get(urlFor($this->slug, '/api/v1/reports/export?period=month'))
        ->assertOk();

    $csv = $response->streamedContent();

    // The BOM: without it, Excel on Windows shows "Reina Pepiáda".
    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('numero;fecha;estado')
        ->and($csv)->toContain('2x Reina Pepiada')
        // Decimal comma: a Spanish spreadsheet reads "6.00" as six hundred.
        ->and($csv)->toContain('6,00');
});

it('a suspended tenant can still export what is theirs', function (): void {
    // Their orders are theirs even owing us three months. What is cut off is
    // carrying on for free, not access to their data.
    loginAs($this->slug, 'maria@ejemplo.com');
    actingForTenant($this->tenant);

    sell($this->arepa->id, 1, method: 'cash_usd');

    app(Subscriptions::class)
        ->setTenantStatus($this->tenant, TenantStatus::Suspended);

    test()->withHeaders(browsingAs($this->slug))
        ->get(urlFor($this->slug, '/api/v1/reports/export'))
        ->assertOk();
});

it('whoever cannot see the sales cannot export them either', function (): void {
    actingForTenant($this->tenant);

    $carlos = makeUser($this->tenant, 'carlos-export@ejemplo.com', 'Carlos');
    giveRole($this->tenant, $carlos, 'kitchen');

    loginAs($this->slug, 'carlos-export@ejemplo.com');

    test()->withHeaders(browsingAs($this->slug))
        ->get(urlFor($this->slug, '/api/v1/reports/export'))
        ->assertForbidden();
});
