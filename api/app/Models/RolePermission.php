<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Un permiso concedido a un rol.
 *
 * `requires_authorization` es el tercer estado, el que no tienen los sistemas
 * de permisos al uso: quien lo tiene puede **iniciar** la acción, pero se
 * ejecuta con el PIN de alguien que sí puede, y queda registrada a nombre de
 * quien autorizó.
 */
#[Fillable(['role_id', 'permission', 'requires_authorization'])]
class RolePermission extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'role_permissions';

    protected function casts(): array
    {
        return ['requires_authorization' => 'boolean'];
    }
}
