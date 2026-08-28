<?php

declare(strict_types=1);

namespace App\Models\Orders;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Un agregado de una línea: «sin cebolla», «extra queso».
 *
 * `price_delta_cents` puede ser NEGATIVO. El nombre va copiado.
 */
#[Fillable(['order_item_id', 'modifier_id', 'name', 'price_delta_cents', 'sort_order'])]
class OrderItemModifierModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'order_item_modifiers';

    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
