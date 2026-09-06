<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Platform\Auth\Http\DeviceTokenController;
use Platform\Auth\Http\LoginController;
use Platform\Auth\Http\LogoutController;
use Platform\Auth\Http\PinLoginController;
use Platform\Auth\Http\StaffController;
use Platform\Auth\Http\TeamController;
use Platform\Capabilities\Http\MeController;
use Platform\Subscription\Http\MetricsController;
use Platform\Subscription\Http\PlanAdminController;
use Platform\Subscription\Http\PlatformAuthController;
use Platform\Subscription\Http\RequirePlatformUser;
use Platform\Subscription\Http\TenantAdminController;

/*
 * PLATFORM routes only.
 *
 * A module's routes are declared by its manifest and loaded by
 * PlatformServiceProvider under `module:{code}`. Adding a module does not touch
 * this file, which is what makes turning one on a row rather than a deployment.
 */

/*
 * `/me` deliberately goes WITHOUT `auth:sanctum`: the login screen needs the
 * tenant's name and logo before anyone signs in.
 */
Route::get('/me', MeController::class)->name('me');

// The three ways in. None requires already being inside.
Route::post('/auth/login', LoginController::class)->name('auth.login');
Route::post('/auth/device', DeviceTokenController::class)->name('auth.device');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', LogoutController::class)->name('auth.logout');

    // These two use the DEVICE token, which can do nothing else: list the names
    // and validate a PIN.
    Route::get('/auth/staff', StaffController::class)->name('auth.staff');
    Route::post('/auth/pin', PinLoginController::class)->name('auth.pin');
});

/*
 * ── Platform administration ──────────────────────────────────────────────
 *
 * Lives at `admin.domain` and only there: `domain()` makes these routes not
 * exist on a customer's subdomain, so one tenant's employee session can never
 * reach everybody else's billing. The `platform` guard is a second lock.
 */
Route::domain((string) config('kombo.admin_host'))->group(function (): void {
    Route::post('/platform/auth/login', [PlatformAuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('platform.login');

    // Answers without a session too: the entry screen needs to know where it is
    // before anyone signs in.
    Route::get('/platform/me', [PlatformAuthController::class, 'me'])->name('platform.me');

    Route::middleware(RequirePlatformUser::class)->group(function (): void {
        Route::post('/platform/auth/logout', [PlatformAuthController::class, 'logout']);

        Route::get('/platform/metrics', MetricsController::class);

        Route::get('/platform/tenants', [TenantAdminController::class, 'index']);
        Route::post('/platform/tenants', [TenantAdminController::class, 'store']);
        Route::get('/platform/tenants/{id}', [TenantAdminController::class, 'show']);

        Route::post('/platform/tenants/{id}/payments', [TenantAdminController::class, 'registerPayment']);
        Route::post('/platform/tenants/{id}/status', [TenantAdminController::class, 'changeStatus']);
        Route::post('/platform/tenants/{id}/plan', [TenantAdminController::class, 'changePlan']);

        // Support mode: READ-ONLY, and written down. Walking into a customer's
        // house leaving no trace is the thing you do not do.
        Route::get('/platform/tenants/{id}/support', [TenantAdminController::class, 'support']);

        Route::get('/platform/plans', [PlanAdminController::class, 'index']);
        Route::patch('/platform/plans/{code}', [PlanAdminController::class, 'update']);
    });
});

/*
 * ── The tenant's team ────────────────────────────────────────────────────
 *
 * Here rather than in a module because users and roles belong to the PLATFORM:
 * they exist before any module and cannot be turned off. A tenant with no team
 * is one nobody can get into.
 */
/*
 * No `prefix('api/v1')`: this file is already loaded under it. Module files
 * declare it because `loadRoutesFrom` does not apply the group — the asymmetry
 * that gets `api/v1/api/v1/team` written by mistake.
 */
Route::middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/team', [TeamController::class, 'index'])
            ->middleware('permission:users.manage');

        Route::post('/team', [TeamController::class, 'store'])
            ->middleware('permission:users.manage');

        Route::patch('/team/{id}', [TeamController::class, 'update'])
            ->middleware('permission:users.manage');

        // Deactivates rather than deletes: a deleted user takes with them who
        // confirmed that order.
        Route::delete('/team/{id}', [TeamController::class, 'destroy'])
            ->middleware('permission:users.manage');
    });
