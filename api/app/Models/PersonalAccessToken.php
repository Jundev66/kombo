<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A tablet's access token, tied to ITS tenant.
 *
 * It replaces Sanctum's, whose table has no `tenant_id`, so a valid token would
 * work on any subdomain. Here the token lives in a tenant table under RLS: one
 * tenant's kitchen token does not exist for another — not forbidden, not found.
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
