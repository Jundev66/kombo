<?php

declare(strict_types=1);

namespace Modules\Reports\Application\UseCases;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Tenancy\TenantContext;

/**
 * Las ventas de un período.
 *
 * Dos decisiones de fondo que gobiernan todo lo de abajo:
 *
 * **Una venta es un pedido CONFIRMADO y no cancelado.** No uno «entregado»: en
 * el mostrador el pedido nace confirmado y se cobra en el acto, y esperar a que
 * alguien lo marque entregado dejaría fuera casi todo lo que se vendió. Y no
 * uno «recibido» tampoco: un pedido que el negocio nunca aceptó no es una
 * venta.
 *
 * **Vendido y cobrado no son lo mismo.** Un pedido a domicilio pagado en
 * efectivo se vendió hoy y se cobra al llegar; uno con pago móvil pendiente de
 * revisión se vendió y todavía no entró. La diferencia entre las dos cifras es
 * lo que falta por cobrar, y es una de las cosas que un dueño mira primero.
 *
 * Todo se agrupa en la **hora del negocio**, no en la del servidor. Un
 * contenedor en UTC pone el pico del almuerzo a las cuatro de la tarde.
 */
final class SalesReport
{
    /** Los estados que cuentan como venta. */
    private const CANCELADO = 'cancelled';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantContext $context,
        private readonly CurrentCapabilities $capabilities,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forRange(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            'from' => $from->format(DATE_ATOM),
            'to' => $to->format(DATE_ATOM),
            'summary' => $this->summary($from, $to),
            'byChannel' => $this->byChannel($from, $to),
            'byProduct' => $this->byProduct($from, $to),
            'byHour' => $this->byHour($from, $to),
            'byPaymentMethod' => $this->byPaymentMethod($from, $to),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $row = $this->sales($from, $to)
            ->selectRaw('count(*) as pedidos, coalesce(sum(total_cents), 0) as vendido, coalesce(sum(paid_cents), 0) as cobrado')
            ->first();

        $pedidos = (int) ($row->pedidos ?? 0);
        $vendido = (int) ($row->vendido ?? 0);
        $cobrado = (int) ($row->cobrado ?? 0);

        return [
            'orders' => $pedidos,
            'soldCents' => $vendido,
            'collectedCents' => $cobrado,
            // Lo que falta por cobrar. Nunca negativo: si alguien pagó de más,
            // eso es vuelto y no una deuda al revés.
            'outstandingCents' => max(0, $vendido - $cobrado),
            // El ticket promedio, que es la cifra que dice si conviene empujar
            // los combos. Cero pedidos ⇒ cero, no una división por cero.
            'averageTicketCents' => $pedidos === 0 ? 0 : intdiv($vendido, $pedidos),
            'cancelled' => (int) $this->db->table('orders')
                ->where('status', self::CANCELADO)
                ->whereBetween('placed_at', [$from, $to])
                ->count(),
        ];
    }

