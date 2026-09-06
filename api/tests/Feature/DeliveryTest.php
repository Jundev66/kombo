<?php

declare(strict_types=1);

/*
 * The courier.
 *
 * The role and its permissions existed from the first phase; what was missing
 * was any way to use them. Two lists: ready to go out, and what I am carrying.
 */

use App\Models\Catalog\ProductModel;
use App\Models\Delivery\DeliveryZoneModel;
use App\Models\Orders\OrderModel;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Orders\Application\UseCases\AdvanceOrder;
use Modules\Orders\Application\UseCases\PlaceOrder;
use Modules\Orders\Application\UseCases\RegisterPayment;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Modules\Orders\Domain\ValueObjects\ServiceType;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$suffix}";
    $this->tenant = makeTenant($this->slug, plan: 'business');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'kitchen', 'delivery'] as $module) {
        enableModule($this->tenant, $module);
    }

    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->pedro = makeUser($this->tenant, 'pedro@ejemplo.com', 'Pedro');
    giveRole($this->tenant, $this->pedro, 'courier');

    $this->luis = makeUser($this->tenant, 'luis@ejemplo.com', 'Luis');
    giveRole($this->tenant, $this->luis, 'courier');

    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);
    $this->zone = DeliveryZoneModel::create(['name' => 'Los Palos Grandes', 'fee_cents' => 200]);
});

/** A delivery order, ready to go out. */
function takeaway(string $productId, string $zoneId, string $zoneName, bool $paid = false): OrderModel
{
    $order = app(PlaceOrder::class)->execute(
        items: [['product_id' => $productId, 'quantity' => 1]],
        serviceType: ServiceType::Delivery,
        channel: 'portal',
        customerName: 'Ana',
        customerPhone: '04141234567',
        deliveryAddress: 'Cuarta avenida, casa 12',
        deliveryFeeCents: 200,
        deliveryZoneId: $zoneId,
        deliveryZoneName: $zoneName,
    );

    if ($paid) {
        app(RegisterPayment::class)->execute(
            orderId: (string) $order->id,
            method: 'cash_usd',
            amountCents: (int) $order->total_cents,
        );
    }

    foreach ([OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready] as $step) {
        $order = app(AdvanceOrder::class)->execute((string) $order->id, $step);
    }

    return $order;
}

function deliveries(string $slug, string $path = '', string $method = 'GET'): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->json($method, urlFor($slug, "/api/v1/delivery/orders{$path}"));
}

it('the courier sees what is ready to go out, with what to collect', function (): void {
    actingForTenant($this->tenant);
    takeaway($this->arepa->id, $this->zone->id, 'Los Palos Grandes');

    loginAs($this->slug, 'pedro@ejemplo.com');

    $data = deliveries($this->slug)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['address'])->toBe('Cuarta avenida, casa 12')
        // The phone number is visible: it is what you call with when you cannot find
        // the house, which is half of all deliveries.
        ->and($data[0]['customerPhone'])->toBe('04141234567')
        // 300 plus 200 delivery, and nobody has paid.
        ->and($data[0]['toCollectCents'])->toBe(500)
        ->and($data[0]['isMine'])->toBeFalse();
});

it('what has already been paid is not collected on arrival', function (): void {
    actingForTenant($this->tenant);
    takeaway($this->arepa->id, $this->zone->id, 'Los Palos Grandes', paid: true);

    loginAs($this->slug, 'pedro@ejemplo.com');

    expect(deliveries($this->slug)->json('data.0.toCollectCents'))->toBe(0);
});

it('first to take it gets it', function (): void {
    /*
     * Two couriers tapping "I'll take it" at once really happens at a kitchen
     * door. Without the condition in the UPDATE, both would leave with the same
     * order.
     */
    actingForTenant($this->tenant);
    $order = takeaway($this->arepa->id, $this->zone->id, 'Los Palos Grandes');

    loginAs($this->slug, 'pedro@ejemplo.com');
    deliveries($this->slug, "/{$order->id}/take", 'POST')->assertOk();

    loginAs($this->slug, 'luis@ejemplo.com');
    $response = deliveries($this->slug, "/{$order->id}/take", 'POST')->assertStatus(422);

    expect($response->json('message'))->toContain('ya se lo llevó otra persona');

    actingForTenant($this->tenant);
    expect(OrderModel::find($order->id)->courier_name)->toBe('Pedro');
});

