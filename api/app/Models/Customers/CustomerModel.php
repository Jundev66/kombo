<?php

declare(strict_types=1);

namespace App\Models\Customers;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Platform\Tenancy\Concerns\BelongsToTenant;
use Platform\Tenancy\Concerns\UsesUuidV7;

/**
 * Un cliente del negocio.
 *
 * El teléfono va cifrado y al lado su hash: el cifrado de Laravel no es
 * determinista, así que sin el hash no habría forma de encontrar a alguien por
 * su número sin descifrar la tabla entera.
 */
#[Fillable(['phone', 'phone_hash', 'name', 'notes', 'orders_count', 'spent_cents', 'last_order_at'])]
class CustomerModel extends Model
{
    use BelongsToTenant, UsesUuidV7;

    protected $table = 'customers';

    protected function casts(): array
    {
        return [
            'phone' => 'encrypted',
            'orders_count' => 'integer',
            'spent_cents' => 'integer',
            'last_order_at' => 'immutable_datetime',
        ];
    }

    /**
     * El hash con el que se busca.
     *
     * Con la clave de la aplicación por delante: sin ella, dos despliegues
     * distintos producirían el mismo hash para el mismo número, y ese hash
     * sería una tabla arcoíris trivial —los teléfonos tienen once dígitos—.
     */
    public static function hashOf(string $phone): string
    {
        $normalizado = preg_replace('/\D/', '', $phone) ?? $phone;

        return hash_hmac('sha256', $normalizado, (string) config('app.key'));
    }
}
