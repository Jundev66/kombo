<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Una sección de la carta. Existe para que la caja tenga menos que mirar:
 * arepas, bebidas, postres. Sin categorías, una carta de sesenta productos es
 * una lista imposible de recorrer con un cliente delante.
 */
#[Fillable(['name', 'sort_order', 'is_active'])]
class CategoryModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'categories';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ProductModel, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(ProductModel::class, 'category_id')->orderBy('sort_order');
    }
}
