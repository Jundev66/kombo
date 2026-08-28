<?php

declare(strict_types=1);

namespace Modules\Portal\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Portal\Application\UseCases\AttachReceipt;
use Modules\Portal\Interfaces\Http\Resources\PublicOrderResource;

/**
 * El cliente manda la foto de su pago móvil.
 *
 * Se identifica con el token de su propio pedido, que es lo único que tiene:
 * no hay cuenta ni sesión. Quien tenga el enlace puede subir un comprobante a
 * ESE pedido y a ninguno más.
 */
final class ReceiptController
{
    public function __invoke(Request $request, string $token, AttachReceipt $attach): JsonResponse
    {
        $data = $request->validate([
            /*
             * Foto, y con techo.
             *
             * `image` valida el contenido, no la extensión: un `.jpg` que en
             * realidad es otra cosa no pasa. Y 8 MB porque una captura de
             * pantalla de un teléfono moderno ronda los 3, mientras que sin
             * límite el primer curioso sube una película.
             */
            'receipt' => ['required', 'image', 'max:8192'],

            // La referencia bancaria: son los últimos dígitos que el negocio
            // busca en su cuenta. Opcional porque a veces la captura ya la
            // trae, y obligarla sería que el cliente la copie a mano de una
            // imagen que tiene delante.
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $order = PortalOrderController::byToken($token);

        $updated = $attach->execute($order, $data['receipt'], $data['reference'] ?? null);

        return response()->json(['data' => PublicOrderResource::make($updated)]);
    }
}
