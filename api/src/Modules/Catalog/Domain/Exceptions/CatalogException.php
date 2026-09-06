<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exceptions;

use Shared\Domain\Exceptions\UserError;

/**
 * Something in the catalog breaks its rules.
 *
 * Extends `UserError`: these happen to a person typing into a form, so they
 * render as 422 with the field name.
 */
abstract class CatalogException extends UserError {}
