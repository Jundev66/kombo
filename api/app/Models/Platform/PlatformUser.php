<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A platform administrator.
 *
 * Not a user of any tenant, which is why it carries neither `BelongsToTenant`
 * nor RLS. Confusing the two is how one customer's employee ends up with access
 * to everybody's billing.
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
