<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObjects;

use Modules\Catalog\Domain\Exceptions\InvalidProductName;

/**
 * A product's name. Whitespace is normalised, or "Reina  Pepiada" and "Reina
 * Pepiada" end up as two rows on the menu.
 */
final readonly class ProductName
{
    private const MIN = 2;

    /** What fits on the ticket and on the till button without wrapping. */
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
