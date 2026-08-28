<?php

declare(strict_types=1);

namespace Modules\Catalog\Interfaces\Http\Resources;

use App\Models\Catalog\ProductModel;

/**
 * Cómo se ve un producto hacia el cliente.
 *
 * En camelCase, porque lo consume TypeScript. Y **siempre en centavos**: el
 * importe se formatea en el borde, en el componente que lo pinta, nunca antes.
 * Mandar «12,30» obligaría al cliente a re-parsearlo para sumar, que es
 * exactamente donde vuelven a aparecer los errores de coma flotante.
 */
final class ProductResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(ProductModel $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'photoUrl' => $product->photo_url,

            'priceCents' => $product->price_cents,
            'currency' => $product->currency,
            // Para que el dueño vea de un vistazo qué lleva meses sin revisar.
            // En un país con inflación, eso es la diferencia entre ganar y
            // regalar.
            'priceUpdatedAt' => $product->price_updated_at?->toAtomString(),

            'categoryId' => $product->category_id,
            'prepMinutes' => $product->prep_minutes,
            'isActive' => $product->is_active,

            'tracksStock' => $product->track_stock,
            'stockQty' => $product->stock_qty,
            // Resuelto en el servidor: la pantalla no tiene que saber que
            // «sin cuenta de existencias» significa «siempre hay».
            'isSoldOut' => $product->track_stock && ($product->stock_qty ?? 0) <= 0,

            'sortOrder' => $product->sort_order,

            'modifierGroupIds' => $product->relationLoaded('modifierGroups')
                ? $product->modifierGroups->pluck('id')->all()
                : null,
        ];
    }
}
