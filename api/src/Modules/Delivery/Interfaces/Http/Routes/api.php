<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Delivery\Interfaces\Http\Controllers\DeliveryController;
use Modules\Delivery\Interfaces\Http\Controllers\DeliveryZoneController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:delivery'])
    ->group(function (): void {
        // Seeing them is part of taking an order: the till needs to know the
        // delivery fee. Changing them is configuring the tenant.
        Route::get('/delivery/zones', [DeliveryZoneController::class, 'index']);

        Route::post('/delivery/zones', [DeliveryZoneController::class, 'store'])
            ->middleware('permission:delivery.manage');

        Route::patch('/delivery/zones/{id}', [DeliveryZoneController::class, 'update'])
            ->middleware('permission:delivery.manage');

        Route::delete('/delivery/zones/{id}', [DeliveryZoneController::class, 'destroy'])
            ->middleware('permission:delivery.manage');

        /*
         * `delivery.view_own` rather than `delivery.manage`: a courier sees
         * their own and what is free, and does not touch zones or fees.
         */
        Route::get('/delivery/orders', [DeliveryController::class, 'index'])
            ->middleware('permission:delivery.view_own');

        Route::post('/delivery/orders/{id}/take', [DeliveryController::class, 'take'])
            ->middleware('permission:delivery.view_own');

        Route::post('/delivery/orders/{id}/release', [DeliveryController::class, 'release'])
            ->middleware('permission:delivery.view_own');

        Route::post('/delivery/orders/{id}/advance', [DeliveryController::class, 'advance'])
            ->middleware('permission:delivery.mark_delivered');
    });
