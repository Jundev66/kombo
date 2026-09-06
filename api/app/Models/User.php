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
 * A user belongs to ONE tenant, and the email is unique WITHIN it.
 *
 * That lets the same person work at two locations, and means signing in never
 * has to ask "which of your businesses?" — the subdomain already said.
 *
 * `tenant_id` is not mass assignable (an architecture test watches that); the
 * BelongsToTenant trait fills it in on create.
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
            // The PIN is hashed like the password. Four digits does not make it less
            // of a secret: it authorises voiding sales.
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
     * This user's permissions.
     *
     * An owner gets `['*']` rather than a list, expanded afterwards against
     * whichever modules the tenant has on today. Stored one by one, enabling a
     * new module would leave them unable to use it until somebody added them.
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
     * The ones they can START but not carry out alone.
     *
     * The till needs this to open the PIN dialog before attempting the action
     * rather than after the server rejects it.
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
