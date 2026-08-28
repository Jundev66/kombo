<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Una respuesta posible: «sin cebolla», «extra queso», «término medio».
 *
 * `price_delta_cents` puede ser NEGATIVO: quitar el queso a veces descuenta.
 */
#[Fillable(['group_id', 'name', 'price_delta_cents', 'sort_order', 'is_active'])]
class ModifierModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'modifiers';

    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ModifierGroupModel, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroupModel::class, 'group_id');
    }
}
