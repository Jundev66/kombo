<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Platform\Auth\Middleware\RequireAnyPermission;
use Platform\Auth\Middleware\RequirePermission;
use Platform\Modules\Middleware\RequireModule;
use Platform\Subscription\Http\EnsureTenantCanWrite;
use Platform\Tenancy\Middleware\ResolveTenant;
use Shared\Domain\Exceptions\UserError;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * There is a proxy in front, and it has to be believed.
         *
         * Without this, Laravel sees the proxy's IP on every request — and the
         * attempt limiters count per IP, so the first customer to get their
         * password wrong locks everyone else out. It also thinks the request
         * arrived over `http`, generating links that leave the browser with no
         * cookie under `SESSION_SECURE_COOKIE`.
         *
         * `at: '*'` rather than Cloudflare's ranges: php-fpm is only reachable
         * from nginx, so a hand-maintained list would add no security and would
         * fail silently the day the ranges change.
         */
        $middleware->trustProxies(at: '*');

        // The API is consumed from the same origin, so the session cookie is
        // enough and no tokens are carried around the dashboard or the portal.
        $middleware->statefulApi();

        // PREPENDED, and deliberately before authentication: the user is looked up
        // INSIDE the already-resolved tenant, so the same email in two tenants
        // signs into the one for the subdomain.
        $middleware->prependToGroup('web', ResolveTenant::class);
        $middleware->prependToGroup('api', ResolveTenant::class);

        /*
         * Suspension, in ONE place: on the whole group rather than an `if` per
         * controller. That is exactly where the previous project failed, with
         * the check wired into 2 of some 20 controllers.
         */
        $middleware->appendToGroup('api', EnsureTenantCanWrite::class);

        $middleware->alias([
            'module' => RequireModule::class,
            'permission' => RequirePermission::class,
            'permission.any' => RequireAnyPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * PEOPLE's errors are answered as 422, shaped like a validation error,
         * so the screen can paint them next to the field that caused them.
         *
         * Without this they came out as 500 and "worked" in development,
         * because APP_DEBUG puts the message in the body.
         */
        $exceptions->render(function (UserError $error, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            $field = $error->field();

            return response()->json([
                'message' => $error->getMessage(),
                'errors' => $field === null ? [] : [$field => [$error->getMessage()]],
            ], 422);
        });
    })->create();
