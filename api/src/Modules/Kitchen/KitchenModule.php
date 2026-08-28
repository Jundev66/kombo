<?php

declare(strict_types=1);

namespace Modules\Kitchen;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * La pantalla de comandas.
 *
 * **No es de núcleo**, y eso es deliberado: hay negocios de comida sin cocina
 * separada —un puesto donde el que atiende es el que cocina, una cocina oculta
 * de una sola persona— y para ellos esta pantalla sólo sería una cosa más que
 * mirar. Se enciende cuando hace falta.
 */
final class KitchenModule extends ModuleManifest
{
    public function code(): string
    {
        return 'kitchen';
    }

    public function name(): string
    {
        return 'Cocina';
    }

    public function description(): string
    {
        return 'La pantalla donde la cocina ve lo que hay que hacer y marca lo que ya está listo.';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return ['orders'];
    }

    public function routes(): ?string
    {
        return __DIR__.'/Interfaces/Http/Routes/api.php';
    }

    public function migrations(): ?string
    {
        return __DIR__.'/Infrastructure/Migrations';
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return ['kitchen.view', 'kitchen.update'];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            /*
             * A partir de cuántos minutos una comanda «va tarde».
             *
             * Va en la configuración del negocio y viaja en la respuesta, no
             * fijo en la pantalla: una arepera y una pizzería no tienen la
             * misma idea de tarde, y un umbral prestado hace que el semáforo
             * esté siempre en rojo —o nunca—, que es lo mismo que no tenerlo.
             *
             * Se usa cuando el producto no dice cuánto tarda.
             */
            'stale_minutes' => Setting::int(15)->min(1)->max(120),
        ];
    }
}
