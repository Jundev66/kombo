<?php

declare(strict_types=1);

namespace Platform\Modules\Middleware;

use Closure;
use Illuminate\Http\Request;
use Platform\Capabilities\CurrentCapabilities;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Requires the tenant to have this module enabled.
 *
 * Answers 404, not 403, and deliberately: a module a tenant does not have is a
 * fact about their contract, not their permissions. A 403 would say "this
 * exists but you may not", which invites insistence and leaks the feature list.
 * (`permission:` does answer 403 — there the owner decides, so say it plainly.)
 */
final class RequireModule
{
    public function __construct(private readonly CurrentCapabilities $capabilities) {}

    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        foreach ($modules as $module) {
            if (! $this->capabilities->get()->hasModule($module)) {
                throw new NotFoundHttpException;
            }
        }

        return $next($request);
    }
}
