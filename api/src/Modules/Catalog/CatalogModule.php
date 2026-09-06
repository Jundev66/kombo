<?php

declare(strict_types=1);

namespace Modules\Catalog;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * The menu: what this tenant sells and at what price.
 *
 * Core. Orders, kitchen, till, portal and bots all hang off it.
 */
final class CatalogModule extends ModuleManifest
{
    public function code(): string
    {
        return 'catalog';
    }

    public function name(): string
    {
        return 'Carta';
    }

    public function description(): string
    {
        return 'Lo que vendes: categorías, productos con su foto y su precio, y los agregados como «sin cebolla».';
    }

    public function isCore(): bool
    {
        return true;
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
        return [
            'catalog.view',
            'catalog.manage',

            // Separate from managing the catalog, and not bureaucracy: it is the
            // natural way to give merchandise away.
            'catalog.change_price',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            // Whether menu prices already include tax. In fast food they usually do.
            // The default is today's behaviour, so nobody notices until they need it.
            'prices_include_tax' => Setting::bool(true),

            // Products per page in the dashboard. On a counter PC, two hundred at
            // once is half a second of waiting on every screenful.
            'page_size' => Setting::int(50)->min(10)->max(200),
        ];
    }
}
