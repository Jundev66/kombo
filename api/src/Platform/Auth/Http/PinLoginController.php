<?php

declare(strict_types=1);

namespace Platform\Auth\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Platform\Audit\Actor;
use Platform\Audit\AuditLogger;
use Platform\Capabilities\CurrentCapabilities;

/**
 * La tercera puerta: quién está en esta pantalla ahora mismo.
 *
 * Se toca el nombre, se teclean cuatro dígitos, y se entra. Devuelve un token
 * con la habilidad `station`, que sí opera — a nombre de la persona real, no
 * del dispositivo.
 *
 * El rate limit no es decorativo: **un PIN de cuatro dígitos son 10.000
 * combinaciones**, y sin tope probarlas todas es cuestión de un rato con un
 * script. Cinco intentos por minuto lo convierte en más de un día.
 */
final class PinLoginController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentCapabilities $capabilities,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'pin' => ['required', 'string', 'min:4', 'max:12'],
            'device' => ['required', 'string', 'max:60'],
        ]);

        $key = "pin:{$data['user_id']}|{$data['device']}";
        $maxAttempts = (int) $this->capabilities->get()->setting('core.pin_attempts', 5);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                'pin' => 'Demasiados intentos. Espera un momento.',
            ]);
        }

        $user = User::find($data['user_id']);

        if ($user === null || ! $user->is_active || ! $user->authorizesWithPin($data['pin'])) {
            RateLimiter::hit($key, decaySeconds: 60);

            throw ValidationException::withMessages([
                'pin' => 'Ese PIN no es. Inténtalo otra vez.',
            ]);
        }

        RateLimiter::clear($key);

        // Entrar de nuevo revoca el turno anterior en esta misma pantalla: dos
        // personas a la vez en la misma caja no es un escenario real, y dejar
        // el token viejo vivo sí lo es.
        $tokenName = "{$data['device']}·{$user->name}";
        $user->tokens()->where('name', $tokenName)->delete();

        $token = $user->createToken($tokenName, ['station']);

        $this->audit->record(
            action: 'auth.pin_login',
            entityType: 'device',
            after: ['device' => $data['device']],
            // A nombre de la PERSONA, no del token del dispositivo con el que
            // llegó la petición. Es todo el sentido de esta puerta.
            actor: new Actor((string) $user->getKey(), (string) $user->name),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
            ],
        ]);
    }
}
