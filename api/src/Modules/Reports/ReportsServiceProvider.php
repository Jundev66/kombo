<?php

declare(strict_types=1);

namespace Modules\Reports;

use Illuminate\Support\ServiceProvider;

/**
 * Los reportes no enlazan puertos ni escuchan eventos: leen lo que ya está
 * escrito.
 *
 * Existe igualmente para que añadir un módulo siga siendo «una carpeta, un
 * proveedor y una línea», sin excepciones que haya que recordar.
 */
final class ReportsServiceProvider extends ServiceProvider
{
    //
}
