<?php

declare(strict_types=1);

namespace Modules\Kitchen;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * The ticket board.
 *
 * Not core: a stall where whoever serves is whoever cooks has no separate
 * kitchen, and for them this screen is one more thing to look at.
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
             * From how many minutes a ticket is "running late".
             *
             * In the tenant's settings and travelling in the response rather
             * than fixed in the screen: an arepera and a pizzeria do not share
             * an idea of late, and a borrowed threshold makes the traffic light
             * always red — or never.
             */
            'stale_minutes' => Setting::int(15)->min(1)->max(120),
        ];
    }
}
