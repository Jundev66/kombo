<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Un usuario pertenece a UN negocio.
 *
 * El correo es único DENTRO del negocio, no en todo el sistema. Es lo que
 * permite que la misma persona trabaje en dos locales, y que al iniciar sesión
 * no haya que preguntar «¿a cuál de tus negocios?»: el subdominio ya lo dijo.
 *
 * `tenant_id` NO es asignable en masa —lo vigila una prueba de arquitectura— y
 * lo rellena solo el trait BelongsToTenant al crear.
 */
#[Fillable(['name', 'email', 'password', 'pin_hash', 'is_active'])]
#[Hidden(['password', 'pin_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, Notifiable, UsesUuidV7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            // El PIN se guarda con hash igual que la contraseña. Que sean
            // cuatro dígitos no lo hace menos secreto: autoriza anular ventas.
            'pin_hash' => 'hashed',
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function isOwner(): bool
    {
        return $this->roles->contains(fn (Role $role): bool => $role->is_owner);
    }

    /**
     * Los permisos de este usuario.
     *
     * El dueño devuelve `['*']` en vez de una lista: se EXPANDE después contra
     * los módulos que el negocio tenga encendidos hoy. Si se le guardaran los
     * permisos uno a uno, el día que encendiera un módulo nuevo no podría
     * usarlo hasta que alguien le añadiera los permisos a mano.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if ($this->isOwner()) {
            return ['*'];
        }

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('permission'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Los que puede INICIAR pero no ejecutar solo.
     *
     * La caja necesita esta lista para saber cuándo abrir el diálogo del PIN
     * antes de intentar la acción, en vez de tras un rechazo del servidor.
     *
     * @return list<string>
     */
    public function permissionsNeedingAuthorization(): array
    {
        if ($this->isOwner()) {
            return [];
        }

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->where('requires_authorization', true)->pluck('permission'))
            ->unique()
            ->values()
            ->all();
    }

    public function authorizesWithPin(string $pin): bool
    {
        return $this->pin_hash !== null && Hash::check($pin, $this->pin_hash);
    }
}
