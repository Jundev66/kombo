<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * El token de acceso de una tablet, atado a SU negocio.
 *
 * Sustituye al de Sanctum. Con el original, un token válido serviría en
 * cualquier subdominio: la tabla no tiene `tenant_id` y nadie lo comprueba.
 * Aquí el token vive en una tabla de negocio con RLS, así que el de la cocina
 * de El Sazón no existe para La Esquina — no es que esté prohibido, es que la
 * consulta no lo encuentra.
 */
#[Fillable(['name', 'token', 'abilities', 'device_id', 'expires_at'])]
class PersonalAccessToken extends SanctumToken
{
    use BelongsToTenant, UsesUuidV7;

    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
