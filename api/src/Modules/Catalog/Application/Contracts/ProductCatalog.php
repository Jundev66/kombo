<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Contracts;

/**
 * The port this module publishes for the others.
 *
 * One of only two ways modules talk: a port plus a DTO for "I need to know
 * now", a domain event for "react to what happened". Nobody outside touches
 * `ProductModel`, and an architecture test verifies it.
 */
interface ProductCatalog
{
    public function find(string $productId): ?ProductSnapshot;

    /**
     * Several at once: a ten-line order cannot cost ten queries.
     *
     * @param  list<string>  $productIds
     * @return array<string, ProductSnapshot> indexed by id
     */
    public function findMany(array $productIds): array;
}
