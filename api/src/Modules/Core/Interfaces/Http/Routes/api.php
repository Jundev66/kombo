<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Interfaces\Http\Controllers\BusinessHoursController;
use Modules\Core\Interfaces\Http\Controllers\ExchangeRateController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:core'])
    ->group(function (): void {
        /*
         * Anyone who operates can READ the rate — the till needs it — but only
         * whoever configures the tenant can CHANGE it.
         */
        Route::get('/exchange-rate', [ExchangeRateController::class, 'current']);
        Route::post('/exchange-rate', [ExchangeRateController::class, 'store'])
            ->middleware('permission:settings.manage');

        Route::get('/business-hours', [BusinessHoursController::class, 'index']);
        Route::put('/business-hours', [BusinessHoursController::class, 'update'])
            ->middleware('permission:settings.manage');
    });
