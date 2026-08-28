<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Contracts;

/**
 * El puerto que este módulo PUBLICA para los demás.
 *
 * Es una de las dos únicas formas en que dos módulos se hablan:
 *
 *   - ¿Necesitas saber algo AHORA?     → un puerto como éste, y un DTO.
 *   - ¿Reaccionas a algo QUE YA PASÓ?  → un evento de dominio.
 *
 * `Orders`, `Counter` y el bot piden aquí. Ninguno toca `ProductModel` ni
 * conoce la entidad de dominio, y hay una prueba de arquitectura que lo
 * verifica.
 */
interface ProductCatalog
{
    public function find(string $productId): ?ProductSnapshot;

    /**
     * Varios de una vez.
     *
     * Existe porque un pedido de diez líneas no puede costar diez consultas.
     * En una máquina modesta, ese detalle es la diferencia entre cobrar en
     * medio segundo o en cinco.
     *
     * @param  list<string>  $productIds
     * @return array<string, ProductSnapshot> indexado por id
     */
    public function findMany(array $productIds): array;
}
