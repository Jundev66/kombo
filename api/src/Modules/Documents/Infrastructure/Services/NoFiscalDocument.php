<?php

declare(strict_types=1);

namespace Modules\Documents\Infrastructure\Services;

use Modules\Documents\Domain\Ports\FiscalDocument;

/**
 * The default implementation: no fiscal documents are issued.
 *
 * It exists so the till's flow can ask without branching. It answers no,
 * always, and the system carries on issuing its delivery note.
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
