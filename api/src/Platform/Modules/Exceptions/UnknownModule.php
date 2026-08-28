<?php

declare(strict_types=1);

namespace Platform\Modules\Exceptions;

use RuntimeException;

/**
 * Se pidió un módulo que no existe en esta versión del sistema.
 *
 * Es un error de programación, no de negocio: significa que alguien escribió
 * un código de módulo a mano y se equivocó, o que se borró una carpeta sin
 * quitar su línea de `config/modules.php`.
 */
final class UnknownModule extends RuntimeException
{
    public function __construct(string $code)
    {
        parent::__construct(
            "No existe ningún módulo «{$code}». Los módulos de esta versión se ".
            'declaran en config/modules.php.'
        );
    }
}
