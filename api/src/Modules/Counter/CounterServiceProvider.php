<?php

declare(strict_types=1);

namespace Modules\Counter;

use Illuminate\Support\ServiceProvider;

/**
 * La caja no enlaza ningún puerto propio: orquesta los casos de uso de
 * `Orders` y `Documents`, que el contenedor ya sabe construir.
 *
 * Existe igualmente para que añadir la caja siga siendo «una carpeta, un
 * proveedor y una línea», sin excepciones que haya que recordar.
 */
final class CounterServiceProvider extends ServiceProvider
{
    //
}
