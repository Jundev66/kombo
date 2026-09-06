<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\UseCases;

use App\Models\Catalog\ProductModel;
use Modules\Catalog\Application\Exceptions\ProductNotFound;
use Modules\Catalog\Domain\Entities\Product;
use Modules\Catalog\Domain\ValueObjects\PrepTime;
use Modules\Catalog\Domain\ValueObjects\ProductName;
use Modules\Catalog\Domain\ValueObjects\StockPolicy;
use Platform\Audit\AuditLogger;
use Shared\Domain\ValueObjects\Money;

/**
 * Changing a product's price.
 *
 * Its own use case because changing prices is the natural way to give
 * merchandise away: separate permission (`catalog.change_price`) and always a
 * before/after entry in the audit log.
 */
final class ChangePrice
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(string $productId, int $newPriceCents): ProductModel
    {
        $model = ProductModel::find($productId) ?? throw new ProductNotFound;

        $product = Product::rehydrate(
            id: (string) $model->id,
            name: ProductName::of((string) $model->name),
            price: Money::fromCents((int) $model->price_cents, (string) $model->currency),
            stock: StockPolicy::from((bool) $model->track_stock, $model->stock_qty),
            prepTime: PrepTime::ofMinutes($model->prep_minutes),
            categoryId: $model->category_id,
            description: $model->description,
            photoUrl: $model->photo_url,
            active: (bool) $model->is_active,
            priceUpdatedAt: $model->price_updated_at,
        );

        $before = $product->price()->cents;

        // The entity decides whether this counts as a change. An unchanged price
        // moves no date and logs nothing: "changed from 3.00 to 3.00" is noise.
        $product->changePriceTo(Money::fromCents($newPriceCents));

        if ($product->price()->cents === $before) {
            return $model;
        }

        $model->price_cents = $product->price()->cents;
        $model->price_updated_at = $product->priceUpdatedAt();
        $model->save();

        $this->audit->record(
            action: 'catalog.price_changed',
            entityType: 'product',
            entityId: (string) $model->id,
            before: ['price_cents' => $before],
            after: ['price_cents' => $product->price()->cents],
        );

        return $model;
    }
}
