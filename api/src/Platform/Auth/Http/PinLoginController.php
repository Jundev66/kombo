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
 * Third door: who is at this screen right now.
 *
 * Returns a `station` token that does operate — in the real person's name, not
 * the device's. The rate limit matters: four digits is 10,000 combinations,
 * and five tries a minute turns a scripted sweep into more than a day.
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

        // Signing in again revokes the previous shift on this screen: two people at
        // once on the same till is not real, a stale live token is.
        $tokenName = "{$data['device']}·{$user->name}";
        $user->tokens()->where('name', $tokenName)->delete();

        $token = $user->createToken($tokenName, ['station']);

        $this->audit->record(
            action: 'auth.pin_login',
            entityType: 'device',
            after: ['device' => $data['device']],
            // In the PERSON's name, not the device token the request arrived with.
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
