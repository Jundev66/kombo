<?php

declare(strict_types=1);

namespace App\Models\Channels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Un mensaje, en cualquiera de las dos direcciones.
 */
#[Fillable(['conversation_id', 'direction', 'content', 'message_type', 'external_id', 'metadata'])]
class MessageModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'messages';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
