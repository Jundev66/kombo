<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use App\Models\Platform\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Platform\Subscription\PlatformAudit;

/**
 * La puerta de la super administración.
 *
 * Guard aparte del de los negocios: estar dentro de un negocio no deja entrar
 * aquí, ni al revés. Y **sólo responde en `admin.dominio`** — la ruta ni
 * siquiera existe en el subdominio de un cliente.
 */
final class PlatformAuthController
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /*
         * Freno por correo Y por origen.
         *
         * Esta puerta abre la facturación de todos los clientes: cinco intentos
         * es de sobra para alguien que sabe su contraseña, y muy poco para
         * quien las está probando.
         */
        $clave = 'platform-login:'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($clave, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Espera un momento.',
            ]);
        }

        $user = PlatformUser::where('email', $data['email'])->first();

        /*
         * El hash se compara AUNQUE el usuario no exista.
         *
         * Si no, la respuesta tarda distinto según el correo exista o no, y esa
         * diferencia de milisegundos es suficiente para averiguar quiénes somos.
         */
        $ok = Hash::check($data['password'], $user?->password ?? '$2y$12$'.str_repeat('x', 53));

        if ($user === null || ! $ok || ! $user->is_active) {
            RateLimiter::hit($clave, 300);

            // Un solo mensaje para los tres fallos: no revelar cuál de las tres
            // cosas acertó quien lo intenta.
            throw ValidationException::withMessages([
                'email' => 'Ese correo y esa contraseña no entran.',
            ]);
        }

        RateLimiter::clear($clave);

        Auth::guard('platform')->login($user, remember: false);
        $request->session()->regenerate();

        $user->update(['last_login_at' => now()]);

        $this->audit->record('platform.login');

        return response()->json(['data' => self::asArray($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = Auth::guard('platform')->user();

        // Responde también SIN sesión: la pantalla de entrada necesita saber
        // que está en la super administración antes de que nadie entre.
        return response()->json([
            'data' => $user instanceof PlatformUser ? self::asArray($user) : null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function asArray(PlatformUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
