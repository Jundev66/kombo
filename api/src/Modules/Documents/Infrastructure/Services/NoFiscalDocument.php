<?php

declare(strict_types=1);

namespace Modules\Documents\Infrastructure\Services;

use Modules\Documents\Domain\Ports\FiscalDocument;

/**
 * La implementación por defecto: **no se emiten documentos fiscales**.
 *
 * Existe para que el flujo de la caja pueda preguntar sin ramificarse en
 * `if (hay adaptador fiscal)`. Devuelve que no, siempre, y el sistema sigue
 * emitiendo su nota de entrega.
 *
 * Es también la respuesta honesta a «¿y si mañana me homologo?»: se escribe
 * otro adaptador, se enlaza en su sitio, y ni la caja ni los pedidos se
 * enteran.
 */
final class NoFiscalDocument implements FiscalDocument
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function issueFor(string $orderId): ?array
    {
        return null;
    }
}
