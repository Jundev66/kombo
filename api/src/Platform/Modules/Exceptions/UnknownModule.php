<?php

declare(strict_types=1);

namespace Platform\Modules\Exceptions;

use RuntimeException;

/**
 * A module was requested that does not exist in this version.
 *
 * A programming error, not a business one: a hand-typed module code, or a
 * directory deleted without removing its `config/modules.php` line.
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
