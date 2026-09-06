<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\UseCases;

use App\Models\Catalog\ProductModel;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Exceptions\ProductNotFound;
use Modules\Catalog\Domain\ValueObjects\PrepTime;
use Modules\Catalog\Domain\ValueObjects\ProductName;
use Modules\Catalog\Domain\ValueObjects\StockPolicy;
use Platform\Audit\AuditLogger;
use Platform\Tenancy\TenantContext;

/**
 * Editing a product: everything except the price.
 *
 * The price goes through `ChangePrice`. That this use case cannot touch it is
 * what makes that separation real — accepting `price_cents` here would make the
 * extra permission decorative.
 *
 * @param  list<string>|null  $modifierGroupIds
 */
final class UpdateProduct
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(
        string $productId,
        ?string $name = null,
        ?string $categoryId = null,
        ?string $description = null,
        ?string $photoUrl = null,
        ?int $prepMinutes = null,
        ?bool $trackStock = null,
        ?int $stockQty = null,
        ?bool $isActive = null,
        ?array $modifierGroupIds = null,
    ): ProductModel {
        $model = ProductModel::find($productId) ?? throw new ProductNotFound;

        $before = ['name' => $model->name, 'is_active' => $model->is_active];

        if ($name !== null) {
            // Through the value object even though it lands in a text column: that is
            // where whitespace normalisation and the minimum length live.
            $model->name = ProductName::of($name)->value;
        }

        if ($prepMinutes !== null) {
            $model->prep_minutes = PrepTime::ofMinutes($prepMinutes)->minutes;
        }

        if ($trackStock !== null) {
            // The value object discards the quantity when stock is untracked, so the
            // impossible "untracked but 7 left" state never arises.
            $stock = StockPolicy::from($trackStock, $stockQty);
            $model->track_stock = $stock->tracked;
            $model->stock_qty = $stock->quantity;
        }

        // Checked explicitly rather than with `func_get_args`, to tell "not sent"
        // from "deliberately sent empty" — removing the photo, or the category.
        foreach (['category_id' => $categoryId, 'description' => $description, 'photo_url' => $photoUrl] as $column => $value) {
            if ($value !== null) {
                $model->{$column} = $value === '' ? null : $value;
            }
        }

        if ($isActive !== null) {
            $model->is_active = $isActive;
        }

        $model->save();

        if ($modifierGroupIds !== null) {
            $model->modifierGroups()->sync($this->pivotRows($modifierGroupIds));
        }

        $this->audit->record(
            action: 'catalog.product_updated',
            entityType: 'product',
            entityId: (string) $model->id,
            before: $before,
            after: ['name' => $model->name, 'is_active' => $model->is_active],
        );

        return $model;
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
