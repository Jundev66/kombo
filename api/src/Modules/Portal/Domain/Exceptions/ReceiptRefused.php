<?php

declare(strict_types=1);

namespace Modules\Portal\Domain\Exceptions;

use Shared\Domain\Exceptions\UserError;

/**
 * The receipt could not be accepted, and the customer deserves to know why.
 */
final class ReceiptRefused extends UserError
{
    public function field(): ?string
    {
        return 'receipt';
    }

    public static function orderNotWaiting(): self
    {
        return new self('Este pedido ya no está esperando el comprobante.');
    }

    public static function couldNotStore(): self
    {
        return new self('No se pudo guardar el comprobante. Inténtalo otra vez.');
    }
}
