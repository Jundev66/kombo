<?php

declare(strict_types=1);

namespace Modules\Portal\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Portal\Application\UseCases\AttachReceipt;
use Modules\Portal\Interfaces\Http\Resources\PublicOrderResource;

/**
 * The customer sends the photo of their mobile payment, identified only by
 * their own order's token. Whoever holds the link can upload to THAT order and
 * no other.
 */
final class ReceiptController
{
    public function __invoke(Request $request, string $token, AttachReceipt $attach): JsonResponse
    {
        $data = $request->validate([
            /*
             * A photo, and with a ceiling. `image` validates the content, not
             * the extension, and 8 MB covers a phone screenshot while stopping
             * the first curious visitor uploading a film.
             */
            'receipt' => ['required', 'image', 'max:8192'],

            // The bank reference: the last digits the tenant looks for on its
            // statement. Optional, because the screenshot usually carries it and
            // requiring it would mean copying it by hand from an image.
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $order = PortalOrderController::byToken($token);

        $updated = $attach->execute($order, $data['receipt'], $data['reference'] ?? null);

        return response()->json(['data' => PublicOrderResource::make($updated)]);
    }
}
