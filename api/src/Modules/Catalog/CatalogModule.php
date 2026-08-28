<?php

declare(strict_types=1);

namespace Modules\Catalog;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * La carta: qué vende este negocio y a cómo.
 *
 * Es de núcleo. Un negocio de comida sin carta no es nada, y absolutamente
 * todo lo demás —pedidos, cocina, caja, portal, bots— cuelga de aquí.
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

            // Cambiar precios va APARTE de gestionar el catálogo, y no es
            // burocracia: es la vía natural para regalar mercancía. Quien
            // arregla una descripción no tiene por qué poder bajar el precio
            // de la parrilla a un dólar.
            'catalog.change_price',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            // Si los precios de la carta ya llevan el impuesto incluido. En
            // comida rápida casi siempre sí: el cliente ve un número redondo
            // y paga ese número. El valor por defecto es el comportamiento de
            // hoy, así que nadie nota que la opción existe hasta que la
            // necesita.
            'prices_include_tax' => Setting::bool(true),

            // Cuántos productos mostrar por página en el panel. En una PC de
            // mostrador, pedir doscientos de golpe es medio segundo de espera
            // en cada pantallazo.
            'page_size' => Setting::int(50)->min(10)->max(200),
        ];
    }
}
