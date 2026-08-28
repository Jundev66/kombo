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
        // La API se consume desde el mismo origen (el navegador ya está en
        // `elsazon.localhost`), así que la sesión por cookie vale y no hace
        // falta pasear tokens por el panel ni por el portal.
        $middleware->statefulApi();

        // ANTEPUESTO, y antes de la autenticación a propósito: el usuario se
        // busca DENTRO del negocio ya resuelto. Así el mismo correo en dos
        // negocios entra al que corresponde al subdominio, sin preguntar.
        $middleware->prependToGroup('web', ResolveTenant::class);
        $middleware->prependToGroup('api', ResolveTenant::class);

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
         * Los errores de PERSONA se responden como 422 con forma de error de
         * validación.
         *
         * «El precio no puede ser negativo» o «tu plan llega hasta 60
         * productos» no son fallos del servidor: son cosas que le pasan a
         * alguien escribiendo en un formulario, y la pantalla tiene que poder
         * pintarlas junto al campo que las causó.
         *
         * Sin esto salían como 500 y «funcionaban» en desarrollo por
         * accidente, porque con APP_DEBUG Laravel incluye el mensaje en el
         * cuerpo. En producción el usuario veía «error del servidor» y nadie
         * entendía por qué.
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
