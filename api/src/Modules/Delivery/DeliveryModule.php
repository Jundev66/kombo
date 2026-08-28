<?php

declare(strict_types=1);

namespace Modules\Delivery;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * El reparto a domicilio.
 *
 * Se apaga entero, y hay negocios de comida que lo tienen apagado: un puesto de
 * la calle, una arepera donde todo el mundo pasa a buscar. Para ellos la
 * opción «delivery» no aparece en ningún sitio —ni en el portal, ni en la
 * caja— en vez de aparecer y no funcionar.
 */
final class DeliveryModule extends ModuleManifest
{
    public function code(): string
    {
        return 'delivery';
    }

    public function name(): string
    {
        return 'Reparto';
    }

    public function description(): string
    {
        return 'Llevar el pedido a casa del cliente, por zonas y con su tarifa.';
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
            'delivery.manage',

            // El repartidor: sus entregas y nada más.
            'delivery.view_own',
            'delivery.mark_delivered',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            /*
             * Lo mínimo para que salga un viaje.
             *
             * Cero es «sin mínimo», que es el comportamiento de hoy. No es lo
             * mismo que dejarlo sin poner: un mínimo mal entendido deja al
             * cliente con el carrito lleno sin poder pedir y sin saber por qué,
             * así que el portal lo dice desde la primera pantalla.
             */
            'minimum_order_cents' => Setting::money(0),

            // Cuánto se tarda, cuando la zona no lo diga. Lo que se le promete
            // al cliente antes de que pida.
            'default_minutes' => Setting::int(45)->min(5),
        ];
    }
}
