<?php

declare(strict_types=1);

namespace Modules\Portal;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Portal\Interfaces\Console\CancelExpiredOrdersCommand;

/**
 * Todo el enganche del portal: su orden programada.
 *
 * La tarea vive aquí y no en `routes/console.php` por la misma razón que las
 * rutas viven en el manifiesto: borrar la carpeta del módulo tiene que
 * llevarse todo lo suyo, sin dejar una línea huérfana en un fichero común que
 * empiece a fallar.
 */
final class PortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([CancelExpiredOrdersCommand::class]);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            /*
             * Cada diez minutos, no cada minuto.
             *
             * La ventana de pago se mide en horas: afinar el cierre al minuto
             * no le sirve a nadie y despierta el proceso 1.440 veces al día en
             * una máquina que además está cocinando.
             */
            $this->app->make(Schedule::class)
                ->command('pedidos:cerrar-vencidos')
                ->everyTenMinutes()
                ->withoutOverlapping();
        });
    }
}
