<?php

declare(strict_types=1);

namespace App\Models\Kitchen;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * One line of the kitchen ticket.
 *
 * `modifiers` is already-resolved TEXT — "No onion", "Extra cheese" — not
 * references: looking up an id while cooking is not an option.
 */
#[Fillable(['ticket_id', 'product_id', 'name', 'quantity', 'modifiers', 'notes', 'sort_order'])]
class KitchenTicketItemModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'kitchen_ticket_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'modifiers' => 'array',
            'sort_order' => 'integer',
        ];
    }
}
