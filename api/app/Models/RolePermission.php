<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A permission granted to a role.
 *
 * `requires_authorization` is the third state ordinary permission systems lack:
 * the holder can START the action, but it runs against the PIN of someone who
 * can, and is recorded in that person's name.
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
