<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Portal\Interfaces\Http\Controllers\MenuController;
use Modules\Portal\Interfaces\Http\Controllers\PortalOrderController;
use Modules\Portal\Interfaces\Http\Controllers\ReceiptController;
use Modules\Portal\Interfaces\Http\Controllers\ShopController;

/*
 * The only routes in the system without a session: asking somebody in the
 * street for an account to buy an arepa is the fastest way to lose them.
 *
 * What they do carry is a brake. These are the only doors anybody on the
 * internet can push, and without a limit a script fills the kitchen with fake
 * tickets in a minute. The limits are named in `PortalServiceProvider`, where
 * what each is worth — and why development differs — is written down.
 */
Route::prefix('api/v1/portal')
    ->middleware(['api', 'module:portal'])
    ->group(function (): void {
        Route::get('/shop', ShopController::class);
        Route::get('/menu', MenuController::class);

        Route::post('/orders', [PortalOrderController::class, 'store'])
            ->middleware('throttle:portal-pedidos');

        // Reading is cheap, but the tracking screen polls every few seconds: the
        // limit is for whoever automates, not for whoever is waiting for food.
        Route::get('/orders/{token}', [PortalOrderController::class, 'show'])
            ->middleware('throttle:portal-seguimiento');

        // Uploading the mobile-payment photo. Tighter still: these are files.
        Route::post('/orders/{token}/receipt', ReceiptController::class)
            ->middleware('throttle:portal-receipts');
    });
