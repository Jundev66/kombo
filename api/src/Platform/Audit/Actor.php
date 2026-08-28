<?php

declare(strict_types=1);

namespace Platform\Audit;

/**
 * Quién hizo algo, cuando no coincide con quién autenticó la petición.
 *
 * Pasa constantemente en el local: la caja está autenticada con el token del
 * DISPOSITIVO, pero quien acaba de cobrar es Ana, que puso su PIN. La bitácora
 * tiene que decir «Ana», no «tablet del mostrador».
 *
 * Es un tipo propio y no dos argumentos sueltos porque el identificador y el
 * nombre no tienen sentido por separado: uno sin el otro da una bitácora que o
 * no se puede leer, o no se puede rastrear.
 */
final readonly class Actor
{
    public function __construct(
        public string $userId,
        public string $userName,
    ) {}
}
