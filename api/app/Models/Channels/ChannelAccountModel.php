<?php

declare(strict_types=1);

namespace App\Models\Channels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A tenant's account on a channel.
 *
 * Credentials are stored ENCRYPTED: what sits in here is the permanent token
 * that can write to every customer in the tenant's name, and a leaked dump must
 * not also be a list of ready-to-use tokens.
 */
#[Fillable([
    'channel', 'external_id', 'label', 'credentials', 'webhook_secret',
    'is_active', 'last_message_at',
])]
class ChannelAccountModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'channel_accounts';

    protected $hidden = ['credentials', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            // `encrypted:array` stores encrypted JSON and hands back an array.
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_message_at' => 'immutable_datetime',
        ];
    }

    /** A single credential, without recalling the shape of the array. */
    public function credential(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
