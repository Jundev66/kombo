<?php

declare(strict_types=1);

namespace Platform\Subscription;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Platform\Subscription\Backups\BackupCommand;
use Platform\Subscription\Backups\DatabaseDump;
use Platform\Subscription\Backups\PgDump;
use Platform\Subscription\Http\CleanDemoDataCommand;
use Platform\Subscription\Http\CreatePlatformAdminCommand;
use Platform\Subscription\Http\SweepSubscriptionsCommand;

/**
 * Billing, wired to the clock.
 *
 * Remembering the scheduler on deployment day is the classic failure: expiries
 * simply do not happen, and nobody notices until a customer is four months
 * behind and still working.
 */
final class SubscriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DatabaseDump::class, PgDump::class);

        $this->commands([
            SweepSubscriptionsCommand::class,
            CleanDemoDataCommand::class,
            BackupCommand::class,
            CreatePlatformAdminCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            /*
             * Once a day, at 3am server time: no food business is taking
             * payment then, so nobody meets a suspension mid-lunch.
             */
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('subscriptions:check')
                ->dailyAt('03:00')
                ->withoutOverlapping();

            /*
             * The backup at 3:40, after the sweep rather than alongside it:
             * `pg_dump` and the sweep competing for the same disk on a modest
             * machine makes both take twice as long.
             *
             * `runInBackground` is deliberately not set: if yesterday's backup
             * is still running, today's waits rather than piling on.
             */
            $schedule->command('backups:run')
                ->dailyAt('03:40')
                ->withoutOverlapping(120);
        });
    }
}
