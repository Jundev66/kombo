<?php

declare(strict_types=1);

namespace App\Models\Delivery;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Una zona de reparto: un barrio con su tarifa.
 */
#[Fillable(['name', 'fee_cents', 'estimated_minutes', 'is_active', 'sort_order'])]
class DeliveryZoneModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'delivery_zones';

    protected function casts(): array
    {
        return [
            'fee_cents' => 'integer',
            'estimated_minutes' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
