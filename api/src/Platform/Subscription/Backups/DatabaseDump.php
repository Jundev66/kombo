<?php

declare(strict_types=1);

namespace Platform\Subscription\Backups;

/**
 * Volcar la base de datos a un archivo.
 *
 * Es una interfaz por una razón práctica y no por dogma: `pg_dump` es un
 * programa externo, y una prueba que dependa de él comprueba a la vez el
 * respaldo y la versión del cliente instalado en la imagen. Detrás de esto, la
 * prueba puede ejercitar lo que de verdad tiene lógica —el empaquetado de los
 * archivos, la subida, la rotación, la bitácora— sin montar un PostgreSQL.
 *
 * El volcado real sí se comprueba, pero a mano y contra un servidor de verdad:
 * está en la lista de `docs/respaldos.md`, que es donde se restaura.
 */
interface DatabaseDump
{
    /**
     * Deja el volcado en $destino.
     *
     * @return string|null El error, o null si salió bien. No lanza: quien
     *                     llama tiene que poder escribir el fallo en la
     *                     bitácora antes de rendirse.
     */
    public function toFile(string $destino): ?string;
}
