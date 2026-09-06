<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Counter\Interfaces\Http\Controllers\SaleController;
use Modules\Counter\Interfaces\Http\Controllers\VoidSaleController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:counter'])
    ->group(function (): void {
        // One call: what they took and how they paid. The server assembles the
        // order, sends it to the kitchen, charges and returns the note.
        Route::post('/counter/sales', SaleController::class)
            ->middleware('permission:counter.sell');

        /*
         * `permission.any`: getting through means being able to START the void.
         * `counter.void_request` reaches the controller and is asked there for
         * the PIN of someone who can carry it out.
         */
        Route::post('/counter/sales/{orderId}/void', VoidSaleController::class)
            ->middleware('permission.any:counter.void,counter.void_request');
    });
