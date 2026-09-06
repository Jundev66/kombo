<?php

declare(strict_types=1);

namespace Modules\Orders\Application\UseCases;

use App\Models\Orders\OrderModel;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Contracts\ModifierCatalog;
use Modules\Catalog\Application\Contracts\ProductCatalog;
use Modules\Orders\Application\Exceptions\ProductNotSellable;
use Modules\Orders\Domain\Entities\Order;
use Modules\Orders\Domain\Events\OrderPlaced;
use Modules\Orders\Domain\ValueObjects\OrderLine;
use Modules\Orders\Domain\ValueObjects\OrderLineModifier;
use Modules\Orders\Domain\ValueObjects\ServiceType;
use Platform\Audit\AuditLogger;
use Platform\Tenancy\TenantContext;
use Shared\Domain\ValueObjects\Money;

/**
 * Taking an order.
 *
 * The rule that governs this use case: ids come from the client, prices do NOT.
 * The portal, the bot and the till send which product and how many; what it
 * costs is resolved here against the menu, always. Without that, a tampered
 * browser charges itself whatever it likes and it shows up at month end.
 */
final class PlaceOrder
{
    public function __construct(
        private readonly ProductCatalog $products,
        private readonly ModifierCatalog $modifiers,
        private readonly DatabaseManager $db,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: int, modifier_ids?: list<string>, notes?: string|null}>  $items
     */
    public function execute(
        array $items,
        ServiceType $serviceType = ServiceType::Takeaway,
        string $channel = 'counter',
        ?string $customerName = null,
        ?string $customerPhone = null,
        ?string $deliveryAddress = null,
        int $deliveryFeeCents = 0,
        ?string $notes = null,
        bool $awaitingPayment = false,
        ?string $deliveryZoneId = null,
        ?string $deliveryZoneName = null,
        ?DateTimeImmutable $expiresAt = null,
    ): OrderModel {
        $lines = $this->resolveLines($items);

        // The domain validates before the database is touched: something to charge
        // for, sensible quantities, and the totals.
        $order = Order::place(
            id: (string) Str::uuid7(),
            serviceType: $serviceType,
            lines: $lines,
            deliveryFee: Money::fromCents($deliveryFeeCents),
            notes: $notes,
            awaitingPayment: $awaitingPayment,
        );

        $rate = $this->currentRate();

        return $this->db->transaction(function () use (
            $order, $channel, $customerName, $customerPhone, $deliveryAddress, $rate,
            $deliveryZoneId, $deliveryZoneName, $expiresAt
        ): OrderModel {
            $model = new OrderModel;
            $model->id = $order->id;
            $model->fill([
                'number' => $this->nextNumber(),
                // 22 base64url characters ≈ 128 bits: not guessable by trying.
                'public_token' => Str::random(22),
                'status' => $order->status()->value,
                'service_type' => $order->serviceType()->value,
                'channel' => $channel,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'delivery_address' => $deliveryAddress,
                'delivery_zone_id' => $deliveryZoneId,
                // COPIED: an order from two months ago has to say which neighbourhood it
                // went to even if the zone no longer exists.
                'delivery_zone_name' => $deliveryZoneName,
                'subtotal_cents' => $order->subtotal()->cents,
                'delivery_fee_cents' => $order->deliveryFee()->cents,
                'total_cents' => $order->total()->cents,
                'currency' => 'USD',
                // The rate is FROZEN here, or a March order's bolívar amount would change
                // every morning.
                'exchange_rate' => $rate,
                'notes' => $order->notes(),
                'placed_at' => $order->stampedAt('placed_at'),
                // Only orders awaiting payment carry it. An already-paid order does not
                // expire.
                'expires_at' => $expiresAt,
                'created_by' => auth()->id(),
            ]);
            $model->save();

            foreach ($order->lines() as $index => $line) {
                $item = $model->items()->create([
                    'product_id' => $line->productId,
                    // COPIED. A ticket from six months ago must say what it said when it was
                    // printed.
                    'product_name' => $line->productName,
                    'unit_price_cents' => $line->unitPrice->cents,
                    'quantity' => $line->quantity,
                    'modifiers_total_cents' => $line->modifiersTotal()->cents,
                    'line_total_cents' => $line->total()->cents,
                    'notes' => $line->notes,
                    'sort_order' => $index,
                ]);

                foreach ($line->modifiers as $position => $modifier) {
                    $item->modifiers()->create([
                        'modifier_id' => $modifier->modifierId,
                        'name' => $modifier->name,
                        'price_delta_cents' => $modifier->priceDelta->cents,
                        'sort_order' => $position,
                    ]);
                }
            }

            $this->audit->record(
                action: 'orders.placed',
                entityType: 'order',
                entityId: $order->id,
                after: ['number' => $model->number, 'total_cents' => $order->total()->cents],
            );

            $model->refresh();

            /*
             * An order came in. By event, like everything else: `Orders` does
             * not know who is listening.
             */
            $this->events->dispatch(new OrderPlaced(
                tenantId: $this->context->id(),
                orderId: (string) $model->id,
                number: (int) $model->number,
                channel: $channel,
                totalCents: (int) $model->total_cents,
                customerName: $model->customer_name,
                customerPhone: $model->customer_phone,
            ));

            // Returned re-read: columns like `payment_status` come from database
            // defaults, and a half-filled model makes the caller guess which.
            return $model;
        });
    }

