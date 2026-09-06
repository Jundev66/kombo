<?php

declare(strict_types=1);

/*
 * The public portal: the only part of the system used WITHOUT a session, and
 * the most exposed — anybody on the internet can call it.
 *
 * The tests are written from that distrust: nothing the client sends decides a
 * price, no order is accepted that the tenant cannot fulfil, and a token opens
 * only its own order.
 */

use App\Models\Catalog\ProductModel;
use App\Models\Delivery\DeliveryZoneModel;
use App\Models\Kitchen\KitchenTicketModel;
use App\Models\Orders\OrderModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Portal\Application\UseCases\CancelExpiredOrders;
use Platform\Capabilities\CurrentCapabilities;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$suffix}";
    $this->tenant = makeTenant($this->slug, plan: 'business');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'kitchen', 'portal', 'delivery'] as $module) {
        enableModule($this->tenant, $module);
    }

    openNow($this->tenant);
    setting($this->tenant, 'portal.pago_movil_details', 'Banco · 0102 · V-12.345.678');

    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    $this->zone = DeliveryZoneModel::create([
        'name' => 'Los Palos Grandes',
        'fee_cents' => 200,
        'estimated_minutes' => 30,
    ]);
});

/** Leaves the tenant open 24 hours a day, every day. */
function openNow(string $tenantId): void
{
    for ($weekday = 0; $weekday <= 6; $weekday++) {
        DB::table('business_hours')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'weekday' => $weekday,
            'opens_at' => '00:00',
            'closes_at' => '23:59',
            'is_closed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function closed(string $tenantId): void
{
    DB::table('business_hours')->where('tenant_id', $tenantId)->update(['is_closed' => true]);
}

function setting(string $tenantId, string $key, string $value): void
{
    DB::table('tenant_settings')->updateOrInsert(
        ['tenant_id' => $tenantId, 'key' => $key],
        ['id' => (string) Str::uuid7(), 'value' => $value, 'created_at' => now(), 'updated_at' => now()],
    );

    app(CurrentCapabilities::class)->reset();
}

/** A portal call with no session, as somebody off the street would make it. */
function asCustomer(string $slug, string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders(['Accept' => 'application/json'])
        ->json($method, urlFor($slug, $path), $body);
}

/**
 * A payment photo, faked by TYPE rather than really generated.
 *
 * `UploadedFile::fake()->image()` needs the GD extension, and adding it to the
 * production image so a test can draw a square would be weight for nothing: the
 * system does not process images, it stores them.
 */
function fakeReceipt(string $name = 'pago.jpg', string $mime = 'image/jpeg'): UploadedFile
{
    return UploadedFile::fake()->create($name, 120, $mime);
}

function orderFromPortal(string $slug, array $body): TestResponse
{
    return asCustomer($slug, 'POST', '/api/v1/portal/orders', $body);
}

it('the shop and the menu are visible WITHOUT signing in', function (): void {
    // Asking somebody for an account to buy an arepa is the fastest way to make
    // them leave.
    asCustomer($this->slug, 'GET', '/api/v1/portal/shop')
        ->assertOk()
        ->assertJsonPath('data.slug', $this->slug)
        ->assertJsonPath('data.isOpen', true)
        ->assertJsonPath('data.zones.0.name', 'Los Palos Grandes');

    asCustomer($this->slug, 'GET', '/api/v1/portal/menu')
        ->assertOk()
        ->assertJsonPath('data.products.0.name', 'Reina Pepiada');
});

it('the public menu does NOT show what is off or sold out', function (): void {
    actingForTenant($this->tenant);

    ProductModel::create(['name' => 'Fuera de carta', 'price_cents' => 100, 'is_active' => false]);
    ProductModel::create([
        'name' => 'Se acabó', 'price_cents' => 100, 'track_stock' => true, 'stock_qty' => 0,
    ]);

    $names = asCustomer($this->slug, 'GET', '/api/v1/portal/menu')->json('data.products.*.name');

    expect($names)->toBe(['Reina Pepiada']);
});

it('a portal order comes in through the right channel and reaches the tenant', function (): void {
    $response = orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 2]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertCreated();

    $response->assertJsonPath('data.totalCents', 600)
        // Cash on delivery: it goes straight into the tenant's queue.
        ->assertJsonPath('data.status', 'placed')
        ->assertJsonPath('data.needsReceipt', false)
        // In the CUSTOMER's words, not the tenant's.
        ->assertJsonPath('data.statusLabel', 'Recibido, ya lo vemos');

    actingForTenant($this->tenant);

    $order = OrderModel::latest('created_at')->first();
    expect($order->channel)->toBe('portal')
        ->and($order->customer_phone)->toBe('04141234567');
});

it('the catalog sets the price, whatever the client sends', function (): void {
    // The most exposed door in the system: anybody can edit the request body
    // from the browser console.
    orderFromPortal($this->slug, [
        'items' => [[
            'product_id' => $this->arepa->id,
            'quantity' => 1,
            'unit_price_cents' => 1,
            'line_total_cents' => 1,
        ]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
        'total_cents' => 1,
        'delivery_fee_cents' => 0,
    ])->assertCreated()->assertJsonPath('data.totalCents', 300);
});

it('the delivery fee comes from the ZONE, not from the request', function (): void {
    orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'delivery',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
        'delivery_zone_id' => $this->zone->id,
        'delivery_address' => 'Cuarta avenida, casa 12',
        'delivery_fee_cents' => 0,
    ])->assertCreated()
        ->assertJsonPath('data.deliveryFeeCents', 200)
        ->assertJsonPath('data.totalCents', 500)
        // COPIED: the order has to say which neighbourhood it went to even if the
        // zone is switched off tomorrow.
        ->assertJsonPath('data.deliveryZoneName', 'Los Palos Grandes');
});

