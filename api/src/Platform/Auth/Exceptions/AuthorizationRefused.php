<?php

declare(strict_types=1);

namespace Platform\Auth\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The PIN does not authorise this action.
 *
 * One message for all three causes — unknown user, inactive user, wrong PIN.
 * Telling them apart is the hint that makes guessing four digits feasible.
 */
final class AuthorizationRefused extends AccessDeniedHttpException
{
    public function __construct()
    {
        parent::__construct('Esa autorización no es válida.');
    }
}
