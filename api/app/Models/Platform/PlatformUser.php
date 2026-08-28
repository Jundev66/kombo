<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Un super administrador.
 *
 * **No es un usuario de ningún negocio**, y por eso no lleva `BelongsToTenant`
 * ni RLS: es de la plataforma. Confundir las dos cosas es cómo se acaba dando
 * acceso a la facturación de todos los clientes al empleado de uno.
 */
#[Fillable(['name', 'email', 'password', 'is_active', 'last_login_at'])]
class PlatformUser extends Authenticatable
{
    use HasApiTokens, Notifiable, UsesUuidV7;

    protected $table = 'platform_users';

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'immutable_datetime',
        ];
    }
}
