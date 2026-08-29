<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Interfaces\Http\Controllers\CategoryController;
use Modules\Catalog\Interfaces\Http\Controllers\ChangePriceController;
use Modules\Catalog\Interfaces\Http\Controllers\ModifierGroupController;
use Modules\Catalog\Interfaces\Http\Controllers\ProductController;
use Modules\Catalog\Interfaces\Http\Controllers\ProductPhotoController;

/*
 * Las rutas de la carta.
 *
 * Las declara el MANIFIESTO del módulo y las carga PlatformServiceProvider;
 * `routes/api.php` no sabe que existen. Se cargan siempre, y `module:catalog`
 * decide si responden — así, encender un módulo abre sus rutas en el instante
 * en que se escribe la fila, sin desplegar.
 *
 * Se declara el prefijo y el grupo `api` completos porque `loadRoutesFrom` no
 * aplica el grupo de `withRouting`.
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

        // Permiso APARTE: cambiar precios es la vía natural para regalar
        // mercancía, y no tiene por qué poder hacerlo quien arregla una
        // descripción.
        Route::post('/catalog/products/{id}/price', ChangePriceController::class)
            ->middleware('permission:catalog.change_price');

        // La foto va con `catalog.manage`, no con `change_price`: cambiar la
        // imagen de un plato no mueve dinero.
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
