<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A role: a set of permissions, within one tenant.
 *
 * System roles arrive with the industry pack at sign-up and cannot be edited or
 * deleted; the owner can create their own on top.
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
