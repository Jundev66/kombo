<?php

declare(strict_types=1);

namespace Platform\Auth\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Quién puede entrar en esta pantalla.
 *
 * Se pide con el token del dispositivo y devuelve nombres para tocar, no un
 * campo de correo: en una cocina nadie va a escribir
 * `carlos@elsazon.test` con las manos ocupadas.
 *
 * **Nunca devuelve el hash del PIN**, ni quién lo tiene puesto, ni el correo.
 * Sólo lo justo para pintar una lista de botones: quién y con qué rol.
 */
final class StaffController
{
    public function __invoke(): JsonResponse
    {
        $staff = User::query()
            ->with('roles')
            ->where('is_active', true)
            // Sin PIN no puede entrar por aquí, así que no se muestra: un
            // botón que nunca funciona es peor que no tener el botón.
            ->whereNotNull('pin_hash')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'roleName' => $user->roles->first()?->name,
            ])
            ->values();

        return response()->json(['staff' => $staff]);
    }
}
