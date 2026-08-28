<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Orders\Interfaces\Http\Controllers\OrderController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:orders'])
    ->group(function (): void {
        Route::get('/orders', [OrderController::class, 'index'])
            ->middleware('permission:orders.view');

        Route::get('/orders/{id}', [OrderController::class, 'show'])
            ->middleware('permission:orders.view');

        Route::post('/orders', [OrderController::class, 'store'])
            ->middleware('permission:orders.create');

        // Confirmar y avanzar comparten ruta porque son el mismo gesto: mover
        // el pedido un paso. El permiso distingue quién puede dar el primero
        // —que es el que lo manda a la cocina— de quién puede dar los demás.
        Route::post('/orders/{id}/advance', [OrderController::class, 'advance'])
            ->middleware('permission.any:orders.confirm,orders.advance');

        /*
         * `permission.any` a propósito: pasar por aquí significa poder
         * INICIAR la cancelación, no ejecutarla. Quien sólo tiene el permiso
         * `_request` llega al caso de uso, y allí ActionAuthorizer le pide el
         * PIN de un supervisor.
         */
        Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])
            ->middleware('permission.any:orders.cancel,orders.cancel_request');

        Route::post('/orders/{id}/payments', [OrderController::class, 'pay'])
            ->middleware('permission:orders.create');

        // Dar por bueno un pago móvil mirando el comprobante. Es una decisión
        // sobre dinero, así que va con su propio permiso.
        Route::post('/orders/{id}/payments/{paymentId}/confirm', [OrderController::class, 'confirmPayment'])
            ->middleware('permission:payments.confirm');
    });
