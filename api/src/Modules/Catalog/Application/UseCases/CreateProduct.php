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
 * Añadir algo a la carta.
 *
 * Las reglas de qué es un producto válido viven en la entidad de dominio; aquí
 * sólo se orquesta: comprobar el techo del plan, construir, guardar, anotar.
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

        // El dominio valida ANTES de tocar la base: nombre, precio, coherencia
        // de las existencias. Si algo no cumple, no se escribe nada.
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

        // Se cuentan TODOS, activos e inactivos: desactivar no libera cupo,
        // porque el dato sigue ahí. Lo que libera cupo es borrar de verdad.
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
            // La tabla pivote es de negocio: lleva su propio id y su
            // `tenant_id`, así que `sync()` necesita que se los demos.
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
