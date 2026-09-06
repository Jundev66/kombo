<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only a platform administrator gets through.
 *
 * The `platform` guard is checked explicitly rather than bare `auth`: with the
 * default guard, any tenant employee's session would open every customer's
 * billing.
 */
final class RequirePlatformUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('platform')->user();

        if ($user === null || $user->is_active !== true) {
            return new JsonResponse(['message' => 'Aquí no entras.'], 401);
        }

        return $next($request);
    }
}
