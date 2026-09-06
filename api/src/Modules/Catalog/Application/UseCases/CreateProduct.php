<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\UseCases;

use App\Models\Catalog\ProductModel;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Exceptions\PlanLimitReached;
use Modules\Catalog\Domain\Entities\Product;
use Modules\Catalog\Domain\ValueObjects\PrepTime;
use Modules\Catalog\Domain\ValueObjects\StockPolicy;
use Platform\Audit\AuditLogger;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Tenancy\TenantContext;
use Shared\Domain\ValueObjects\Money;

/**
 * Adding something to the menu. The rules live in the domain entity; this only
 * orchestrates: check the plan ceiling, build, save, log.
 */
final class CreateProduct
{
    public function __construct(
        private readonly CurrentCapabilities $capabilities,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $modifierGroupIds
     */
    public function execute(
        string $name,
        int $priceCents,
        ?string $categoryId = null,
        ?string $description = null,
        ?string $photoUrl = null,
        ?int $prepMinutes = null,
        bool $trackStock = false,
        ?int $stockQty = null,
        array $modifierGroupIds = [],
    ): ProductModel {
        $this->assertFitsInPlan();

        // The domain validates BEFORE the database is touched. If anything fails,
        // nothing is written.
        $product = Product::create(
            id: (string) Str::uuid7(),
            name: $name,
            price: Money::fromCents($priceCents),
            categoryId: $categoryId,
            description: $description,
            photoUrl: $photoUrl,
            stock: StockPolicy::from($trackStock, $stockQty),
            prepTime: PrepTime::ofMinutes($prepMinutes),
        );

        $model = new ProductModel;
        $model->id = $product->id;
        $model->fill([
            'category_id' => $product->categoryId(),
            'name' => $product->name()->value,
            'description' => $product->description(),
            'photo_url' => $product->photoUrl(),
            'price_cents' => $product->price()->cents,
            'currency' => $product->price()->currency,
            'price_updated_at' => $product->priceUpdatedAt(),
            'prep_minutes' => $product->prepTime()->minutes,
            'is_active' => $product->isActive(),
            'track_stock' => $product->stock()->tracked,
            'stock_qty' => $product->stock()->quantity,
        ]);
        $model->save();

        if ($modifierGroupIds !== []) {
            $model->modifierGroups()->sync($this->pivotRows($modifierGroupIds));
        }

        $this->audit->record(
            action: 'catalog.product_created',
            entityType: 'product',
            entityId: $product->id,
            after: ['name' => $product->name()->value, 'price_cents' => $product->price()->cents],
        );

        return $model;
    }

    private function assertFitsInPlan(): void
    {
        $limits = $this->capabilities->get()->limits;

        // ALL of them count, active and inactive: deactivating does not free a
        // slot, because the data is still there. Real deletion does.
        if ($limits->exceeds($limits->maxProducts, ProductModel::count())) {
            throw new PlanLimitReached((int) $limits->maxProducts);
        }
    }

    /**
     * @param  list<string>  $groupIds
     * @return array<string, array<string, mixed>>
     */
    private function pivotRows(array $groupIds): array
    {
        $rows = [];
        $tenantId = app(TenantContext::class)->id();

        foreach (array_values($groupIds) as $index => $groupId) {
            // The pivot is a tenant table with its own id and `tenant_id`, so `sync()`
            // needs us to supply them.
            $rows[$groupId] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'sort_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }
}
