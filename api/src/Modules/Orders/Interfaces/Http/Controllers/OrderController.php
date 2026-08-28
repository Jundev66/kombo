<?php

declare(strict_types=1);

namespace Modules\Orders\Interfaces\Http\Controllers;

use App\Models\Orders\OrderModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Orders\Application\Exceptions\OrderNotFound;
use Modules\Orders\Application\UseCases\AdvanceOrder;
use Modules\Orders\Application\UseCases\CancelOrder;
use Modules\Orders\Application\UseCases\PlaceOrder;
use Modules\Orders\Application\UseCases\RegisterPayment;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Modules\Orders\Domain\ValueObjects\ServiceType;
use Modules\Orders\Interfaces\Http\Resources\OrderResource;
use Platform\Auth\ActionAuthorizer;

/**
 * Los pedidos, por HTTP.
 *
 * Sin reglas de negocio aquí: valida la forma, llama al caso de uso, devuelve.
 * Un `if` sobre estados en este archivo sería una regla que deja de valer para
 * el bot y para la caja.
 */
final class OrderController
{
    /** Cuántos pedidos caben en el tablero antes de tener que avisar. */
    private const TOPE = 200;

    public function index(Request $request): JsonResponse
    {
        $orders = OrderModel::query()
            ->with(['items.modifiers'])
            ->when(
                $request->boolean('abiertos', true),
                // Por defecto, sólo los vivos: el tablero es para trabajar, no
                // para consultar el histórico.
                fn ($q) => $q->whereNotIn('status', [
                    OrderStatus::Delivered->value,
                    OrderStatus::Cancelled->value,
                ]),
            )
            ->when(
                $request->string('estado')->isNotEmpty(),
                fn ($q) => $q->where('status', $request->string('estado')->toString()),
            )
            // Del más viejo al más nuevo: el que lleva más esperando va
            // primero, que es el orden en el que hay que atenderlos.
            ->orderBy('placed_at')
            ->limit(self::TOPE)
            ->get();

        /*
         * Si hay más de los que caben, **se dice**.
         *
         * Como el orden va del más viejo al más nuevo, lo que se queda fuera
         * son los pedidos RECIÉN entrados. Cortar en silencio significa que un
         * negocio con el tablero lleno deja de ver lo que acaba de llegar — sin
         * ningún aviso, y con el cliente esperando. Es el mismo cuidado que se
         * tuvo con la pantalla de cocina.
         */
        $vivos = $orders->count() < self::TOPE
            ? $orders->count()
            : OrderModel::query()->whereNotIn('status', [
                OrderStatus::Delivered->value,
                OrderStatus::Cancelled->value,
            ])->count();

        return response()->json([
            'data' => $orders->map(fn (OrderModel $o): array => OrderResource::make($o))->all(),
            'meta' => [
                'total' => $vivos,
                'hidden' => max(0, $vivos - $orders->count()),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $order = OrderModel::with(['items.modifiers', 'payments'])->find($id) ?? throw new OrderNotFound;

        return response()->json(['data' => OrderResource::make($order)]);
    }

    public function store(Request $request, PlaceOrder $placeOrder): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.modifier_ids' => ['array'],
            'items.*.modifier_ids.*' => ['uuid'],
            'items.*.notes' => ['nullable', 'string', 'max:300'],

            'service_type' => ['nullable', 'string', 'in:takeaway,dine_in,delivery'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_fee_cents' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // `price_cents` NO se acepta, ni por línea ni en total. Los
        // identificadores vienen del cliente; los precios los pone el
        // servidor. Es la regla que impide que un navegador manipulado se
        // cobre a sí mismo lo que quiera.
        $order = $placeOrder->execute(
            items: $data['items'],
            serviceType: ServiceType::from($data['service_type'] ?? 'takeaway'),
            channel: 'counter',
            customerName: $data['customer_name'] ?? null,
            customerPhone: $data['customer_phone'] ?? null,
            deliveryAddress: $data['delivery_address'] ?? null,
            deliveryFeeCents: $data['delivery_fee_cents'] ?? 0,
            notes: $data['notes'] ?? null,
        );

        return response()->json(
            ['data' => OrderResource::make($order->load('items.modifiers'))],
            201,
        );
    }

    public function advance(Request $request, string $id, AdvanceOrder $advance): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:confirmed,preparing,ready,out_for_delivery,delivered'],
        ]);

        $order = $advance->execute(
            orderId: $id,
            next: OrderStatus::from($data['status']),
            byName: $request->user()?->name,
        );

        return response()->json(['data' => OrderResource::make($order->load('items.modifiers'))]);
    }

    public function cancel(Request $request, string $id, CancelOrder $cancel, ActionAuthorizer $authorizer): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        /*
         * Quien sólo tiene `orders.cancel_request` llega hasta aquí y necesita
         * el PIN de alguien que sí pueda. El autorizador devuelve 422 con
         * nombre de campo si falta, para que la caja abra el diálogo en vez de
         * dejar al cajero sin salida con un cliente delante.
         */
        $authorizedBy = $authorizer->resolve($request, 'orders.cancel');

        $order = $cancel->execute($id, $data['reason'], $authorizedBy);

        return response()->json(['data' => OrderResource::make($order->load('items.modifiers'))]);
    }

    public function pay(Request $request, string $id, RegisterPayment $payments): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'in:cash_usd,cash_bs,pago_movil,transfer,zelle,card,binance'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:120'],
            'receipt_url' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $payments->execute(
            orderId: $id,
            method: $data['method'],
            amountCents: $data['amount_cents'],
            reference: $data['reference'] ?? null,
            receiptUrl: $data['receipt_url'] ?? null,
        );

        return response()->json(['data' => OrderResource::make($order->load(['items.modifiers', 'payments']))]);
    }

    public function confirmPayment(string $id, string $paymentId, RegisterPayment $payments): JsonResponse
    {
        $order = $payments->confirm($paymentId);

        return response()->json(['data' => OrderResource::make($order->load(['items.modifiers', 'payments']))]);
    }
}
