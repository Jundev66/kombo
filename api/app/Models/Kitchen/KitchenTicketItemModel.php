<?php

declare(strict_types=1);

namespace App\Models\Kitchen;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Una línea de la comanda.
 *
 * `modifiers` es un array de TEXTO ya resuelto —«Sin cebolla», «Extra
 * queso»—, no referencias. La cocina lee eso; ir a buscar un identificador
 * mientras se cocina no es una opción.
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
