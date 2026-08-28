<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * Un usuario pertenece a UN negocio.
 *
 * `tenant_id` NO es asignable en masa, y eso lo vigila una prueba de
 * arquitectura: `User::create($request->all())` con el negocio dentro sería
 * una petición HTTP escribiendo una fila a nombre de otro. En Fase 1 lo
 * rellena solo el trait BelongsToTenant al crear.
 */
#[Fillable(['name', 'email', 'password', 'pin_hash', 'is_active'])]
#[Hidden(['password', 'pin_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * UUID v7 y no v4: lleva el tiempo en los bits altos, así conserva el
     * orden de creación y no fragmenta el índice como lo haría uno aleatorio.
     * En una tabla que crece todos los días, eso se nota.
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            // El PIN se guarda con hash, igual que la contraseña. Que sean
            // cuatro dígitos no lo hace menos secreto: autoriza anular ventas.
            'pin_hash' => 'hashed',
        ];
    }
}
