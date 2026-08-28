<?php

declare(strict_types=1);

namespace Modules\Counter;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * La caja del mostrador.
 *
 * **No es de núcleo**: hay negocios de comida que sólo venden por el portal o
 * por WhatsApp —una cocina oculta, un emprendimiento desde casa— y para ellos
 * esta pantalla no existe. Se enciende cuando hay mostrador.
 *
 * Hoy vende y cobra. **No abre turno, no cierra caja y no cuadra el efectivo**:
 * eso es otra fase, y meterlo ahora sería obligar a todo el mundo a hacer un
 * arqueo que la mayoría no pide.
 */
final class CounterModule extends ModuleManifest
{
    public function code(): string
    {
        return 'counter';
    }

    public function name(): string
    {
        return 'Caja';
    }

    public function description(): string
    {
        return 'Vender y cobrar en el local, y entregar la nota.';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        // Sin la nota no se puede cobrar en el mostrador: al cliente hay que
        // darle un papel.
        return ['orders', 'documents'];
    }

    public function routes(): ?string
    {
        return __DIR__.'/Interfaces/Http/Routes/api.php';
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return [
            'counter.sell',

            /*
             * Anular una venta y descontar son las dos vías naturales para
             * sacar mercancía o dinero sin que quede rastro de venta, así que
             * cada una va con su par `_request`: el mostrador lo inicia y el
             * encargado lo autoriza con su PIN.
             *
             * Anular la venta es lo mismo que anular su nota —una nota por
             * pedido, y no se puede reemitir—, y por eso hay un solo permiso
             * para las dos cosas en vez de dos que siempre se conceden juntos.
             */
            'counter.void',
            'counter.void_request',
            'counter.discount',
            'counter.discount_request',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            /*
             * Cómo se eligen los productos.
             *
             * En comida se toca la foto: son cuarenta productos y se buscan de
             * un vistazo. El buscador es para quien tenga trescientos. El
             * valor por defecto es el comportamiento de hoy.
             */
            'layout' => Setting::enum(['grid', 'search'], 'grid'),

            // Cómo se entrega, por defecto. En un puesto de comida rápida casi
            // todo es para llevar.
            'default_service_type' => Setting::enum(['takeaway', 'dine_in', 'delivery'], 'takeaway'),
        ];
    }
}
