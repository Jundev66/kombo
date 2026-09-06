<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Contracts;

/**
 * The add-ons, for whoever charges.
 *
 * Ids come from the client, prices do NOT: a tampered browser could otherwise
 * send "extra cheese, -5.00" and the system would charge it.
 */
interface ModifierCatalog
{
    /**
     * @param  list<string>  $modifierIds
     * @return array<string, ModifierSnapshot> indexed by id
     */
    public function findMany(array $modifierIds): array;
}
