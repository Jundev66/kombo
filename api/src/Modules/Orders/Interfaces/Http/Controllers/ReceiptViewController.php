<?php

declare(strict_types=1);

namespace Modules\Orders\Interfaces\Http\Controllers;

use App\Models\Orders\OrderPaymentModel;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The receipt photo, for whoever decides whether the money arrived.
 *
 * Served through the controller and not from a file URL: the receipt carries
 * the payer's name, ID number and account balance, so viewing it requires being
 * inside the tenant with permission to confirm payments. A `public/` URL checks
 * none of that, however unguessable its name.
 */
final class ReceiptViewController
{
    public function __invoke(string $orderId, string $paymentId): StreamedResponse
    {
        // The payment has to belong to THAT order: a loose payment id would
        // otherwise show another order's receipt, and RLS would not stop it
        // because both belong to the same tenant.
        $payment = OrderPaymentModel::where('order_id', $orderId)->find($paymentId)
            ?? throw new NotFoundHttpException('Ese pago no existe en este pedido.');

        $path = $payment->receipt_url;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            throw new NotFoundHttpException('Ese pago no tiene comprobante.');
        }

        // `inline`: it opens on screen. Whoever confirms a payment wants to look at
        // it for three seconds, not collect files.
        return Storage::disk('local')->response($path, headers: [
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
