<?php

declare(strict_types=1);

namespace Modules\Orders\Interfaces\Http\Controllers;

use App\Models\Orders\OrderPaymentModel;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La foto del comprobante, para quien tiene que decidir si el dinero llegó.
 *
 * **Se sirve por aquí y no por una URL de archivo.** El comprobante lleva el
 * nombre de quien pagó, su cédula y el saldo de su cuenta: pasar por el
 * controlador es lo que hace que verlo exija estar dentro del negocio, tener
 * permiso para confirmar pagos, y que RLS decida si ese pago es tuyo.
 *
 * Una URL en `public/` no puede comprobar nada de eso. Ni siquiera una con un
 * nombre imposible de adivinar: esa URL acaba en el historial del navegador y
 * en el chat donde alguien la reenvía.
 */
final class ReceiptViewController
{
    public function __invoke(string $orderId, string $paymentId): StreamedResponse
    {
        // El pago tiene que ser de ESE pedido. Sin la comprobación, un id de
        // pago suelto serviría para ver el comprobante de otro pedido — y RLS
        // no lo impediría, porque ambos son del mismo negocio.
        $payment = OrderPaymentModel::where('order_id', $orderId)->find($paymentId)
            ?? throw new NotFoundHttpException('Ese pago no existe en este pedido.');

        $path = $payment->receipt_url;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            throw new NotFoundHttpException('Ese pago no tiene comprobante.');
        }

        // `inline`: se abre en la pantalla, no se descarga. Quien confirma un
        // pago quiere mirarlo tres segundos, no acumular archivos.
        return Storage::disk('local')->response($path, headers: [
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
