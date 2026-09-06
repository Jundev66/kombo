<?php

declare(strict_types=1);

namespace Modules\Portal;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Portal\Interfaces\Console\CancelExpiredOrdersCommand;

/**
 * The portal's entire hook-up: its scheduled job.
 *
 * The task lives here rather than in `routes/console.php` for the same reason
 * routes live in the manifest — deleting the module takes everything of its own
 * with it, leaving no orphaned line in a shared file.
 */
final class PortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([CancelExpiredOrdersCommand::class]);
    }

    public function boot(): void
    {
        $this->registerThrottles();

        $this->app->booted(function (): void {
            /*
             * Every ten minutes, not every minute: the payment window is
             * measured in hours, and waking the process 1,440 times a day on a
             * machine that is also cooking helps nobody.
             */
            $this->app->make(Schedule::class)
                ->command('orders:cancel-expired')
                ->everyTenMinutes()
                ->withoutOverlapping();
        });
    }

    /**
     * The portal's brakes.
     *
     * The only doors with no session, so they go by source address — the only
     * thing there is to tell whoever is ordering from whoever is abusing.
     *
     * Configurable because development needs higher values, and not for
     * convenience: the test browser and the bot come from the SAME machine, so
     * a per-address limit stops measuring one customer and starts measuring the
     * whole suite. It leans on `demo_tools`, the flag that already tells a
     * working environment from a customer's server.
     */
    private function registerThrottles(): void
    {
        $loose = config('kombo.demo_tools') === true;

        RateLimiter::for(
            'portal-pedidos',
            fn (Request $request): Limit => Limit::perMinute($loose ? 200 : 8)->by($request->ip() ?? 'sin-ip'),
        );

        RateLimiter::for(
            'portal-seguimiento',
            fn (Request $request): Limit => Limit::perMinute($loose ? 600 : 120)->by($request->ip() ?? 'sin-ip'),
        );

        // Tighter than orders: these are files, and uploading files with no
        // session is the most expensive door of all.
        RateLimiter::for(
            'portal-receipts',
            fn (Request $request): Limit => Limit::perMinute($loose ? 100 : 5)->by($request->ip() ?? 'sin-ip'),
        );
    }
}
