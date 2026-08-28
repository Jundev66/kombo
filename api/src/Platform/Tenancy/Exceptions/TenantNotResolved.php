<?php

declare(strict_types=1);

namespace Platform\Tenancy\Exceptions;

use RuntimeException;

/**
 * Se pidió el negocio en curso y no hay ninguno.
 *
 * Esto es un error de programación, no una situación de negocio: significa que
 * un trozo de código que asume contexto se ejecutó fuera de una petición de
 * negocio (una cola sin contexto, un comando de consola, el panel de
 * plataforma).
 *
 * Lanza en vez de devolver null a propósito. Un `?Tenant` obligaría a cada
 * llamador a decidir qué hacer cuando falta, y tarde o temprano alguien
 * decidiría «seguir sin filtrar».
 */
final class TenantNotResolved extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'No hay un negocio en contexto. Si esto corre en una cola o en un '.
            'comando, fija el negocio explícitamente antes de tocar datos.'
        );
    }
}
