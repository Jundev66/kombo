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
 * Los pedidos, en un archivo que se abre en cualquier hoja de cálculo.
 *
 * Esto es lo que hace verdad la frase que está escrita en el middleware de
 * suspensión: **«un negocio suspendido lee y exporta»**. Sus pedidos son suyos
 * aunque nos deba tres meses, y sin un botón de exportar esa promesa era sólo
 * una frase bonita en un comentario.
 *
 * Se manda **en streaming**, fila a fila: un año de pedidos armado entero en
 * memoria tumba un contenedor de 512 MB, y justo el negocio que más datos tiene
 * es el que menos puede permitirse que se caiga.
 *
 * Con **BOM** al principio, que parece un detalle de nada y no lo es: sin él,
 * Excel abre el archivo en Windows y enseña «Reina Pepiáda».
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
            'periodo' => ['nullable', 'string', 'in:hoy,ayer,semana,mes'],
        ]);

        $periodo = $data['periodo'] ?? 'mes';
        [$desde, $hasta] = $report->range($periodo);

        $tenant = $this->context->current();
        $nombre = "pedidos-{$tenant->slug}-{$periodo}.csv";

        return response()->streamDownload(function () use ($tenant, $desde, $hasta): void {
            $salida = fopen('php://output', 'w');

            // El BOM: sin él, Excel en Windows destroza los acentos.
            fwrite($salida, "\xEF\xBB\xBF");

            fputcsv($salida, self::CABECERAS, ';');

            /*
             * Se vuelve a entrar en el negocio DENTRO del cuerpo.
             *
             * Esto se ejecuta cuando el servidor vacía la respuesta, que puede
             * ser después de que el middleware haya soltado el contexto — y sin
             * contexto, RLS devuelve cero filas y el archivo sale con la
             * cabecera y nada más. Un export vacío es peor que un error: el
             * negocio se lo lleva creyendo que ahí están sus pedidos.
             */
            $this->session->within($tenant->id, function () use ($salida, $desde, $hasta): void {
                OrderModel::query()
                    ->with(['items', 'payments'])
                    ->whereBetween('placed_at', [$desde, $hasta])
                    ->orderBy('placed_at')
                    // De cien en cien: lo que hace que esto no dependa de
                    // cuántos pedidos tenga el negocio.
                    ->chunk(100, function ($orders) use ($salida): void {
                        foreach ($orders as $order) {
                            fputcsv($salida, self::rowOf($order), ';');
                        }
                    });
            });

            fclose($salida);
        }, $nombre, [
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

            // Lo que llevaba, legible. El nombre COPIADO en la línea: un
            // pedido de hace seis meses se lee igual aunque el producto se
            // haya renombrado.
            $order->items->map(fn ($item): string => "{$item->quantity}x {$item->product_name}")->implode(' | '),

            self::money($order->delivery_fee_cents),
            self::money($order->total_cents),
            self::money($order->paid_cents),
            $order->payments->where('status', 'confirmed')->pluck('method')->implode(' | '),
        ];
    }

    /**
     * Con COMA decimal.
     *
     * Una hoja de cálculo configurada en español lee «12.30» como doce mil
     * trescientos, y eso convierte un reporte en una discusión.
     */
    private static function money(?int $cents): string
    {
        return number_format(((int) $cents) / 100, 2, ',', '');
    }
}
