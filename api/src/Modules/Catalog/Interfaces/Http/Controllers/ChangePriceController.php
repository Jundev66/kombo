<?php

declare(strict_types=1);

namespace Modules\Catalog\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Application\UseCases\ChangePrice;
use Modules\Catalog\Interfaces\Http\Resources\ProductResource;

/**
 * Changing the price gets its own route and permission
 * (`catalog.change_price`), because it is the natural way to give merchandise
 * away — and the audit log then says who touched it and when.
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
