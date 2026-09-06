<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Services;

use App\Models\Catalog\ProductModel;
use Modules\Catalog\Application\Contracts\ProductCatalog;
use Modules\Catalog\Application\Contracts\ProductSnapshot;
use Shared\Domain\ValueObjects\Money;

final class EloquentProductCatalog implements ProductCatalog
{
    public function find(string $productId): ?ProductSnapshot
    {
        $model = ProductModel::find($productId);

        return $model === null ? null : $this->toSnapshot($model);
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, ProductSnapshot>
     */
    public function findMany(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        // One query, indexed by id. The tenant filter is already applied by the
        // global scope and, above all, by RLS.
        return ProductModel::whereIn('id', $productIds)
            ->get()
            ->mapWithKeys(fn (ProductModel $m): array => [(string) $m->id => $this->toSnapshot($m)])
            ->all();
    }

    private function toSnapshot(ProductModel $model): ProductSnapshot
    {
        return new ProductSnapshot(
            id: (string) $model->id,
            name: (string) $model->name,
            price: Money::fromCents((int) $model->price_cents, (string) $model->currency),
            isActive: (bool) $model->is_active,
            tracksStock: (bool) $model->track_stock,
            stockQuantity: $model->stock_qty,
            prepMinutes: $model->prep_minutes,
        );
    }
}
