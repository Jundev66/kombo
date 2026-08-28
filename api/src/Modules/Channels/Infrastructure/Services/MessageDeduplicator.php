<?php

declare(strict_types=1);

namespace Modules\Channels\Infrastructure\Services;

use Illuminate\Support\Facades\Cache;

/**
 * ¿Este mensaje ya lo procesamos?
 *
 * Meta reintenta. Reintenta cuando el servidor tarda, cuando devuelve algo que
 * no es 200, y a veces sin razón aparente. Sin esto, un cliente que escribe una
 * vez recibe el menú tres veces.
 *
 * **`Cache::add()` y no `has()` seguido de `put()`.** La diferencia importa de
 * verdad: dos reintentos que llegan a la vez —a dos procesos de PHP distintos—
 * pasan los dos por el `has()` antes de que ninguno haya escrito, y los dos
 * siguen. `add()` es atómico en Redis: escribe **sólo** si la clave no existía,
 * y devuelve si lo consiguió.
 *
 * Se guarda 24 horas: más que cualquier ventana de reintentos de Meta, y poco
 * comparado con lo que ocupa una clave por mensaje.
 */
final class MessageDeduplicator
{
    private const TTL_SECONDS = 86_400;

    /** `true` si es la primera vez que se ve. */
    public function firstTime(string $tenantId, string $channel, string $externalId): bool
    {
        if ($externalId === '') {
            // Sin identificador no se puede deduplicar. Se deja pasar en vez de
            // descartar: perder un mensaje de un cliente es peor que
            // contestarle dos veces.
            return true;
        }

        return Cache::add("msg:{$tenantId}:{$channel}:{$externalId}", 1, self::TTL_SECONDS);
    }
}
