<?php

declare(strict_types=1);

namespace Platform\Auth\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Platform\Audit\AuditLogger;

/**
 * La primera puerta: correo y contraseña, con cookie de sesión.
 *
 * Es la del panel y la del portal, donde hay un teclado y tiempo para
 * escribir. La caja y la cocina entran por otra (token de dispositivo + PIN).
 *
 * **No se pregunta a qué negocio.** El subdominio ya lo dijo, y el middleware
 * de negocio corrió antes que esto: `User::where('email', ...)` ya está
 * filtrado por RLS, así que el mismo correo en dos negocios entra al que
 * corresponde sin un campo extra en el formulario.
 */
final class LoginController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! $request->hasSession()) {
            // Sin esto el fallo es un 500 con «Session store not set», que no
            // dice nada. Pasa cuando la caja llama a /auth/login en vez de a
            // /auth/device.
            return response()->json([
                'message' => 'Esta pantalla entra con el token del dispositivo, no con contraseña. Usa /auth/device.',
            ], 400);
        }

        $this->assertNotThrottled($request, $credentials['email']);

        $user = User::where('email', $credentials['email'])->first();

        // Se compara el hash AUNQUE el usuario no exista, contra un dummy. Sin
        // esto, un correo que existe tarda notablemente más que uno que no, y
        // eso basta para averiguar quién trabaja aquí.
        $valid = $user !== null
            ? Hash::check($credentials['password'], $user->password)
            : Hash::check($credentials['password'], '$2y$12$'.str_repeat('a', 53));

        if ($user === null || ! $valid) {
            RateLimiter::hit($this->throttleKey($request, $credentials['email']));

            throw ValidationException::withMessages([
                'email' => 'Ese correo y esa contraseña no entran.',
            ]);
        }

        if (! $user->is_active) {
            // Mensaje DISTINTO a propósito: quien llega aquí ya demostró saber
            // la contraseña, así que no hay nada que proteger, y decirle
            // «credenciales incorrectas» lo mandaría a buscar un problema que
            // no existe.
            throw ValidationException::withMessages([
                'email' => 'Tu usuario está desactivado. Habla con el dueño del negocio.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request, $credentials['email']));

        Auth::guard('web')->login($user, remember: $request->boolean('remember'));
        $request->session()->regenerate();
        Auth::forgetGuards();

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record('auth.login');

        return response()->json(['ok' => true]);
    }

    private function assertNotThrottled(Request $request, string $email): void
    {
        $key = $this->throttleKey($request, $email);

        if (! RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => "Demasiados intentos. Espera {$seconds} segundos.",
        ]);
    }

    private function throttleKey(Request $request, string $email): string
    {
        return 'login:'.mb_strtolower($email).'|'.$request->ip();
    }
}
