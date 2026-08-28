<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Exceptions;

use Shared\Domain\Exceptions\UserError;

/**
 * Algo del pedido no cumple sus reglas.
 */
abstract class OrderException extends UserError {}
