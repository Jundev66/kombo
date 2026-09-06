<?php

declare(strict_types=1);

namespace Modules\Orders\Domain\Exceptions;

use Shared\Domain\Exceptions\UserError;

/**
 * Something about the order breaks its rules.
 */
abstract class OrderException extends UserError {}
