<?php

declare(strict_types=1);

/*
 * The counter till and the paper handed to the customer.
 *
 * These tests are written against what happens at the counter — payment in a
 * mix, a void with the manager standing there, a reprint of the note that got
 * smudged — rather than against the shape of the endpoints.
 */

use App\Models\Catalog\ModifierGroupModel;
use App\Models\Catalog\ProductModel;
use App\Models\Documents\DeliveryNoteModel;
use App\Models\Kitchen\KitchenTicketModel;
use App\Models\Orders\OrderModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$suffix}";
    $this->tenant = makeTenant($this->slug, plan: 'business');

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'kitchen', 'documents', 'counter'] as $module) {
        enableModule($this->tenant, $module);
    }

    // María is the owner; José the manager, who can void alone; Ana is at the
    // counter and can only REQUEST a void.
    $this->maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María', pin: '1234');
    giveRole($this->tenant, $this->maria, 'owner');

    $this->jose = makeUser($this->tenant, 'jose@ejemplo.com', 'José', pin: '2345');
    giveRole($this->tenant, $this->jose, 'manager');

    $this->ana = makeUser($this->tenant, 'ana@ejemplo.com', 'Ana', pin: '3456');
    giveRole($this->tenant, $this->ana, 'counter');

    $this->arepa = ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);
    $this->jugo = ProductModel::create(['name' => 'Jugo de parchita', 'price_cents' => 150]);

    $group = ModifierGroupModel::create(['name' => 'Extras', 'min_choices' => 0, 'max_choices' => 3]);
    $this->quesoExtra = $group->modifiers()->create(['name' => 'Extra queso', 'price_delta_cents' => 100]);
});

/** Charges a sale at the counter. */
function charge(string $slug, array $payload): TestResponse
{
    return test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, '/api/v1/counter/sales'), $payload);
}

/** A simple paid sale: one arepa in cash. Returns the response. */
function simpleSale(string $slug, string $productId, int $cents = 300): TestResponse
{
    return charge($slug, [
        'items' => [['product_id' => $productId, 'quantity' => 1]],
        'payments' => [['method' => 'cash_usd', 'amount_cents' => $cents]],
    ])->assertCreated();
}

it('charging at the counter issues the note and sends the ticket to the kitchen', function (): void {
    // The whole in-person sale in ONE call: the customer is there and has paid,
    // so there is nothing to wait for.
    loginAs($this->slug, 'ana@ejemplo.com');

    $response = charge($this->slug, [
        'items' => [
            ['product_id' => $this->arepa->id, 'quantity' => 2, 'modifier_ids' => [$this->quesoExtra->id]],
            ['product_id' => $this->jugo->id, 'quantity' => 1],
        ],
        'payments' => [['method' => 'cash_usd', 'amount_cents' => 950]],
    ])->assertCreated();

    // 2 × (300 + 100) + 150. The server computes the amount.
    $response->assertJsonPath('data.order.totalCents', 950);
    $response->assertJsonPath('data.order.status', 'confirmed');
    $response->assertJsonPath('data.order.paymentStatus', 'paid');

    // The paper says what it is, and says it from the stored document itself.
    $response->assertJsonPath('data.note.title', 'NOTA DE ENTREGA');
    $response->assertJsonPath('data.note.disclaimer', 'No es una factura');
    $response->assertJsonPath('data.note.snapshot.disclaimer', 'No es una factura');
    $response->assertJsonPath('data.note.reference', 'A-000001');

    // And what motivated the whole project: the ticket appears in the kitchen on
    // its own, with the SAME number shouted across the counter.
    actingForTenant($this->tenant);

    $ticket = KitchenTicketModel::first();
    expect($ticket)->not->toBeNull()
        ->and($ticket->status->value)->toBe('pending')
        ->and($ticket->number)->toBe((int) $response->json('data.order.number'));
});

it('payment comes in a mix: part cash and the rest by mobile transfer', function (): void {
    // How payment works here, and the reason payments are several rows rather
    // than one column.
    loginAs($this->slug, 'ana@ejemplo.com');

    charge($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 3]],
        'payments' => [
            ['method' => 'cash_usd', 'amount_cents' => 500],
            ['method' => 'pago_movil', 'amount_cents' => 400, 'reference' => '0102-99887'],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.order.totalCents', 900)
        ->assertJsonPath('data.order.paidCents', 900)
        ->assertJsonPath('data.note.snapshot.payments.1.reference', '0102-99887');
});

