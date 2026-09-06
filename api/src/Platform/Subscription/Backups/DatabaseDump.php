<?php

declare(strict_types=1);

namespace Platform\Subscription\Backups;

/**
 * Dumping the database to a file.
 *
 * An interface for a practical reason: `pg_dump` is an external program, and a
 * test that depends on it tests the installed client version as much as the
 * backup. Behind this, the tests exercise what has real logic — packing,
 * upload, rotation, audit — without standing up a PostgreSQL.
 *
 * The real dump is verified by hand against a real server; see
 * `docs/respaldos.md`.
 */
interface DatabaseDump
{
    /**
     * Leaves the dump at $destination.
     *
     * @return string|null The error, or null on success. It does not throw: the
     *                     caller has to log the failure before giving up.
     */
    public function toFile(string $destination): ?string;
}