    /**
     * From what the client sent to lines with real prices.
     *
     * @param  list<array{product_id: string, quantity: int, modifier_ids?: list<string>, notes?: string|null}>  $items
     * @return list<OrderLine>
     */
    private function resolveLines(array $items): array
    {
        // Two queries for the whole order, not two per line. On a modest machine
        // that is the difference between half a second and five.
        $productIds = array_values(array_unique(array_column($items, 'product_id')));
        $modifierIds = array_values(array_unique(array_merge(
            ...array_map(static fn (array $i): array => $i['modifier_ids'] ?? [], $items),
        )));

        $products = $this->products->findMany($productIds);
        $modifiers = $this->modifiers->findMany($modifierIds);

        $lines = [];

        foreach ($items as $item) {
            $product = $products[$item['product_id']] ?? null;

            if ($product === null || ! $product->isSellable($item['quantity'])) {
                // The same message for "gone", "off the menu" and "sold out": to whoever
                // is ordering they are the same thing.
                throw new ProductNotSellable($product?->name);
            }

            $chosen = [];

            foreach ($item['modifier_ids'] ?? [] as $modifierId) {
                $modifier = $modifiers[$modifierId] ?? null;

                // An inactive modifier is ignored silently rather than failing the order:
                // taking an extra off the menu cannot break the screen of somebody who
                // already had it in their basket.
                if ($modifier === null || ! $modifier->isActive) {
                    continue;
                }

                $chosen[] = new OrderLineModifier(
                    modifierId: $modifier->id,
                    name: $modifier->name,
                    priceDelta: $modifier->priceDelta,
                );
            }

            $lines[] = new OrderLine(
                productId: $product->id,
                productName: $product->name,
                // FROM THE CATALOG, never from what arrived in the request.
                unitPrice: $product->price,
                quantity: $item['quantity'],
                modifiers: $chosen,
                notes: $item['notes'] ?? null,
            );
        }

        return $lines;
    }

    /**
     * The tenant's next order number.
     *
     * Two tills taking payment at once cannot draw the same number: it is the
     * one shouted across the counter, and repeating it means two customers
     * collecting each other's food.
     *
     * An advisory lock per tenant rather than `for update`: PostgreSQL does not
     * allow `FOR UPDATE` with `max()`, and locking the last row is no use on an
     * empty table — the first order of the day, when two tills start at once.
     * The unique `(tenant_id, number)` sits behind it either way.
     */
    private function nextNumber(): int
    {
        $this->db->select('select pg_advisory_xact_lock(hashtext(?))', [
            'orders:'.$this->context->id(),
        ]);

        $last = $this->db->table('orders')
            ->where('tenant_id', $this->context->id())
            ->max('number');

        return (int) $last + 1;
    }

    private function currentRate(): ?string
    {
        $rate = $this->db->table('exchange_rates')
            ->where('tenant_id', $this->context->id())
            ->orderByDesc('effective_date')
            ->value('rate');

        return $rate === null ? null : (string) $rate;
    }
}