it('the till CANNOT set the price of what it sells', function (): void {
    // A tampered browser cannot charge itself whatever it likes: line amounts it
    // sends are ignored and the price comes from the catalog.
    loginAs($this->slug, 'ana@ejemplo.com');

    charge($this->slug, [
        'items' => [[
            'product_id' => $this->arepa->id,
            'quantity' => 1,
            'unit_price_cents' => 1,
            'line_total_cents' => 1,
        ]],
        'payments' => [['method' => 'cash_usd', 'amount_cents' => 300]],
        'total_cents' => 1,
    ])->assertCreated()
        ->assertJsonPath('data.order.totalCents', 300)
        ->assertJsonPath('data.note.totalCents', 300);
});

it('the sequence runs one after another, with no gaps', function (): void {
    loginAs($this->slug, 'ana@ejemplo.com');

    $references = collect(range(1, 3))
        ->map(fn (): string => simpleSale($this->slug, $this->arepa->id)->json('data.note.reference'));

    expect($references->all())->toBe(['A-000001', 'A-000002', 'A-000003']);
});

it('voiding a sale does not release its number', function (): void {
    // If two pieces of paper can carry the same number, the number identifies
    // neither.
    loginAs($this->slug, 'jose@ejemplo.com');

    $first = simpleSale($this->slug, $this->arepa->id);
    $orderId = $first->json('data.order.id');

    expect($first->json('data.note.reference'))->toBe('A-000001');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'El cliente se arrepintió',
        ])
        ->assertOk()
        ->assertJsonPath('data.note.isVoided', true)
        ->assertJsonPath('data.note.reference', 'A-000001')
        ->assertJsonPath('data.order.status', 'cancelled');

    // The next sale takes the NEXT number, not the voided one.
    expect(simpleSale($this->slug, $this->arepa->id)->json('data.note.reference'))->toBe('A-000002');
});

it('voiding a sale takes the ticket off the kitchen board', function (): void {
    // Without this, the cook finishes a dish nobody will collect.
    loginAs($this->slug, 'jose@ejemplo.com');

    $orderId = simpleSale($this->slug, $this->arepa->id)->json('data.order.id');

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/kitchen/tickets'))
        ->assertJsonCount(1, 'data');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'Se equivocó de pedido',
        ])->assertOk();

    $this->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/kitchen/tickets'))
        ->assertJsonCount(0, 'data');

    // But it is NOT deleted: stock was involved and the owner will want to know
    // how much was lost.
    actingForTenant($this->tenant);

    $ticket = KitchenTicketModel::first();
    expect($ticket->status->value)->toBe('cancelled')
        ->and($ticket->cancelled_at)->not->toBeNull();
});

it('the counter REQUESTS the void; without a manager\'s PIN nothing happens', function (): void {
    loginAs($this->slug, 'ana@ejemplo.com');

    $orderId = simpleSale($this->slug, $this->arepa->id)->json('data.order.id');

    // 422 on `authorization_pin`, not 403: the till has to know this can be
    // fixed right there, and open the PIN pad.
    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'Se equivocó de pedido',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('authorization_pin');

    actingForTenant($this->tenant);
    expect(OrderModel::find($orderId)->status->value)->not->toBe('cancelled');
});

it('with the manager\'s PIN it is voided, in the name of whoever authorised it', function (): void {
    loginAs($this->slug, 'ana@ejemplo.com');

    $orderId = simpleSale($this->slug, $this->arepa->id)->json('data.order.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'Se equivocó de pedido',
            'authorized_by' => $this->jose,
            'authorization_pin' => '2345',
        ])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'cancelled');

    actingForTenant($this->tenant);

    // Ana did it, José authorised it, and both are recorded. The whole reason
    // the counter can only request it.
    $registry = DB::table('audit_log')
        ->where('tenant_id', $this->tenant)
        ->where('action', 'orders.cancelled')
        ->first();

    expect($registry->user_id)->toBe($this->ana)
        ->and($registry->authorized_by)->toBe($this->jose)
        ->and($registry->reason)->toBe('Se equivocó de pedido');
});

it('a wrong PIN does not say what was wrong', function (): void {
    // One message for all three failures: unknown, inactive, or wrong PIN.
    // Saying which gives away half the work to whoever is guessing.
    loginAs($this->slug, 'ana@ejemplo.com');

    $orderId = simpleSale($this->slug, $this->arepa->id)->json('data.order.id');

    $withBadPin = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'Se equivocó de pedido',
            'authorized_by' => $this->jose,
            'authorization_pin' => '9999',
        ])->assertForbidden();

    $withInventedUser = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'Se equivocó de pedido',
            'authorized_by' => (string) Str::uuid7(),
            'authorization_pin' => '2345',
        ])->assertForbidden();

    expect($withBadPin->json('message'))->toBe($withInventedUser->json('message'));

    actingForTenant($this->tenant);
    expect(OrderModel::find($orderId)->status->value)->not->toBe('cancelled');
});

