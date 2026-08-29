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
 * Tomar un pedido.
 *
 * **La regla que gobierna este caso de uso: los identificadores vienen del
 * cliente, los precios NO.**
 *
 * Quien arma el pedido —el portal, el bot, la caja— manda qué producto y
 * cuántos. Cuánto cuesta se resuelve aquí, contra la carta, siempre. Sin eso,
 * un navegador manipulado se cobraría lo que quisiera, y el fallo sólo se
 * notaría al cuadrar el mes.
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

        // El dominio valida antes de tocar la base: que haya algo que cobrar,
        // que las cantidades tengan sentido, y calcula los totales.
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
                // 22 caracteres base64url ≈ 128 bits: no se adivina probando.
                'public_token' => Str::random(22),
                'status' => $order->status()->value,
                'service_type' => $order->serviceType()->value,
                'channel' => $channel,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'delivery_address' => $deliveryAddress,
                'delivery_zone_id' => $deliveryZoneId,
                // COPIADO: un pedido de hace dos meses tiene que decir a qué
                // barrio fue aunque la zona ya no exista.
                'delivery_zone_name' => $deliveryZoneName,
                'subtotal_cents' => $order->subtotal()->cents,
                'delivery_fee_cents' => $order->deliveryFee()->cents,
                'total_cents' => $order->total()->cents,
                'currency' => 'USD',
                // La tasa se CONGELA aquí. Sin esto, el importe en bolívares
                // de un pedido de marzo cambiaría cada mañana.
                'exchange_rate' => $rate,
                'notes' => $order->notes(),
                'placed_at' => $order->stampedAt('placed_at'),
                // Sólo lo llevan los que esperan un pago. Un pedido ya pagado
                // no caduca.
                'expires_at' => $expiresAt,
                'created_by' => auth()->id(),
            ]);
            $model->save();

            foreach ($order->lines() as $index => $line) {
                $item = $model->items()->create([
                    'product_id' => $line->productId,
                    // COPIADOS. Un ticket de hace seis meses debe decir lo que
                    // decía cuando se imprimió.
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
             * Entró un pedido.
             *
             * Por evento, como todo lo demás: `Orders` no sabe quién escucha.
             * Hoy lo oye el módulo de clientes para llevar su cuenta; mañana
             * podría oírlo otro sin tocar esto.
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

            // Se devuelve releído: columnas como `payment_status` las pone la
            // base con su valor por defecto, y un modelo a medio llenar obliga
            // a quien lo recibe a adivinar cuáles faltan.
            return $model;
        });
    }

    /**
     * De lo que mandó el cliente a líneas con precios de verdad.
     *
     * @param  list<array{product_id: string, quantity: int, modifier_ids?: list<string>, notes?: string|null}>  $items
     * @return list<OrderLine>
     */
    private function resolveLines(array $items): array
    {
        // Dos consultas para todo el pedido, no dos por línea. En una máquina
        // modesta, ese detalle es la diferencia entre cobrar en medio segundo
        // o en cinco.
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
                // El mismo mensaje para «no existe», «lo sacaron de la carta» y
                // «se acabó»: para quien pide son la misma cosa, y distinguirlo
                // sólo sirve para que alguien deduzca qué hay en la base.
                throw new ProductNotSellable($product?->name);
            }

            $chosen = [];

            foreach ($item['modifier_ids'] ?? [] as $modifierId) {
                $modifier = $modifiers[$modifierId] ?? null;

                // Un modificador inactivo se IGNORA en silencio en vez de
                // tumbar el pedido: quitar un extra de la carta no puede
                // reventarle la pantalla a quien lo tenía en el carrito.
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
                // DEL CATÁLOGO, nunca de lo que llegó en la petición.
                unitPrice: $product->price,
                quantity: $item['quantity'],
                modifiers: $chosen,
                notes: $item['notes'] ?? null,
            );
        }

        return $lines;
    }

    /**
     * El siguiente número del negocio.
     *
     * Dos cajas cobrando a la vez no pueden sacar el mismo número: ése es el
     * que se grita en el mostrador, y repetirlo son dos clientes recogiendo la
     * comida del otro.
     *
     * Se usa un **cerrojo consultivo por negocio**, no `for update` sobre la
     * tabla, por dos razones: PostgreSQL no admite `FOR UPDATE` junto a una
     * función de agregado como `max()`, y bloquear la última fila no serviría
     * cuando la tabla está vacía —justo el primer pedido del día, que es
     * cuando dos cajas arrancan a la vez—.
     *
     * El cerrojo se suelta solo al terminar la transacción. Y por si acaso,
     * detrás está el único `(tenant_id, number)`: si algo fallara, la base
     * rechaza el insert en vez de duplicar el número.
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
