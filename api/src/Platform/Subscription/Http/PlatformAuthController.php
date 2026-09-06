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
 * The platform administration door.
 *
 * A separate guard from the tenants': being inside a tenant does not get you in
 * here, or the other way round. And it only answers on `admin.domain` — the
 * route does not exist on a customer's subdomain.
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
         * Throttled by email AND by origin. This door opens every customer's
         * billing: five attempts is plenty for someone who knows their
         * password, and very few for someone trying them.
         */
        $password = 'platform-login:'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($password, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Espera un momento.',
            ]);
        }

        $user = PlatformUser::where('email', $data['email'])->first();

        /*
         * The hash is compared even when the user does not exist, or response
         * time would reveal which addresses are registered.
         */
        $ok = Hash::check($data['password'], $user?->password ?? '$2y$12$'.str_repeat('x', 53));

        if ($user === null || ! $ok || ! $user->is_active) {
            RateLimiter::hit($password, 300);

            // One message for all three failures: never reveal which of the three the
            // caller got right.
            throw ValidationException::withMessages([
                'email' => 'Ese correo y esa contraseña no entran.',
            ]);
        }

        RateLimiter::clear($password);

        Auth::guard('platform')->login($user, remember: false);
        $request->session()->regenerate();

        $user->update(['last_login_at' => now()]);

        $this->audit->record('platform.login');

        return response()->json(['data' => self::asArray($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = Auth::guard('platform')->user();

        // Answers WITHOUT a session too: the entry screen needs to know it is on
        // platform administration before anyone signs in.
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
