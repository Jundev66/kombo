<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Customers\Interfaces\Http\Controllers\CustomerController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:customers'])
    ->group(function (): void {
        Route::get('/customers', [CustomerController::class, 'index'])
            ->middleware('permission:customers.view');

        Route::get('/customers/{id}', [CustomerController::class, 'show'])
            ->middleware('permission:customers.view');

        Route::patch('/customers/{id}', [CustomerController::class, 'update'])
            ->middleware('permission:customers.manage');
    });
