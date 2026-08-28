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
 * Editar un producto: todo menos el precio.
 *
 * El precio va por `ChangePrice`, con su permiso aparte y su rastro. Que este
 * caso de uso NO pueda tocarlo es lo que hace real esa separación: si aceptara
 * un `price_cents`, el permiso extra sería decorativo.
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

        $antes = ['name' => $model->name, 'is_active' => $model->is_active];

        if ($name !== null) {
            // Se pasa por el value object aunque vaya a una columna de texto:
            // es donde vive la normalización de espacios y el mínimo de
            // longitud, y saltárselo aquí abriría la puerta a productos que la
            // creación no habría aceptado.
            $model->name = ProductName::of($name)->value;
        }

        if ($prepMinutes !== null) {
            $model->prep_minutes = PrepTime::ofMinutes($prepMinutes)->minutes;
        }

        if ($trackStock !== null) {
            // El value object descarta la cantidad si no se lleva la cuenta, y
            // así no queda el estado imposible «no cuenta pero quedan 7».
            $stock = StockPolicy::from($trackStock, $stockQty);
            $model->track_stock = $stock->tracked;
            $model->stock_qty = $stock->quantity;
        }

        // `func_get_args` no: se comprueba explícitamente para poder distinguir
        // «no lo mandaron» de «lo mandaron vacío a propósito» —quitar la foto,
        // sacarlo de su categoría—.
        foreach (['category_id' => $categoryId, 'description' => $description, 'photo_url' => $photoUrl] as $columna => $valor) {
            if ($valor !== null) {
                $model->{$columna} = $valor === '' ? null : $valor;
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
            before: $antes,
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
