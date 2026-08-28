<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Counter\Interfaces\Http\Controllers\SaleController;
use Modules\Counter\Interfaces\Http\Controllers\VoidSaleController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:counter'])
    ->group(function (): void {
        // Una sola llamada: lo que se llevó y cómo pagó. El servidor arma el
        // pedido, lo manda a la cocina, cobra y devuelve la nota.
        Route::post('/counter/sales', SaleController::class)
            ->middleware('permission:counter.sell');

        /*
         * `permission.any`: pasar por aquí es poder INICIAR la anulación.
         * Quien sólo tiene `counter.void_request` llega al controlador y allí
         * se le pide el PIN de alguien que sí pueda ejecutarla.
         */
        Route::post('/counter/sales/{orderId}/void', VoidSaleController::class)
            ->middleware('permission.any:counter.void,counter.void_request');
    });
