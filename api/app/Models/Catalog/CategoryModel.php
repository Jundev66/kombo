<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A section of the menu, so the till has less to look at. A sixty-product menu
 * with no categories cannot be scanned with a customer standing there.
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
