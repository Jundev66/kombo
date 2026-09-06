<?php

declare(strict_types=1);

namespace Platform\Subscription\Backups;

use Symfony\Component\Process\Process;

/**
 * The real dump, with `pg_dump`.
 *
 * Three non-obvious decisions: `custom` format (`-Fc`) so a single table can be
 * restored with `pg_restore -t`; connecting as the OWNER, because `kombo_app`
 * is subject to RLS and would produce a well-formed dump with every table
 * EMPTY; and the password via `PGPASSWORD`, since process arguments are visible
 * to anyone running `ps`.
 */
final class PgDump implements DatabaseDump
{
    public function toFile(string $destination): ?string
    {
        /** @var array<string, mixed> $conexion */
        $connection = config('database.connections.pgsql_owner');

        $process = new Process(
            [
                'pg_dump',
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--file='.$destination,
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--username='.$connection['username'],
                '--dbname='.$connection['database'],
            ],
            env: ['PGPASSWORD' => (string) $connection['password']],
            timeout: 900.0,
        );

        $process->run();

        if ($process->isSuccessful()) {
            return null;
        }

        // `pg_dump`'s stderr is the useful part: it says whether the problem is
        // version, permissions or connection. Repeating it whole avoids the
        // "the backup failed" / "what did it say?" / "no idea" conversation.
        return trim($process->getErrorOutput()) ?: 'pg_dump terminó con código '.$process->getExitCode();
    }
}
