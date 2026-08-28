<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Platform\Auth\Http\DeviceTokenController;
use Platform\Auth\Http\LoginController;
use Platform\Auth\Http\LogoutController;
use Platform\Auth\Http\PinLoginController;
use Platform\Auth\Http\StaffController;
use Platform\Capabilities\Http\MeController;

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
