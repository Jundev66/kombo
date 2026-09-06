<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Reports\Interfaces\Http\Controllers\ExportController;
use Modules\Reports\Interfaces\Http\Controllers\ReportsController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:reports'])
    ->group(function (): void {
        // Seeing the totals has its own permission: in some tenants the manager
        // works all day and the owner would rather they did not.
        Route::get('/reports/sales', ReportsController::class)
            ->middleware('permission:reports.view_sales');

        /*
         * Exporting. A GET on purpose, so it keeps working while the tenant is
         * suspended — exactly when it is needed most.
         */
        Route::get('/reports/export', ExportController::class)
            ->middleware('permission:reports.view_sales');
    });
