<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Infraestructura, no dominio.
 *
 * El dominio (`Modules\Catalog\Domain\Entities\Product`) no sabe que esto
 * existe: es PHP puro y una prueba de arquitectura lo verifica. Esta clase
 * sólo sabe leer y escribir filas.
 */
#[Fillable([
    'category_id', 'name', 'description', 'photo_url',
    'price_cents', 'currency', 'price_updated_at',
    'prep_minutes', 'is_active', 'track_stock', 'stock_qty', 'sort_order',
])]
class ProductModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'products';

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'prep_minutes' => 'integer',
            'stock_qty' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'track_stock' => 'boolean',
            'price_updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CategoryModel, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoryModel::class, 'category_id');
    }

    /** @return BelongsToMany<ModifierGroupModel, $this> */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            ModifierGroupModel::class,
            'product_modifier_groups',
            'product_id',
            'group_id',
        )->orderBy('modifier_groups.sort_order');
    }
}
