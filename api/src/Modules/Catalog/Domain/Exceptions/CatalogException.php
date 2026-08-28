<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exceptions;

use Shared\Domain\Exceptions\UserError;

/**
 * Algo del catálogo no cumple sus reglas.
 *
 * Extiende `UserError` porque todas estas son cosas que le pasan a una persona
 * escribiendo en un formulario, no fallos del sistema: se renderizan como 422
 * con el nombre del campo, para que la pantalla las pinte donde tocan.
 */
abstract class CatalogException extends UserError {}
