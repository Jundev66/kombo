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
 * A period's sales. Two decisions govern everything below.
 *
 * A sale is a CONFIRMED, uncancelled order — not "delivered", because at the
 * counter the order is paid on the spot and waiting for a delivered mark would
 * leave out almost everything sold.
 *
 * Sold and collected are not the same: the difference between the two figures
 * is what is still owed, and one of the first things an owner looks at.
 *
 * Everything is grouped by the TENANT's local time; a container in UTC puts the
 * lunch peak at four in the afternoon.
 */
final class SalesReport
{
    /** The states that count as a sale. */
    private const CANCELLED = 'cancelled';

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
            ->selectRaw('count(*) as orders, coalesce(sum(total_cents), 0) as sold, coalesce(sum(paid_cents), 0) as collected')
            ->first();

        $orders = (int) ($row->orders ?? 0);
        $sold = (int) ($row->sold ?? 0);
        $collected = (int) ($row->collected ?? 0);

        return [
            'orders' => $orders,
            'soldCents' => $sold,
            'collectedCents' => $collected,
            // What is still owed. Never negative: an overpayment is change, not a debt
            // in reverse.
            'outstandingCents' => max(0, $sold - $collected),
            // The average ticket, which says whether pushing combos is worth it. Zero
            // orders means zero, not a division by zero.
            'averageTicketCents' => $orders === 0 ? 0 : intdiv($sold, $orders),
            'cancelled' => (int) $this->db->table('orders')
                ->where('status', self::CANCELLED)
                ->whereBetween('placed_at', [$from, $to])
                ->count(),
        ];
    }

    /**
     * Where it came in: counter, portal, WhatsApp. One of the first things an
     * owner wants to know after switching on a new channel.
     *
     * @return list<array<string, mixed>>
     */
    private function byChannel(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->sales($from, $to)
            ->selectRaw('channel, count(*) as orders, coalesce(sum(total_cents), 0) as total')
            ->groupBy('channel')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'channel' => $row->channel,
                'orders' => (int) $row->orders,
                'totalCents' => (int) $row->total,
            ])->all();
    }

    /**
     * What sells most, grouped by the NAME copied onto the line rather than by
     * product: renaming "Arepa" to "Arepa grande" with a new price makes two
     * different offers, and merging them would hide the effect being measured.
     *
     * @return list<array<string, mixed>>
     */
    private function byProduct(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $limit = (int) $this->capabilities->get()->setting('reports.top_products', 10);

        return $this->db->table('order_items')
            ->join('orders', function ($join): void {
                // Composite, like all of them: joining on `order_id` alone would mix
                // tenants if this were ever queried without RLS.
                $join->on('orders.id', '=', 'order_items.order_id')
                    ->on('orders.tenant_id', '=', 'order_items.tenant_id');
            })
            ->whereNotNull('orders.confirmed_at')
            ->where('orders.status', '!=', self::CANCELLED)
            ->whereBetween('orders.confirmed_at', [$from, $to])
            ->selectRaw('order_items.product_name as name, sum(order_items.quantity) as quantity, coalesce(sum(order_items.line_total_cents), 0) as total')
            ->groupBy('order_items.product_name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'name' => $row->name,
                'quantity' => (int) $row->quantity,
                'totalCents' => (int) $row->total,
            ])->all();
    }

    /**
     * What time people come in, in the TENANT's local time.
     *
     * All 24 hours always, with zero where there was nothing: a screen that
     * fills the gaps would fill them differently from the bot or a spreadsheet.
     *
     * @return list<array<string, mixed>>
     */
    private function byHour(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $tz = $this->context->current()->timezone;

        $rows = $this->sales($from, $to)
            ->selectRaw(
                'extract(hour from placed_at at time zone ?) as hour, count(*) as orders, coalesce(sum(total_cents), 0) as total',
                [$tz],
            )
            ->groupBy('hour')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->hour);

        $hours = [];

        for ($hour = 0; $hour <= 23; $hour++) {
            $row = $rows->get($hour);

            $hours[] = [
                'hour' => $hour,
                'orders' => (int) ($row->orders ?? 0),
                'totalCents' => (int) ($row->total ?? 0),
            ];
        }

        return $hours;
    }

    /**
     * How they pay. Confirmed payments only: a mobile payment awaiting review
     * is not money yet.
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
            ->where('orders.status', '!=', self::CANCELLED)
            ->whereBetween('orders.confirmed_at', [$from, $to])
            ->selectRaw('order_payments.method as method, count(*) as times, coalesce(sum(order_payments.amount_cents), 0) as total')
            ->groupBy('order_payments.method')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'method' => $row->method,
                'count' => (int) $row->times,
                'totalCents' => (int) $row->total,
            ])->all();
    }

    /**
     * The base of every query: the orders that count as a sale, written once.
     *
     * If each report defined "sale" on its own, the summary total would not
     * match the sum by channel and nobody would know which to believe.
     */
    private function sales(DateTimeImmutable $from, DateTimeImmutable $to): Builder
    {
        return $this->db->table('orders')
            ->whereNotNull('confirmed_at')
            ->where('status', '!=', self::CANCELLED)
            ->whereBetween('confirmed_at', [$from, $to]);
    }

    /**
     * The shortcuts the screen uses, resolved on the SERVER in the tenant's
     * local time — computing "today" on the phone would use the phone's zone.
     *
     * Returned in UTC, which is the part that took finding: the query builder
     * serialises a date as `Y-m-d H:i:s` and throws the timezone away, so
     * PostgreSQL reads it in the session zone. A "today" computed in Caracas and
     * sent unconverted shifted four hours, and looked correct until 8pm.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public function range(string $preset): array
    {
        $tz = $this->context->current()->timezone;
        $now = Carbon::now($tz);

        [$from, $until] = match ($preset) {
            'yesterday' => [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };

        // The TENANT's day, expressed as the instant the database understands.
        return [$from->utc()->toDateTimeImmutable(), $until->utc()->toDateTimeImmutable()];
    }
}
