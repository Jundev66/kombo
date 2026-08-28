<?php

declare(strict_types=1);

namespace Shared\Domain\Exceptions;

use RuntimeException;

/**
 * Un error que le pasa a una PERSONA, no al sistema.
 *
 * «Ese precio no puede ser negativo», «ya tienes un producto con ese nombre».
 * Se renderizan como **422 con forma de error de validación**, para que la
 * pantalla los pinte junto al campo que los causó en vez de soltar un mensaje
 * suelto arriba.
 *
 * La distinción importa: sin este tipo, estos casos salían como 500 y
 * «funcionaban» en desarrollo por accidente, porque con APP_DEBUG Laravel
 * incluye el mensaje en el cuerpo. En producción el usuario veía «error del
 * servidor» y nadie entendía por qué.
 */
abstract class UserError extends RuntimeException
{
    /** A qué campo del formulario apunta, si apunta a alguno. */
    public function field(): ?string
    {
        return null;
    }
}
