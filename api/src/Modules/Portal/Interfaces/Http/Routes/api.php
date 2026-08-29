<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Portal\Interfaces\Http\Controllers\MenuController;
use Modules\Portal\Interfaces\Http\Controllers\PortalOrderController;
use Modules\Portal\Interfaces\Http\Controllers\ReceiptController;
use Modules\Portal\Interfaces\Http\Controllers\ShopController;

/*
 * Las únicas rutas del sistema SIN sesión.
 *
 * No llevan `auth:sanctum` a propósito: quien las usa es alguien de la calle
 * con un teléfono, y pedirle una cuenta para comprar una arepa es la forma más
 * rápida de que se vaya.
 *
 * Lo que sí llevan es freno. `throttle` en las de escritura, porque son las
 * únicas puertas del sistema que cualquiera en internet puede empujar: sin
 * límite, un script llena la cocina de comandas falsas en un minuto.
 *
 * Los límites se declaran con nombre en `PortalServiceProvider` y no como
 * números aquí: allí está escrito cuánto vale cada uno y, sobre todo, por qué
 * en desarrollo son otros.
 */
Route::prefix('api/v1/portal')
    ->middleware(['api', 'module:portal'])
    ->group(function (): void {
        Route::get('/shop', ShopController::class);
        Route::get('/menu', MenuController::class);

        Route::post('/orders', [PortalOrderController::class, 'store'])
            ->middleware('throttle:portal-pedidos');

        // Consultar sí es barato, pero la pantalla de seguimiento pregunta
        // cada pocos segundos: el límite está para el que automatiza, no para
        // el que espera su comida.
        Route::get('/orders/{token}', [PortalOrderController::class, 'show'])
            ->middleware('throttle:portal-seguimiento');

        // Subir la foto del pago móvil. Más apretado todavía: son archivos.
        Route::post('/orders/{token}/receipt', ReceiptController::class)
            ->middleware('throttle:portal-comprobantes');
    });
