<?php

declare(strict_types=1);

namespace Modules\Channels\Infrastructure\Services;

/**
 * La dirección pública del portal de un negocio.
 *
 * Vive aquí y no en `url()` por una razón concreta: los enlaces que manda el
 * bot se arman **fuera de una petición HTTP**. Un aviso de «tu pedido está
 * listo» sale de la cola, donde no hay `Host` del que deducir el negocio —
 * `url()` daría la dirección del último trabajo que corrió, que puede ser de
 * otro cliente.
 */
final class PortalLink
{
    public static function forTenant(string $slug, string $path = '/'): string
    {
        $template = (string) config('kombo.public_url', 'http://{slug}.localhost:8010');

        return rtrim(str_replace('{slug}', $slug, $template), '/').$path;
    }
}
