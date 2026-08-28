<?php

declare(strict_types=1);

namespace Platform\Auth\Http;

use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Platform\Audit\AuditLogger;

final class LogoutController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Se audita ANTES: después ya no hay usuario del que sacar el nombre,
        // y una bitácora con «alguien cerró sesión» no sirve de nada.
        $this->audit->record('auth.logout');

        // Token (caja o cocina): se revoca sólo el de ESTA pantalla, no todos
        // los del usuario. Cerrar el turno en la caja no puede echar de la
        // cocina a la misma persona.
        //
        // Con sesión por cookie, Sanctum devuelve un `TransientToken` que no
        // existe en la base y no se puede borrar. Es el caso del panel.
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Sin esto la sesión está cerrada pero `/me` sigue diciendo que hay
        // alguien dentro, porque el guard memorizó al usuario en esta misma
        // petición. Sólo se nota fuera de php-fpm, y por eso muerde tarde.
        Auth::forgetGuards();

        return response()->json(['ok' => true]);
    }
}
