<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Delivery\Interfaces\Http\Controllers\DeliveryZoneController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:delivery'])
    ->group(function (): void {
        // Verlas es parte de tomar un pedido: la caja necesita saber cuánto
        // cobrar por llevarlo. Cambiarlas es configurar el negocio.
        Route::get('/delivery/zones', [DeliveryZoneController::class, 'index']);

        Route::post('/delivery/zones', [DeliveryZoneController::class, 'store'])
            ->middleware('permission:delivery.manage');

        Route::patch('/delivery/zones/{id}', [DeliveryZoneController::class, 'update'])
            ->middleware('permission:delivery.manage');

        Route::delete('/delivery/zones/{id}', [DeliveryZoneController::class, 'destroy'])
            ->middleware('permission:delivery.manage');
    });