it('there is no delivery to a zone that does not exist', function (): void {
    orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'delivery',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
        'delivery_zone_id' => (string) Str::uuid7(),
        'delivery_address' => 'Por ahí',
    ])->assertStatus(422)->assertJsonValidationErrors('delivery_zone_id');
});

it('with no address there is nowhere to take it', function (): void {
    orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'delivery',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
        'delivery_zone_id' => $this->zone->id,
    ])->assertStatus(422)->assertJsonValidationErrors('delivery_address');
});

it('closed, not a single order is accepted', function (): void {
    // Accepting an order nobody will prepare is worse than refusing it: the
    // customer waits for food nobody is making.
    actingForTenant($this->tenant);
    closed($this->tenant);

    asCustomer($this->slug, 'GET', '/api/v1/portal/shop')->assertJsonPath('data.isOpen', false);

    orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertStatus(422);

    actingForTenant($this->tenant);
    expect(OrderModel::count())->toBe(0);
});

it('mobile payment leaves the order awaiting the receipt, with an expiry', function (): void {
    $response = orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'pago_movil',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertCreated();

    $response->assertJsonPath('data.status', 'pending_payment')
        ->assertJsonPath('data.needsReceipt', true);

    expect($response->json('data.expiresAt'))->not->toBeNull();

    // And it does NOT reach the kitchen: what has not been paid is not cooked.
    actingForTenant($this->tenant);
    expect(KitchenTicketModel::count())->toBe(0);
});

/*
 * Both deadlines, counted by the SERVER.
 *
 * The tracking screen shows them — how long they have waited, and how long
 * before the order cancels itself. Deriving them from an ISO date on the phone
 * would be wrong the moment the device clock is off, and the second one decides
 * whether somebody loses their order.
 */

it('tracking says how long they have waited and how long they have to pay', function (): void {
    $token = orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'pago_movil',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertCreated()->json('data.token');

    $tracking = asCustomer($this->slug, 'GET', "/api/v1/portal/orders/{$token}")->assertOk();

    expect($tracking->json('data.waitingSeconds'))->toBeInt()->toBeLessThan(60)
        ->and($tracking->json('data.expiresInSeconds'))->toBeInt()->toBeGreaterThan(0);
});

it('a deadline already past is zero seconds, never a negative number', function (): void {
    // "You have -3 minutes left" means nothing. The screen needs to be able to
    // say "it may be cancelled at any moment", and for that zero has to arrive
    // as zero.
    $token = orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'pago_movil',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertCreated()->json('data.token');

    actingForTenant($this->tenant);
    OrderModel::where('public_token', $token)->update(['expires_at' => now()->subHour()]);

    asCustomer($this->slug, 'GET', "/api/v1/portal/orders/{$token}")
        ->assertOk()
        ->assertJsonPath('data.expiresInSeconds', 0);
});

it('an order with no deadline does not invent one', function (): void {
    // In cash there is no receipt to wait for, so there is no countdown. `null`
    // and not zero: zero means "your time is up".
    $token = orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertCreated()->json('data.token');

    asCustomer($this->slug, 'GET', "/api/v1/portal/orders/{$token}")
        ->assertOk()
        ->assertJsonPath('data.expiresInSeconds', null);
});

it('with no mobile payment details, the portal neither offers nor accepts it', function (): void {
    // A pay button that does not say who to pay is a guaranteed phone call.
    actingForTenant($this->tenant);
    setting($this->tenant, 'portal.pago_movil_details', '');

    asCustomer($this->slug, 'GET', '/api/v1/portal/shop')
        ->assertJsonPath('data.paymentMethods', ['cash'])
        ->assertJsonPath('data.pagoMovilDetails', null);

    orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'pago_movil',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertStatus(422)->assertJsonValidationErrors('payment_method');
});

