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
 * First door: email and password, with a session cookie.
 *
 * The business never has to be asked for: the subdomain already said, and RLS
 * has already scoped the lookup, so the same email in two tenants signs into
 * the right one.
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
            // Without this the failure is a 500 saying "Session store not set", which
            // happens when the till calls /auth/login instead of /auth/device.
            return response()->json([
                'message' => 'Esta pantalla entra con el token del dispositivo, no con contraseña. Usa /auth/device.',
            ], 400);
        }

        $this->assertNotThrottled($request, $credentials['email']);

        $user = User::where('email', $credentials['email'])->first();

        // The hash is compared even when the user does not exist, against a dummy:
        // otherwise response time reveals which emails are registered.
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
            // Deliberately a different message: whoever gets here already proved they
            // know the password, so "wrong credentials" would send them hunting a
            // problem that does not exist.
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
