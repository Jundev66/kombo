<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Un rol: un conjunto de permisos, dentro de un negocio.
 *
 * Los de sistema los trae el paquete del rubro al registrarse y no se editan
 * ni se borran. El dueño puede crear los suyos encima.
 */
#[Fillable(['code', 'name', 'is_system', 'is_owner'])]
class Role extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_owner' => 'boolean',
        ];
    }

    /** @return HasMany<RolePermission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }
}