it('nobody authorises themselves', function (): void {
    // Whoever is complaining has the original in their hand. Rebuilding the note
    // from the live tables would give a different paper.
    loginAs($this->slug, 'ana@ejemplo.com');

    $orderId = simpleSale($this->slug, $this->arepa->id)->json('data.order.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [
            'reason' => 'Se equivocó de pedido',
            'authorized_by' => $this->ana,
            'authorization_pin' => '3456',
        ])->assertForbidden();

    actingForTenant($this->tenant);
    expect(OrderModel::find($orderId)->status->value)->not->toBe('cancelled');
});

it('voiding requires a reason', function (): void {
    loginAs($this->slug, 'jose@ejemplo.com');

    $orderId = simpleSale($this->slug, $this->arepa->id)->json('data.order.id');

    $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/counter/sales/{$orderId}/void"), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

it('reprinting gives EXACTLY the same paper even if the menu has changed', function (): void {
    loginAs($this->slug, 'ana@ejemplo.com');

    $sale = simpleSale($this->slug, $this->arepa->id);
    $noteId = $sale->json('data.note.id');
    $original = $sale->json('data.note.snapshot');

    actingForTenant($this->tenant);
    $this->arepa->update(['name' => 'Reina Pepiada GRANDE', 'price_cents' => 500]);

    $reprinted = $this->withHeaders(browsingAs($this->slug))
        ->postJson(urlFor($this->slug, "/api/v1/notes/{$noteId}/reprint"))
        ->assertOk();

    // `toEqual` and not `toBe`: jsonb does not preserve key order, and what has
    // to be identical is the content of the paper.
    expect($reprinted->json('data.snapshot'))->toEqual($original)
        ->and($reprinted->json('data.snapshot.lines.0.name'))->toBe('Reina Pepiada')
        // Counted: a note reprinted five times is a question somebody will want to
        // ask.
        ->and($reprinted->json('data.printedCount'))->toBe(1);
});

it('charging twice on a double tap does not issue two documents', function (): void {
    // A second note would carry another number and back the same food.
    loginAs($this->slug, 'ana@ejemplo.com');

    $orderId = simpleSale($this->slug, $this->arepa->id)->json('data.order.id');

    actingForTenant($this->tenant);

    expect(DeliveryNoteModel::where('order_id', $orderId)->count())->toBe(1);
});

it('the kitchen cannot take payment', function (): void {
    $carlos = makeUser($this->tenant, 'carlos@ejemplo.com', 'Carlos', pin: '4567');
    giveRole($this->tenant, $carlos, 'kitchen');

    loginAs($this->slug, 'carlos@ejemplo.com');

    // 403 and not 404: the module exists for this tenant; what Carlos lacks is
    // the permission, and that is said plainly.
    charge($this->slug, [
        'items' => [['product_id' => $this->arepa->id, 'quantity' => 1]],
        'payments' => [['method' => 'cash_usd', 'amount_cents' => 300]],
    ])->assertForbidden();
});

it('a tenant with NO till has no till: its routes do not exist', function (): void {
    // A stall that only sells through the portal. Its routes answer 404 — a
    // missing module is contract information, not a permission decision.
    $suffix = Str::lower(Str::random(6));
    $slug = "laesquina-{$suffix}";
    $other = makeTenant($slug, plan: 'starter');

    actingForTenant($other);
    foreach (['core', 'catalog', 'orders', 'kitchen'] as $module) {
        enableModule($other, $module);
    }

    $pedro = makeUser($other, 'pedro@ejemplo.com', 'Pedro', pin: '1234');
    giveRole($other, $pedro, 'owner');

    loginAs($slug, 'pedro@ejemplo.com');

    charge($slug, [
        'items' => [['product_id' => (string) Str::uuid7(), 'quantity' => 1]],
        'payments' => [['method' => 'cash_usd', 'amount_cents' => 300]],
    ])->assertNotFound();

    $this->withHeaders(browsingAs($slug))
        ->getJson(urlFor($slug, '/api/v1/notes'))
        ->assertNotFound();
});
