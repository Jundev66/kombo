<?php

declare(strict_types=1);

namespace Modules\Customers;

use Platform\Modules\ModuleManifest;

/**
 * Quién compra.
 *
 * La ficha se llena sola: cada pedido con teléfono suma a su cuenta. No hay
 * que darle de alta a nadie, y eso es todo el punto — en un negocio de comida
 * nadie va a rellenar un formulario de cliente entre dos almuerzos.
 *
 * Se apaga entero, y para un puesto de la calle tiene sentido apagarlo: si
 * nadie deja su teléfono, la lista está vacía y sólo estorba.
 */
final class CustomersModule extends ModuleManifest
{
    public function code(): string
    {
        return 'customers';
    }

    public function name(): string
    {
        return 'Clientes';
    }

    public function description(): string
    {
        return 'Quién compra, cuánto lleva gastado y cuándo fue la última vez.';
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
        return [
            'customers.view',
            // Poner una nota: «no le pongan cebolla», «paga siempre en
            // efectivo». Es lo que hace que la ficha sirva para algo.
            'customers.manage',
        ];
    }
}
