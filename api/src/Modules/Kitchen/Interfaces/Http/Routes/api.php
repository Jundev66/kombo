<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Kitchen\Interfaces\Http\Controllers\KitchenController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:kitchen'])
    ->group(function (): void {
        Route::get('/kitchen/tickets', [KitchenController::class, 'index'])
            ->middleware('permission:kitchen.view');

        Route::post('/kitchen/tickets/{id}/advance', [KitchenController::class, 'advance'])
            ->middleware('permission:kitchen.update');
    });
