<?php

declare(strict_types=1);

namespace Modules\Catalog\Interfaces\Http\Resources;

use App\Models\Catalog\ProductModel;

/**
 * How a product looks to the client: camelCase, and always in cents.
 *
 * Amounts are formatted at the edge, in the component that paints them. Sending
 * "12.30" would force the client to re-parse it to add up.
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
            // So the owner can see at a glance what has gone months without review.
            'priceUpdatedAt' => $product->price_updated_at?->toAtomString(),

            'categoryId' => $product->category_id,
            'prepMinutes' => $product->prep_minutes,
            'isActive' => $product->is_active,

            'tracksStock' => $product->track_stock,
            'stockQty' => $product->stock_qty,
            // Resolved on the server: the screen need not know that "stock untracked"
            // means "there is always some".
            'isSoldOut' => $product->track_stock && ($product->stock_qty ?? 0) <= 0,

            'sortOrder' => $product->sort_order,

            'modifierGroupIds' => $product->relationLoaded('modifierGroups')
                ? $product->modifierGroups->pluck('id')->all()
                : null,
        ];
    }
}
