<?php

declare(strict_types=1);

namespace App\Models\Orders;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * An add-on to a line: "no onion", "extra cheese".
 *
 * `price_delta_cents` can be NEGATIVE. The name is stored as a copy.
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
