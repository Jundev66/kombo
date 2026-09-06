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
 * Second door: registering a screen, once in its life.
 *
 * Returns a token whose only ability is `device` — list the staff names and
 * validate a PIN. Shop machines get lent, lost and sold; this one is worthless.
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

        // Registering the same screen again revokes the previous token, which is
        // what you want when the tablet was lost and you are setting up the new one.
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
