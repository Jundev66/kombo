<?php

declare(strict_types=1);

namespace App\Models\Channels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Un hilo con un cliente.
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
     * Se ordena por `created_at` **y por `id`**: las marcas de tiempo se
     * guardan con precisión de segundos, y dos mensajes del mismo segundo
     * —que es exactamente lo que pasa cuando el bot contesta— empatan. El
     * uuid7 desempata bien porque lleva el tiempo dentro.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(MessageModel::class, 'conversation_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
