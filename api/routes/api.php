<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Platform\Auth\Http\DeviceTokenController;
use Platform\Auth\Http\LoginController;
use Platform\Auth\Http\LogoutController;
use Platform\Auth\Http\PinLoginController;
use Platform\Auth\Http\StaffController;
use Platform\Capabilities\Http\MeController;
use Platform\Subscription\Http\MetricsController;
use Platform\Subscription\Http\PlanAdminController;
use Platform\Subscription\Http\PlatformAuthController;
use Platform\Subscription\Http\RequirePlatformUser;
use Platform\Subscription\Http\TenantAdminController;

/*
 * Rutas de PLATAFORMA solamente.
 *
 * Las rutas de un módulo NO se declaran aquí: las declara su manifiesto
 * (`routes()`) y las carga PlatformServiceProvider bajo el middleware
 * `module:{codigo}`. Añadir un módulo no toca este fichero, y eso es
 * deliberado: es lo que hace que encender la caja o los canales sea una fila
 * en `tenant_modules` y no un despliegue.
 */

/*
 * `/me` va SIN `auth:sanctum` a propósito: la pantalla de login necesita el
 * nombre y el logo del negocio antes de que nadie entre. Sin sesión devuelve
 * el negocio y cero permisos, que es justo lo que hace falta para pintarla.
 */
Route::get('/me', MeController::class)->name('me');

// Las tres puertas de entrada. Ninguna exige estar ya dentro.
Route::post('/auth/login', LoginController::class)->name('auth.login');
Route::post('/auth/device', DeviceTokenController::class)->name('auth.device');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', LogoutController::class)->name('auth.logout');

    // Estas dos se piden con el token del DISPOSITIVO, que no puede hacer nada
    // más: ver la lista de nombres y validar un PIN.
    Route::get('/auth/staff', StaffController::class)->name('auth.staff');
    Route::post('/auth/pin', PinLoginController::class)->name('auth.pin');
});

/*
 * ── La super administración ──────────────────────────────────────────────
 *
 * Vive en `admin.dominio` y **sólo ahí**: `domain()` hace que estas rutas ni
 * siquiera existan en el subdominio de un cliente. No es una comodidad de
 * organización — es lo que impide que la sesión de un empleado de un negocio
 * llegue a tocar la facturación de todos los demás.
 *
 * Entrar es una segunda cerradura: el guard `platform` es otro, con su propia
 * tabla de usuarios.
 */
Route::domain((string) config('kombo.admin_host'))->group(function (): void {
    Route::post('/platform/auth/login', [PlatformAuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('platform.login');

    // Responde también sin sesión: la pantalla de entrada necesita saber dónde
    // está antes de que nadie entre.
    Route::get('/platform/me', [PlatformAuthController::class, 'me'])->name('platform.me');

    Route::middleware(RequirePlatformUser::class)->group(function (): void {
        Route::post('/platform/auth/logout', [PlatformAuthController::class, 'logout']);

        Route::get('/platform/metrics', MetricsController::class);

        Route::get('/platform/tenants', [TenantAdminController::class, 'index']);
        Route::post('/platform/tenants', [TenantAdminController::class, 'store']);
        Route::get('/platform/tenants/{id}', [TenantAdminController::class, 'show']);

        Route::post('/platform/tenants/{id}/payments', [TenantAdminController::class, 'registerPayment']);
        Route::post('/platform/tenants/{id}/status', [TenantAdminController::class, 'changeStatus']);
        Route::post('/platform/tenants/{id}/plan', [TenantAdminController::class, 'changePlan']);

        // Modo soporte: SÓLO lectura, y queda escrito. Entrar en casa de un
        // cliente sin que quede rastro es lo que no se hace.
        Route::get('/platform/tenants/{id}/support', [TenantAdminController::class, 'support']);

        Route::get('/platform/plans', [PlanAdminController::class, 'index']);
        Route::patch('/platform/plans/{code}', [PlanAdminController::class, 'update']);
    });
});
