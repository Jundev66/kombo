<?php

declare(strict_types=1);

namespace App\Models\Channels;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * La cuenta de un negocio en un canal.
 *
 * **Las credenciales van cifradas en la base**, no en texto plano. Aquí dentro
 * está el token permanente con el que se puede escribir a todos los clientes
 * del negocio en su nombre: un volcado que se filtre no puede ser además una
 * lista de tokens listos para usar.
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
            // `encrypted:array` guarda un JSON cifrado y lo devuelve como
            // arreglo. Si mañana rota la clave de la aplicación, esto deja de
            // descifrarse — que es exactamente lo que tiene que pasar.
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_message_at' => 'immutable_datetime',
        ];
    }

    /** Una credencial suelta, sin tener que acordarse de la forma del arreglo. */
    public function credential(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
