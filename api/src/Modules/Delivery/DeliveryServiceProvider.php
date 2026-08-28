<?php

declare(strict_types=1);

namespace Modules\Delivery;

use Illuminate\Support\ServiceProvider;

/**
 * El reparto no enlaza puertos propios todavía: hoy son zonas con su tarifa,
 * que los pedidos leen al calcular el total.
 *
 * Existe igualmente para que añadir un módulo siga siendo «una carpeta, un
 * proveedor y una línea», sin excepciones que haya que recordar.
 */
final class DeliveryServiceProvider extends ServiceProvider
{
    //
}
