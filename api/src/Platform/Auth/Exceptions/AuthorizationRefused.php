<?php

declare(strict_types=1);

namespace Platform\Auth\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * El PIN no autoriza esta acción.
 *
 * **Un solo mensaje para los tres fallos posibles** —el usuario no existe,
 * está inactivo, o el PIN no es— y eso es deliberado: distinguirlos le diría a
 * quien lo intenta cuál de las tres cosas ya acertó, que es justo la pista que
 * convierte adivinar un PIN de cuatro dígitos en algo factible.
 */
final class AuthorizationRefused extends AccessDeniedHttpException
{
    public function __construct()
    {
        parent::__construct('Esa autorización no es válida.');
    }
}
