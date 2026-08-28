<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Contracts;

/**
 * Los agregados, para quien cobra.
 *
 * Segundo puerto que publica la carta. Existe por la misma razón que el de
 * productos: **los identificadores vienen del cliente, los precios NO.** Quien
 * arma un pedido manda qué modificadores eligió; cuánto cuesta cada uno se
 * resuelve aquí, contra la carta, siempre.
 *
 * Sin esto, un navegador manipulado podría mandar «extra queso, -5,00» y el
 * sistema lo cobraría.
 */
interface ModifierCatalog
{
    /**
     * @param  list<string>  $modifierIds
     * @return array<string, ModifierSnapshot> indexado por id
     */
    public function findMany(array $modifierIds): array;
}
