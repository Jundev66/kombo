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
 * El cobro, enganchado al reloj.
 *
 * Acordarse del planificador el día del despliegue es el fallo clásico: los
 * vencimientos sencillamente no pasan y nadie se entera hasta que un cliente
 * lleva cuatro meses sin pagar y sigue trabajando.
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
             * Una vez al día, temprano.
             *
             * A las 3 de la mañana en el huso del servidor: ningún negocio de
             * comida está cobrando a esa hora, así que nadie se encuentra la
             * suspensión en mitad de un almuerzo.
             */
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('suscripciones:revisar')
                ->dailyAt('03:00')
                ->withoutOverlapping();

            /*
             * El respaldo, a las 3:40.
             *
             * Después de la revisión y no a la vez: `pg_dump` y el barrido
             * compitiendo por el mismo disco en una máquina modesta hacen que
             * los dos tarden el doble. Y a esa hora no hay ningún negocio de
             * comida cobrando, así que el volcado no le quita entradas y
             * salidas a nadie.
             *
             * `runInBackground` NO se pone: si el respaldo de ayer todavía
             * está corriendo, el de hoy tiene que esperar, no arrancar encima.
             */
            $schedule->command('respaldos:hacer')
                ->dailyAt('03:40')
                ->withoutOverlapping(120);
        });
    }
}
