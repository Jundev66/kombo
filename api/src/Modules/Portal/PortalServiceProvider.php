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
        $this->registrarFrenos();

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

    /**
     * Los frenos del portal.
     *
     * Son las ÚNICAS puertas del sistema sin sesión: cualquiera en internet
     * puede empujarlas, y sin límite un script llena la cocina de comandas
     * falsas en un minuto. Van por dirección de origen, que es lo único que
     * hay para distinguir a quien pide de quien abusa.
     *
     * ── Por qué son configurables ──────────────────────────────────────────
     *
     * En producción los valores son los de abajo y no se tocan. En desarrollo
     * hacen falta más altos, y no por comodidad: el navegador de las pruebas,
     * el bot y todo lo demás salen de la MISMA máquina, así que un límite por
     * dirección deja de medir a un cliente y pasa a medir al equipo entero. Con
     * los valores de producción, una suite que hace ocho pedidos seguidos falla
     * con «Too Many Attempts» en una prueba que no tiene nada que ver — y se
     * pierde una tarde buscándolo en el sitio equivocado.
     *
     * Se apoya en `demo_tools`, que es la misma bandera que ya decide qué es un
     * entorno de trabajo y qué es el servidor de un cliente.
     */
    private function registrarFrenos(): void
    {
        $suelto = config('kombo.demo_tools') === true;

        RateLimiter::for(
            'portal-pedidos',
            fn (Request $request): Limit => Limit::perMinute($suelto ? 200 : 8)->by($request->ip() ?? 'sin-ip'),
        );

        RateLimiter::for(
            'portal-seguimiento',
            fn (Request $request): Limit => Limit::perMinute($suelto ? 600 : 120)->by($request->ip() ?? 'sin-ip'),
        );

        // Más apretado que los pedidos: son archivos, y subir archivos sin
        // sesión es la puerta más cara de todas.
        RateLimiter::for(
            'portal-comprobantes',
            fn (Request $request): Limit => Limit::perMinute($suelto ? 100 : 5)->by($request->ip() ?? 'sin-ip'),
        );
    }
}
