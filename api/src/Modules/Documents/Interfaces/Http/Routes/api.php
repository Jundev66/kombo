<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Documents\Interfaces\Http\Controllers\DeliveryNoteController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:documents'])
    ->group(function (): void {
        Route::get('/notes', [DeliveryNoteController::class, 'index'])
            ->middleware('permission:notes.reprint');

        Route::get('/notes/{id}', [DeliveryNoteController::class, 'show'])
            ->middleware('permission:notes.reprint');

        Route::post('/notes/{id}/reprint', [DeliveryNoteController::class, 'reprint'])
            ->middleware('permission:notes.reprint');

        // Voiding is not here: it is `POST /counter/sales/{orderId}/void`, because
        // voiding the paper is voiding the sale.
    });
