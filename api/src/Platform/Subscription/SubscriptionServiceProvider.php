<?php

declare(strict_types=1);

namespace Platform\Subscription;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Platform\Subscription\Http\CleanDemoDataCommand;
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
        $this->commands([SweepSubscriptionsCommand::class, CleanDemoDataCommand::class]);
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
            $this->app->make(Schedule::class)
                ->command('suscripciones:revisar')
                ->dailyAt('03:00')
                ->withoutOverlapping();
        });
    }
}