it('the delivery minimum is stated as a figure, not as a flat "no"', function (): void {
    actingForTenant($this->tenant);
    setting($this->tenant, 'delivery.minimum_order_cents', '1000');

    $response = orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'delivery',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
        'delivery_zone_id' => $this->zone->id,
        'delivery_address' => 'Cuarta avenida, casa 12',
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('$10,00');

    // The whole transaction rolled back: no order, and no gap in the sequence.
    actingForTenant($this->tenant);
    expect(OrderModel::count())->toBe(0);
});

it('the token follows its own order and no other', function (): void {
    $mineOne = orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->json('data.token');

    asCustomer($this->slug, 'GET', "/api/v1/portal/orders/{$mineOne}")
        ->assertOk()
        ->assertJsonPath('data.customerName', 'Ana Cliente');

    // 404 and not 403: a 403 would confirm that token exists somewhere.
    asCustomer($this->slug, 'GET', '/api/v1/portal/orders/'.Str::random(22))
        ->assertNotFound();
});

it('tracking does not show the customer what is not theirs', function (): void {
    // Whoever looks at this is somebody off the street with a link. No internal
    // notes, no who handled it, no other payments' references.
    $token = orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->json('data.token');

    $data = asCustomer($this->slug, 'GET', "/api/v1/portal/orders/{$token}")->json('data');

    expect($data)->not->toHaveKeys(['payments', 'createdBy', 'customerPhone', 'id']);
});

it('the receipt goes to a private disk and leaves the payment pending review', function (): void {
    Storage::fake('local');

    $token = orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'pago_movil',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->json('data.token');

    test()->withHeaders(['Accept' => 'application/json'])
        ->post(urlFor($this->slug, "/api/v1/portal/orders/{$token}/receipt"), [
            'receipt' => fakeReceipt(),
            'reference' => '998877',
        ])->assertOk();

    actingForTenant($this->tenant);

    $order = OrderModel::where('public_token', $token)->first();
    $payment = $order->payments()->first();

    expect($payment->status)->toBe('pending_review')
        ->and($payment->reference)->toBe('998877')
        // The path carries the tenant up front: removing a customer is deleting one
        // directory.
        ->and($payment->receipt_url)->toStartWith("receipts/{$this->tenant}/")
        // And the order KEEPS waiting: a photo arriving does not mean the money did.
        // Somebody at the tenant looks at their account and says yes.
        ->and($order->status->value)->toBe('pending_payment');

    Storage::disk('local')->assertExists($payment->receipt_url);
});

it('what is not a photo is not uploaded', function (): void {
    // The door accepts files from anybody on the internet. The type is
    // validated, not the extension: a `.jpg` that is something else fails.
    Storage::fake('local');

    $token = orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'pago_movil',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->json('data.token');

    test()->withHeaders(['Accept' => 'application/json'])
        ->post(urlFor($this->slug, "/api/v1/portal/orders/{$token}/receipt"), [
            'receipt' => fakeReceipt('receipt.jpg', 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('receipt');
});

it('no receipt is sent to an order that is no longer waiting for one', function (): void {
    Storage::fake('local');

    $token = orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->json('data.token');

    test()->withHeaders(['Accept' => 'application/json'])
        ->post(urlFor($this->slug, "/api/v1/portal/orders/{$token}/receipt"), [
            'receipt' => fakeReceipt(),
        ])->assertStatus(422);
});

it('expired orders close themselves, with their reason', function (): void {
    $token = orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'pago_movil',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->json('data.token');

    actingForTenant($this->tenant);

    // The order's clock is wound forward rather than waiting two hours.
    OrderModel::where('public_token', $token)->update(['expires_at' => now()->subMinute()]);

    $closedOnes = app(CancelExpiredOrders::class)->execute();

    expect($closedOnes)->toBe(1);

    $order = OrderModel::where('public_token', $token)->first();
    expect($order->status->value)->toBe('cancelled')
        ->and($order->cancellation_reason)->toContain('comprobante');
});

it('a paid and live order is closed by nobody', function (): void {
    orderFromPortal($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'service_type' => 'takeaway',
        'payment_method' => 'cash',
        'customer_name' => 'Ana Cliente',
        'customer_phone' => '04141234567',
    ])->assertCreated();

    actingForTenant($this->tenant);

    expect(app(CancelExpiredOrders::class)->execute())->toBe(0);
});

it('a tenant with NO portal has no portal: its routes do not exist', function (): void {
    $suffix = Str::lower(Str::random(6));
    $slug = "sinportal-{$suffix}";
    $other = makeTenant($slug, plan: 'business');

    actingForTenant($other);
    foreach (['core', 'catalog', 'orders'] as $module) {
        enableModule($other, $module);
    }

    // 404 and not 403: a module a tenant does not have is information about
    // their contract, not about anyone's permissions.
    asCustomer($slug, 'GET', '/api/v1/portal/shop')->assertNotFound();
    asCustomer($slug, 'GET', '/api/v1/portal/menu')->assertNotFound();
});
