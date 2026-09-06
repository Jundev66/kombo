<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Orders\Interfaces\Http\Controllers\OrderController;
use Modules\Orders\Interfaces\Http\Controllers\ReceiptViewController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:orders'])
    ->group(function (): void {
        Route::get('/orders', [OrderController::class, 'index'])
            ->middleware('permission:orders.view');

        Route::get('/orders/{id}', [OrderController::class, 'show'])
            ->middleware('permission:orders.view');

        Route::post('/orders', [OrderController::class, 'store'])
            ->middleware('permission:orders.create');

        // Confirming and advancing share a route because they are the same gesture:
        // one step forward. The permission distinguishes who may take the first —
        // the one that sends it to the kitchen — from the rest.
        Route::post('/orders/{id}/advance', [OrderController::class, 'advance'])
            ->middleware('permission.any:orders.confirm,orders.advance');

        /*
         * `permission.any` on purpose: getting through means being able to
         * START the cancellation, not carry it out. `_request` reaches the use
         * case, where ActionAuthorizer asks for a supervisor's PIN.
         */
        Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])
            ->middleware('permission.any:orders.cancel,orders.cancel_request');

        Route::post('/orders/{id}/payments', [OrderController::class, 'pay'])
            ->middleware('permission:orders.create');

        // Taking a mobile payment as good by looking at the receipt. A decision
        // about money, so it has its own permission.
        Route::post('/orders/{id}/payments/{paymentId}/confirm', [OrderController::class, 'confirmPayment'])
            ->middleware('permission:payments.confirm');

        /*
         * The receipt photo, served by the controller rather than as a loose
         * file: it carries the payer's ID number and balance, so viewing it
         * requires the same permission as confirming it.
         */
        Route::get('/orders/{id}/payments/{paymentId}/receipt', ReceiptViewController::class)
            ->middleware('permission:payments.confirm');
    });
