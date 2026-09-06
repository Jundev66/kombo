<?php

declare(strict_types=1);

namespace App\Models\Channels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * A thread with a customer.
 */
#[Fillable([
    'channel', 'external_chat_id', 'customer_name', 'customer_phone',
    'is_human_takeover', 'takeover_at', 'state', 'state_data', 'last_message_at',
])]
class ConversationModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'conversations';

    protected function casts(): array
    {
        return [
            'is_human_takeover' => 'boolean',
            'state_data' => 'array',
            'takeover_at' => 'immutable_datetime',
            'last_message_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<MessageModel, $this>
     *
     * Ordered by `created_at` AND by `id`: timestamps have second precision, and
     * two messages in the same second — what happens when the bot replies — tie.
     * The uuid7 breaks the tie correctly because it carries the time inside.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(MessageModel::class, 'conversation_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
