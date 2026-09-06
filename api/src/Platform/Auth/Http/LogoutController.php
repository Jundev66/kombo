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
        // Audited first: afterwards there is no user left to take the name from.
        $this->audit->record('auth.logout');

        // Token (till or kitchen): only THIS screen's token is revoked. Closing the
        // till cannot sign the same person out of the kitchen. A cookie session
        // yields a `TransientToken`, which has no row to delete.
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Without this the session is closed but `/me` still reports someone inside,
        // because the guard memoised the user during this same request.
        Auth::forgetGuards();

        return response()->json(['ok' => true]);
    }
}
