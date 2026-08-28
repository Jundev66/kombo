<?php

declare(strict_types=1);

namespace Modules\Channels\Infrastructure\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ¿De qué negocio es este webhook?
 *
 * Es la misma pregunta que responde el subdominio en el resto del sistema, pero
 * un mensaje de Meta no trae subdominio: llega a una dirección común con el
 * identificador del número dentro del cuerpo. Y hay que contestarla **antes**
 * de poder consultar nada del negocio, porque sin contexto RLS devuelve cero
 * filas — correctamente.
 *
 * Por eso lee `channel_routes`, que es tabla de plataforma: sólo dice de quién
 * es un número. Las credenciales están en otro sitio, con RLS, y se leen
 * después.
 *
 * **Con caché**, porque esto corre en cada mensaje que entra. Y con su
 * contrapartida: quien cambie una ruta tiene que llamar a `forget()`. Si no, el
 * síntoma engaña — los mensajes se aceptan y se procesan contra el negocio
 * equivocado, o contra ninguno.
 */
final class ChannelRouter
{
    private const TTL = 3600;

    /**
     * El «no lo conozco» se cachea sólo unos segundos.
     *
     * Es la diferencia entre frenar a quien insiste y romperle el alta a un
     * cliente nuevo: si el negocio conecta su canal justo después de que
     * alguien haya preguntado por ese número, cachear la ausencia una hora lo
     * deja **una hora sin recibir un solo mensaje**, sin ningún error a la
     * vista. Diez segundos frenan igual de bien a un script y no se notan.
     */
    private const TTL_AUSENCIA = 10;

    /** El negocio dueño de esa cuenta, o null si no la conoce nadie. */
    public function tenantFor(string $channel, string $externalId): ?string
    {
        $key = self::key($channel, $externalId);

        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $tenantId = (string) (DB::table('channel_routes')
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->where('is_active', true)
            ->value('tenant_id') ?? '');

        Cache::put($key, $tenantId, $tenantId === '' ? self::TTL_AUSENCIA : self::TTL);

        return $tenantId === '' ? null : $tenantId;
    }

    /**
     * Da de alta o actualiza la ruta de una cuenta.
     *
     * Escribe la tabla de plataforma y limpia la caché en el mismo gesto: son
     * dos cosas que no pueden quedar desparejas.
     */
    public function register(string $channel, string $externalId, string $tenantId): void
    {
        DB::table('channel_routes')->updateOrInsert(
            ['channel' => $channel, 'external_id' => $externalId],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->forget($channel, $externalId);
    }

    public function forget(string $channel, string $externalId): void
    {
        Cache::forget(self::key($channel, $externalId));
    }

    private static function key(string $channel, string $externalId): string
    {
        return "channel_route:{$channel}:{$externalId}";
    }
}
