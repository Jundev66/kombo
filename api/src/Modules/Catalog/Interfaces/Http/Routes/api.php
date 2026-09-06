<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Interfaces\Http\Controllers\CategoryController;
use Modules\Catalog\Interfaces\Http\Controllers\ChangePriceController;
use Modules\Catalog\Interfaces\Http\Controllers\ModifierGroupController;
use Modules\Catalog\Interfaces\Http\Controllers\ProductController;
use Modules\Catalog\Interfaces\Http\Controllers\ProductPhotoController;

/*
 * The menu's routes.
 *
 * Declared by the module's manifest and loaded by PlatformServiceProvider;
 * `routes/api.php` does not know they exist. They are always loaded, and
 * `module:catalog` decides whether they answer.
 *
 * The prefix and the full `api` group are declared here because
 * `loadRoutesFrom` does not apply the group from `withRouting`.
 */
Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'module:catalog'])
    ->group(function (): void {
        Route::get('/catalog/products', [ProductController::class, 'index'])
            ->middleware('permission:catalog.view');

        Route::get('/catalog/products/{id}', [ProductController::class, 'show'])
            ->middleware('permission:catalog.view');

        Route::post('/catalog/products', [ProductController::class, 'store'])
            ->middleware('permission:catalog.manage');

        Route::patch('/catalog/products/{id}', [ProductController::class, 'update'])
            ->middleware('permission:catalog.manage');

        // A separate permission: changing prices is the natural way to give
        // merchandise away.
        Route::post('/catalog/products/{id}/price', ChangePriceController::class)
            ->middleware('permission:catalog.change_price');

        // The photo goes with `catalog.manage`, not `change_price`: changing an
        // image does not move money.
        Route::post('/catalog/products/{id}/photo', [ProductPhotoController::class, 'store'])
            ->middleware('permission:catalog.manage');

        Route::delete('/catalog/products/{id}/photo', [ProductPhotoController::class, 'destroy'])
            ->middleware('permission:catalog.manage');

        Route::get('/catalog/categories', [CategoryController::class, 'index'])
            ->middleware('permission:catalog.view');
        Route::post('/catalog/categories', [CategoryController::class, 'store'])
            ->middleware('permission:catalog.manage');
        Route::patch('/catalog/categories/{id}', [CategoryController::class, 'update'])
            ->middleware('permission:catalog.manage');
        Route::delete('/catalog/categories/{id}', [CategoryController::class, 'destroy'])
            ->middleware('permission:catalog.manage');

        Route::get('/catalog/modifier-groups', [ModifierGroupController::class, 'index'])
            ->middleware('permission:catalog.view');
        Route::post('/catalog/modifier-groups', [ModifierGroupController::class, 'store'])
            ->middleware('permission:catalog.manage');
        Route::delete('/catalog/modifier-groups/{id}', [ModifierGroupController::class, 'destroy'])
            ->middleware('permission:catalog.manage');
    });
