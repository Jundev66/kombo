<?php

declare(strict_types=1);

namespace Modules\Reports\Interfaces\Http\Controllers;

use App\Models\Orders\OrderModel;
use Illuminate\Http\Request;
use Modules\Reports\Application\UseCases\SalesReport;
use Platform\Tenancy\TenantContext;
use Platform\Tenancy\TenantSession;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The orders, in a file that opens in any spreadsheet.
 *
 * This is what makes true the sentence written in the suspension middleware: **a
 * suspended business reads and exports**. Its orders are its own even if it owes
 * us three months, and without an export button that promise was just a nice
 * line in a comment.
 *
 * It is sent **streaming**, row by row: a year of orders assembled whole in
 * memory takes down a 512 MB container, and the business with the most data is
 * precisely the one that can least afford it going down.
 *
 * With a **BOM** at the start, which looks like nothing and is not: without it,
 * Excel opens the file on Windows and shows "Reina Pepiáda".
 */
final class ExportController
{
    private const CABECERAS = [
        'numero', 'fecha', 'estado', 'canal', 'entrega',
        'cliente', 'telefono', 'zona', 'direccion',
        'productos', 'delivery', 'total', 'pagado', 'metodos',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantSession $session,
    ) {}

    public function __invoke(Request $request, SalesReport $report): StreamedResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'string', 'in:today,yesterday,week,month'],
        ]);

        $period = $data['period'] ?? 'month';
        [$from, $until] = $report->range($period);

        $tenant = $this->context->current();
        $name = "pedidos-{$tenant->slug}-{$period}.csv";

        return response()->streamDownload(function () use ($tenant, $from, $until): void {
            $output = fopen('php://output', 'w');

            // The BOM: without it, Excel on Windows mangles the accents.
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, self::CABECERAS, ';');

            /*
             * The business is entered again INSIDE the body.
             *
             * This runs when the server flushes the response, which may be
             * after the middleware has released the context — and without
             * context, RLS returns zero rows and the file comes out with the
             * header and nothing else. An empty export is worse than an error:
             * the business takes it away believing their orders are in there.
             */
            $this->session->within($tenant->id, function () use ($output, $from, $until): void {
                OrderModel::query()
                    ->with(['items', 'payments'])
                    ->whereBetween('placed_at', [$from, $until])
                    ->orderBy('placed_at')
                    // A hundred at a time: what stops this depending on how
                    // many orders the business has.
                    ->chunk(100, function ($orders) use ($output): void {
                        foreach ($orders as $order) {
                            fputcsv($output, self::rowOf($order), ';');
                        }
                    });
            });

            fclose($output);
        }, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return list<string>
     */
    private static function rowOf(OrderModel $order): array
    {
        return [
            (string) $order->number,
            $order->placed_at?->format('Y-m-d H:i') ?? '',
            $order->status->label(),
            $order->channel,
            $order->service_type->label(),
            (string) $order->customer_name,
            (string) $order->customer_phone,
            (string) $order->delivery_zone_name,
            (string) $order->delivery_address,

            // What they took, readable. The name COPIED onto the line: an
            // order from six months ago reads the same even if the product
            // has been renamed.
            $order->items->map(fn ($item): string => "{$item->quantity}x {$item->product_name}")->implode(' | '),

            self::money($order->delivery_fee_cents),
            self::money($order->total_cents),
            self::money($order->paid_cents),
            $order->payments->where('status', 'confirmed')->pluck('method')->implode(' | '),
        ];
    }

    /**
     * With a decimal COMMA.
     *
     * A spreadsheet configured in Spanish reads "12.30" as twelve thousand
     * three hundred, and that turns a report into an argument.
     */
    private static function money(?int $cents): string
    {
        return number_format(((int) $cents) / 100, 2, ',', '');
    }
}
