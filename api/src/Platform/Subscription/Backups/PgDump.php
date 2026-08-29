<?php

declare(strict_types=1);

namespace Platform\Subscription\Backups;

use Symfony\Component\Process\Process;

/**
 * El volcado de verdad, con `pg_dump`.
 *
 * Tres decisiones que no son evidentes:
 *
 * 1. **Formato `custom` (`-Fc`)**, no SQL plano. Comprime solo, y sobre todo
 *    permite restaurar una tabla suelta con `pg_restore -t`. El día que alguien
 *    borra el catálogo de un negocio no hace falta volver atrás la base entera.
 *
 * 2. **Conecta como el DUEÑO**, no como `kombo_app`. `kombo_app` está sujeto a
 *    Row Level Security: un volcado hecho con él saldría con las tablas VACÍAS
 *    —sin error, sin aviso— porque sin `app.tenant_id` puesto las políticas no
 *    dejan ver una sola fila. Sería un archivo de respaldo perfectamente
 *    formado y perfectamente inútil.
 *
 * 3. **La contraseña va por `PGPASSWORD`**, no en la línea de comandos: los
 *    argumentos de un proceso los ve cualquiera con `ps`.
 */
final class PgDump implements DatabaseDump
{
    public function toFile(string $destino): ?string
    {
        /** @var array<string, mixed> $conexion */
        $conexion = config('database.connections.pgsql_owner');

        $proceso = new Process(
            [
                'pg_dump',
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--file='.$destino,
                '--host='.$conexion['host'],
                '--port='.$conexion['port'],
                '--username='.$conexion['username'],
                '--dbname='.$conexion['database'],
            ],
            env: ['PGPASSWORD' => (string) $conexion['password']],
            timeout: 900.0,
        );

        $proceso->run();

        if ($proceso->isSuccessful()) {
            return null;
        }

        // La salida de error de `pg_dump` es la parte útil: dice si es de
        // versión, de permisos o de conexión. Repetirla entera evita la
        // conversación de «falló el respaldo» / «¿qué dijo?» / «no sé».
        return trim($proceso->getErrorOutput()) ?: 'pg_dump terminó con código '.$proceso->getExitCode();
    }
}
