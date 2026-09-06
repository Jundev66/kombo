<?php

declare(strict_types=1);

namespace Shared\Domain\Exceptions;

use RuntimeException;

/**
 * An error that happens to a PERSON, not to the system.
 *
 * "That price cannot be negative", "you already have a product with that name".
 * Rendered as 422 shaped like a validation error, so the screen paints them
 * next to the field that caused them.
 *
 * Without this type they came out as 500 and "worked" in development, because
 * APP_DEBUG puts the message in the body.
 */
abstract class UserError extends RuntimeException
{
    /** Which form field it points at, if it points at one. */
    public function field(): ?string
    {
        return null;
    }
}
