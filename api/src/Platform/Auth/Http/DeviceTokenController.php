<?php

declare(strict_types=1);

namespace Platform\Auth\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Platform\Audit\AuditLogger;

/**
 * La segunda puerta: dar de alta una pantalla. **Una sola vez en su vida.**
 *
 * La hace alguien con correo y contraseña —el dueño, el encargado— cuando se
 * pone la tablet en la cocina o la PC en el mostrador. Devuelve un token que
 * se queda en esa máquina.
 *
 * Ese token **no opera nada por sí solo**: su única habilidad es `device`, que
 * sirve para pedir la lista de nombres y validar un PIN. Y punto.
 *
 * La razón es concreta: ese token vive en una máquina de local que se presta,
 * se roba y a veces se vende. Si sirviera para vender o para anular, robarla
 * sería robar el negocio.
 */
final class DeviceTokenController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device' => ['required', 'string', 'max:60'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user === null || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Ese correo y esa contraseña no entran.',
            ]);
        }

        // Dar de alta otra vez la misma pantalla revoca el token anterior. Es
        // lo que quieres si la tablet se perdió y estás configurando la nueva.
        $user->tokens()->where('name', $data['device'])->delete();

        $token = $user->createToken($data['device'], ['device']);

        $this->audit->record(
            action: 'auth.device_registered',
            entityType: 'device',
            after: ['device' => $data['device']],
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'device' => $data['device'],
        ]);
    }
}
