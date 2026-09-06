<?php

declare(strict_types=1);

namespace App\Models\Orders;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * One line of the order. Name and price COPIED from the catalog at order time:
 * the product may be renamed or disappear, the order may not.
 */
#[Fillable(['order_id', 'product_id', 'product_name', 'unit_price_cents',
    'quantity', 'modifiers_total_cents', 'line_total_cents', 'notes', 'sort_order'])]
class OrderItemModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'order_items';

    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'quantity' => 'integer',
            'modifiers_total_cents' => 'integer',
            'line_total_cents' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<OrderItemModifierModel, $this> */
    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifierModel::class, 'order_item_id')->orderBy('sort_order');
    }
}
