<?php

declare(strict_types=1);

namespace Modules\Catalog\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Application\UseCases\ChangePrice;
use Modules\Catalog\Interfaces\Http\Resources\ProductResource;

/**
 * Cambiar el precio tiene su propia ruta y su propio permiso
 * (`catalog.change_price`).
 *
 * No es ceremonia: cambiar precios es la vía natural para regalar mercancía.
 * Quien arregla una descripción no tiene por qué poder bajar la parrilla a un
 * dólar, y cuando el margen del mes no cuadre, la bitácora dirá quién lo tocó
 * y cuándo.
 */
final class ChangePriceController
{
    public function __invoke(Request $request, string $id, ChangePrice $changePrice): JsonResponse
    {
        $data = $request->validate([
            'price_cents' => ['required', 'integer', 'min:0'],
        ]);

        $product = $changePrice->execute($id, $data['price_cents']);

        return response()->json(['data' => ProductResource::make($product)]);
    }
}
