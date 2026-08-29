<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Reports\Interfaces\Http\Controllers\ExportController;
use Modules\Reports\Interfaces\Http\Controllers\ReportsController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:reports'])
    ->group(function (): void {
        // Ver los totales va con su propio permiso: hay negocios donde el
        // encargado opera todo el día y el dueño prefiere que no los vea.
        Route::get('/reports/sales', ReportsController::class)
            ->middleware('permission:reports.view_sales');

        /*
         * Exportar. Es un GET a propósito: así sigue funcionando cuando el
         * negocio está suspendido, que es justo cuando más falta hace —sus
         * datos son suyos aunque nos deba tres meses—.
         */
        Route::get('/reports/export', ExportController::class)
            ->middleware('permission:reports.view_sales');
    });