it('everyone sees their own, not anybody else\'s', function (): void {
    // A list with three people's deliveries is a list where nobody finds their
    // own.
    actingForTenant($this->tenant);

    $forLuis = takeaway($this->arepa->id, $this->zone->id, 'Los Palos Grandes');
    $free = takeaway($this->arepa->id, $this->zone->id, 'Los Palos Grandes');

    loginAs($this->slug, 'luis@ejemplo.com');
    deliveries($this->slug, "/{$forLuis->id}/take", 'POST')->assertOk();

    loginAs($this->slug, 'pedro@ejemplo.com');

    $numbers = array_column(deliveries($this->slug)->json('data'), 'number');

    expect($numbers)->toBe([$free->number]);
});

it('marking somebody else\'s as delivered is not possible', function (): void {
    // It is how the trail of who took what gets lost — and that trail is what
    // each of them is paid on.
    actingForTenant($this->tenant);
    $order = takeaway($this->arepa->id, $this->zone->id, 'Los Palos Grandes');

    loginAs($this->slug, 'luis@ejemplo.com');
    deliveries($this->slug, "/{$order->id}/take", 'POST')->assertOk();

    loginAs($this->slug, 'pedro@ejemplo.com');

    test()->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/delivery/orders/{$order->id}/advance"), [
            'status' => 'delivered',
        ])->assertForbidden();
});

it('the whole journey: take it, go out, deliver it', function (): void {
    actingForTenant($this->tenant);
    $order = takeaway($this->arepa->id, $this->zone->id, 'Los Palos Grandes');

    loginAs($this->slug, 'pedro@ejemplo.com');

    deliveries($this->slug, "/{$order->id}/take", 'POST')->assertOk();

    test()->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/delivery/orders/{$order->id}/advance"), [
            'status' => 'out_for_delivery',
        ])->assertOk();

    test()->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/delivery/orders/{$order->id}/advance"), [
            'status' => 'delivered',
        ])->assertOk();

    actingForTenant($this->tenant);

    $delivered = OrderModel::find($order->id);

    expect($delivered->status->value)->toBe('delivered')
        // Recorded in their name, copied: the day Pedro leaves, the order still has
        // to say who took it.
        ->and($delivered->courier_name)->toBe('Pedro')
        ->and($delivered->delivered_at)->not->toBeNull();
});

it('dropping an order returns it to the list', function (): void {
    actingForTenant($this->tenant);
    $order = takeaway($this->arepa->id, $this->zone->id, 'Los Palos Grandes');

    loginAs($this->slug, 'pedro@ejemplo.com');
    deliveries($this->slug, "/{$order->id}/take", 'POST')->assertOk();
    deliveries($this->slug, "/{$order->id}/release", 'POST')->assertOk();

    loginAs($this->slug, 'luis@ejemplo.com');
    deliveries($this->slug, "/{$order->id}/take", 'POST')->assertOk();

    actingForTenant($this->tenant);
    expect(OrderModel::find($order->id)->courier_name)->toBe('Luis');
});

it('what is searched for is not a delivery, and does not appear', function (): void {
    actingForTenant($this->tenant);

    $order = app(PlaceOrder::class)->execute(
        items: [['product_id' => $this->arepa->id, 'quantity' => 1]],
        channel: 'counter',
    );

    foreach ([OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready] as $step) {
        app(AdvanceOrder::class)->execute((string) $order->id, $step);
    }

    loginAs($this->slug, 'pedro@ejemplo.com');

    expect(deliveries($this->slug)->json('data'))->toBe([]);
});

it('the kitchen does not deliver', function (): void {
    actingForTenant($this->tenant);

    $carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos');
    giveRole($this->tenant, $carlos, 'kitchen');

    loginAs($this->slug, 'carlos@ejemplo.com');

    deliveries($this->slug)->assertForbidden();
});
