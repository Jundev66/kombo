<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObjects;

use Modules\Catalog\Domain\Exceptions\InvalidProductName;

/**
 * El nombre de un producto.
 *
 * Se normalizan los espacios porque «Reina  Pepiada» y «Reina Pepiada» son el
 * mismo producto para cualquiera menos para una comparación de cadenas, y
 * acaban siendo dos filas en la carta.
 */
final readonly class ProductName
{
    private const MIN = 2;

    /** Lo que cabe en la comanda y en el botón de la caja sin partirse. */
    private const MAX = 120;

    private function __construct(public string $value) {}

    public static function of(string $raw): self
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $length = mb_strlen($clean);

        if ($length < self::MIN) {
            throw new InvalidProductName('El nombre del producto es muy corto.');
        }

        if ($length > self::MAX) {
            throw new InvalidProductName('El nombre del producto no puede pasar de '.self::MAX.' caracteres.');
        }

        return new self($clean);
    }

    public function equals(self $other): bool
    {
        return mb_strtolower($this->value) === mb_strtolower($other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
