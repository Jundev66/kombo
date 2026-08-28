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
 * Cambiar el precio de un producto.
 *
 * Tiene caso de uso propio, y no es ceremonia. Cambiar precios es la vía
 * natural para regalar mercancía, así que va con **su propio permiso**
 * (`catalog.change_price`, aparte de `catalog.manage`) y **siempre deja rastro
 * en la bitácora con el antes y el después**. Quien arregla una descripción no
 * tiene por qué poder bajar la parrilla a un dólar.
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

        $antes = $product->price()->cents;

        // La entidad decide si esto cuenta como un cambio. Si el precio es el
        // mismo, no mueve la fecha — y aquí tampoco se anota nada, porque una
        // bitácora llena de «cambió el precio de 3,00 a 3,00» es una bitácora
        // que nadie lee.
        $product->changePriceTo(Money::fromCents($newPriceCents));

        if ($product->price()->cents === $antes) {
            return $model;
        }

        $model->price_cents = $product->price()->cents;
        $model->price_updated_at = $product->priceUpdatedAt();
        $model->save();

        $this->audit->record(
            action: 'catalog.price_changed',
            entityType: 'product',
            entityId: (string) $model->id,
            before: ['price_cents' => $antes],
            after: ['price_cents' => $product->price()->cents],
        );

        return $model;
    }
}
