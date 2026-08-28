<?php

declare(strict_types=1);

namespace Modules\Portal\Application\UseCases;

use App\Models\Orders\OrderModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Orders\Application\UseCases\RegisterPayment;
use Modules\Portal\Domain\Exceptions\ReceiptRefused;
use Platform\Tenancy\TenantContext;

/**
 * El cliente manda la foto del pago móvil.
 *
 * **El archivo va a disco privado, no a `public/`.** Un comprobante lleva el
 * nombre de quien pagó, su cédula, su teléfono y el saldo de su cuenta. Una URL
 * pública —aunque tenga un nombre imposible de adivinar— es una URL que acaba
 * en el historial del navegador, en el chat donde alguien la reenvía, y en el
 * índice de cualquier buscador que la encuentre. Para verlo hay que ser del
 * negocio y tener permiso para confirmar pagos.
 *
 * **El pago se registra a la espera de revisión, no confirmado.** Que llegue
 * una foto no significa que el dinero llegó: alguien del negocio mira su cuenta
 * y dice que sí. No hay API bancaria fiable que preguntar, y fingir que la hay
 * sería peor que asumirlo.
 */
final class AttachReceipt
{
    private const DISK = 'local';

    public function __construct(
        private readonly RegisterPayment $payments,
        private readonly TenantContext $context,
    ) {}

    public function execute(OrderModel $order, UploadedFile $file, ?string $reference = null): OrderModel
    {
        if ($order->status->value !== 'pending_payment') {
            // Ya se pagó, ya se canceló, o ya está en la cocina. Mandar otra
            // foto no cambia nada y confundiría al negocio.
            throw ReceiptRefused::orderNotWaiting();
        }

        /*
         * La ruta lleva el negocio delante.
         *
         * Sirve para dos cosas nada decorativas: dar de baja a un cliente es
         * borrar una carpeta, y un fallo que mezclara identificadores dejaría
         * archivos de dos negocios en el mismo sitio — donde se nota enseguida.
         */
        $path = $file->store("receipts/{$this->context->id()}/{$order->id}", self::DISK);

        if ($path === false) {
            throw ReceiptRefused::couldNotStore();
        }

        return $this->payments->execute(
            orderId: (string) $order->id,
            method: 'pago_movil',
            // Lo que falta por pagar, que en un pedido del portal es todo.
            amountCents: (int) $order->total_cents - (int) $order->paid_cents,
            reference: $reference,
            receiptUrl: $path,
        );
    }

    /** Dónde está el archivo de un comprobante, para servirlo a quien pueda verlo. */
    public static function disk(): string
    {
        return self::DISK;
    }

    public static function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }
}