    /**
     * Por dónde entró: mostrador, portal, WhatsApp.
     *
     * Es de las primeras cosas que un dueño quiere saber cuando enciende un
     * canal nuevo — si el portal trae pedidos de verdad o sólo curiosos.
     *
     * @return list<array<string, mixed>>
     */
    private function byChannel(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->sales($from, $to)
            ->selectRaw('channel, count(*) as pedidos, coalesce(sum(total_cents), 0) as total')
            ->groupBy('channel')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'channel' => $row->channel,
                'orders' => (int) $row->pedidos,
                'totalCents' => (int) $row->total,
            ])->all();
    }

    /**
     * Lo que más se vende.
     *
     * Se agrupa por el **nombre copiado en la línea**, no por el producto. Es a
     * propósito: si el dueño renombró «Arepa» a «Arepa grande» y le subió el
     * precio, son dos ofertas distintas y mezclarlas escondería justo el efecto
     * que quiere medir. Además, un producto borrado sigue apareciendo con lo
     * que vendió.
     *
     * @return list<array<string, mixed>>
     */
    private function byProduct(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $limite = (int) $this->capabilities->get()->setting('reports.top_products', 10);

        return $this->db->table('order_items')
            ->join('orders', function ($join): void {
                // Compuesta, como todas: unir sólo por `order_id` mezclaría
                // negocios si algún día se consultara sin RLS.
                $join->on('orders.id', '=', 'order_items.order_id')
                    ->on('orders.tenant_id', '=', 'order_items.tenant_id');
            })
            ->whereNotNull('orders.confirmed_at')
            ->where('orders.status', '!=', self::CANCELADO)
            ->whereBetween('orders.confirmed_at', [$from, $to])
            ->selectRaw('order_items.product_name as nombre, sum(order_items.quantity) as cantidad, coalesce(sum(order_items.line_total_cents), 0) as total')
            ->groupBy('order_items.product_name')
            ->orderByDesc('total')
            ->limit($limite)
            ->get()
            ->map(fn (object $row): array => [
                'name' => $row->nombre,
                'quantity' => (int) $row->cantidad,
                'totalCents' => (int) $row->total,
            ])->all();
    }

    /**
     * A qué hora entra la gente, **en la hora del negocio**.
     *
     * Se devuelven las 24 horas SIEMPRE, con cero donde no hubo nada: una
     * pantalla que tenga que rellenar los huecos acabaría rellenándolos
     * distinto que el bot o que el que exporte a una hoja de cálculo.
     *
     * @return list<array<string, mixed>>
     */
    private function byHour(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $tz = $this->context->current()->timezone;

        $rows = $this->sales($from, $to)
            ->selectRaw(
                'extract(hour from placed_at at time zone ?) as hora, count(*) as pedidos, coalesce(sum(total_cents), 0) as total',
                [$tz],
            )
            ->groupBy('hora')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->hora);

        $horas = [];

        for ($hora = 0; $hora <= 23; $hora++) {
            $row = $rows->get($hora);

            $horas[] = [
                'hour' => $hora,
                'orders' => (int) ($row->pedidos ?? 0),
                'totalCents' => (int) ($row->total ?? 0),
            ];
        }

        return $horas;
    }

    /**
     * Cómo pagan. **Sólo pagos confirmados**: un pago móvil esperando revisión
     * todavía no es dinero.
     *
     * @return list<array<string, mixed>>
     */
    private function byPaymentMethod(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->db->table('order_payments')
            ->join('orders', function ($join): void {
                $join->on('orders.id', '=', 'order_payments.order_id')
                    ->on('orders.tenant_id', '=', 'order_payments.tenant_id');
            })
            ->where('order_payments.status', 'confirmed')
            ->whereNotNull('orders.confirmed_at')
            ->where('orders.status', '!=', self::CANCELADO)
            ->whereBetween('orders.confirmed_at', [$from, $to])
            ->selectRaw('order_payments.method as metodo, count(*) as veces, coalesce(sum(order_payments.amount_cents), 0) as total')
            ->groupBy('order_payments.method')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'method' => $row->metodo,
                'count' => (int) $row->veces,
                'totalCents' => (int) $row->total,
            ])->all();
    }

    /**
     * La base de todas las consultas: los pedidos que cuentan como venta.
     *
     * Escrita una vez. Si cada reporte definiera «venta» por su cuenta, el
     * total del resumen no cuadraría con la suma por canal y nadie sabría cuál
     * de los dos creer.
     */
    private function sales(DateTimeImmutable $from, DateTimeImmutable $to): Builder
    {
        return $this->db->table('orders')
            ->whereNotNull('confirmed_at')
            ->where('status', '!=', self::CANCELADO)
            ->whereBetween('confirmed_at', [$from, $to]);
    }

    /**
     * Los atajos que usa la pantalla: hoy, ayer, la semana, el mes.
     *
     * Se resuelven en el SERVIDOR, en la hora del negocio. Calcularlos en el
     * teléfono usaría el huso del teléfono — y «hoy» para alguien de viaje
     * sería otro día.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public function range(string $preset): array
    {
        $tz = $this->context->current()->timezone;
        $ahora = Carbon::now($tz);

        return match ($preset) {
            'ayer' => [
                $ahora->copy()->subDay()->startOfDay()->toDateTimeImmutable(),
                $ahora->copy()->subDay()->endOfDay()->toDateTimeImmutable(),
            ],
            'semana' => [
                $ahora->copy()->startOfWeek()->toDateTimeImmutable(),
                $ahora->copy()->endOfDay()->toDateTimeImmutable(),
            ],
            'mes' => [
                $ahora->copy()->startOfMonth()->toDateTimeImmutable(),
                $ahora->copy()->endOfDay()->toDateTimeImmutable(),
            ],
            default => [
                $ahora->copy()->startOfDay()->toDateTimeImmutable(),
                $ahora->copy()->endOfDay()->toDateTimeImmutable(),
            ],
        };
    }
}
